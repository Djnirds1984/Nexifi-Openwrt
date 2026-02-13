#!/bin/sh

echo "Installing Pisowifi..."

# Install dependencies
echo "Updating package lists..."
opkg update

echo "Installing required packages (iptables, php, etc)..."
# Install iptables modules for NAT and Conntrack, and PHP packages
# iptables-mod-nat-extra is needed for REDIRECT target
# iptables-mod-conntrack-extra is needed for conntrack match
opkg install iptables-nft iptables-mod-nat-extra iptables-mod-conntrack-extra php8-cli php8-cgi
opkg install php8-mod-json || true # Optional or might be included

# Verify PHP installation
if [ ! -x /usr/bin/php-cli ]; then
    echo "Error: php-cli not found or not executable. Trying to find alternative..."
    if [ -x /usr/bin/php ]; then
        ln -s /usr/bin/php /usr/bin/php-cli
        echo "Linked /usr/bin/php to /usr/bin/php-cli"
    else
        echo "CRITICAL: PHP not found. Please install php8-cli manually."
    fi
fi

# Configure uhttpd to execute PHP
uci set uhttpd.main.index_page='index.php'
uci delete uhttpd.main.interpreter 2>/dev/null
uci add_list uhttpd.main.interpreter='.php=/usr/bin/php-cgi'
uci commit uhttpd
/etc/init.d/uhttpd restart

# Create directories
mkdir -p /etc/config
mkdir -p /etc/init.d
mkdir -p /usr/bin
mkdir -p /www/pisowifi
mkdir -p /usr/lib/lua/luci/controller
mkdir -p /usr/lib/lua/luci/view/pisowifi
mkdir -p /etc/uci-defaults

# 1. /etc/config/pisowifi
cat << 'EOF_CONFIG' > /etc/config/pisowifi
config pisowifi 'general'
    option coin_value '1'
    option time_per_coin '10' # minutes
    option portal_url 'http://10.0.0.1/pisowifi'
EOF_CONFIG

# 2. /etc/init.d/pisowifi
cat << 'EOF_INIT' > /etc/init.d/pisowifi
#!/bin/sh /etc/rc.common

START=99
STOP=10

SERVICE_USE_PID=1
SERVICE_WRITE_PID=1
SERVICE_DAEMONIZE=1

start() {
    # Create iptables chain for pisowifi if not exists
    iptables -N pisowifi_auth 2>/dev/null
    iptables -t nat -N pisowifi_portal 2>/dev/null

    # Insert chains into FORWARD and PREROUTING
    iptables -C FORWARD -j pisowifi_auth 2>/dev/null || iptables -I FORWARD -j pisowifi_auth
    iptables -t nat -C PREROUTING -j pisowifi_portal 2>/dev/null || iptables -t nat -I PREROUTING -j pisowifi_portal

    # Allow established connections (use conntrack instead of state)
    iptables -A pisowifi_auth -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT

    # Allow DNS
    iptables -A pisowifi_auth -p udp --dport 53 -j ACCEPT
    iptables -A pisowifi_auth -p tcp --dport 53 -j ACCEPT

    # Drop everything else by default (in pisowifi_auth chain, effectively blocking unauth users)
    # Actually, we should only block unauthenticated users. 
    # The authenticated users will be added to pisowifi_auth with ACCEPT rule by the PHP backend.
    
    # Redirect unauthenticated users to portal
    # This rule redirects HTTP traffic from unauthenticated users to the portal
    # We need a way to distinguish authenticated users. 
    # The PHP backend will add MAC addresses to pisowifi_auth with RETURN or ACCEPT.
    
    # Redirect all HTTP traffic to local portal (uhttpd on port 80)
    # But we need to exclude authenticated users.
    # The strategy:
    # 1. pisowifi_portal chain:
    #    - If MAC is authenticated, RETURN (continue to normal routing/masquerading)
    #    - Else, REDIRECT to port 80 (captive portal)
    
    # Clear old rules
    iptables -F pisowifi_auth
    iptables -t nat -F pisowifi_portal

    # Add default rules
    # Authenticated users will be inserted at the top of pisowifi_auth and pisowifi_portal by the backend.
    
    # Block unauthenticated users in FORWARD
    iptables -A pisowifi_auth -j DROP

    # Redirect unauthenticated users in PREROUTING (NAT)
    iptables -t nat -A pisowifi_portal -p tcp --dport 80 -j REDIRECT --to-ports 80

    # Start the backend monitor (PHP script)
    # This script will manage the expiration of users and coin counting
    service_start /usr/bin/php-cli /usr/bin/pisowifi-backend.php
}

stop() {
    # Remove rules
    iptables -D FORWARD -j pisowifi_auth 2>/dev/null
    iptables -t nat -D PREROUTING -j pisowifi_portal 2>/dev/null
    iptables -F pisowifi_auth
    iptables -t nat -F pisowifi_portal
    iptables -X pisowifi_auth
    iptables -t nat -X pisowifi_portal

    service_stop /usr/bin/php-cli /usr/bin/pisowifi-backend.php
}
EOF_INIT
chmod +x /etc/init.d/pisowifi

# 3. /usr/bin/pisowifi-backend.php
cat << 'EOF_BACKEND' > /usr/bin/pisowifi-backend.php
<?php
// backend.php - Pisowifi Logic for OpenWrt

$configFile = '/etc/config/pisowifi';
$usersFile = '/tmp/pisowifi_users.json';
$coinFile = '/tmp/pisowifi_coin'; // Simulate coin drop by writing to this file
$logFile = '/tmp/pisowifi.log';

function logMsg($msg) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

function getUsers() {
    global $usersFile;
    if (file_exists($usersFile)) {
        $content = file_get_contents($usersFile);
        return json_decode($content, true) ?: [];
    }
    return [];
}

function saveUsers($users) {
    global $usersFile;
    file_put_contents($usersFile, json_encode($users));
}

function addFirewallRule($mac) {
    // Add rule to allow traffic for this MAC
    $safe_mac = escapeshellarg($mac);
    exec("iptables -C pisowifi_auth -m mac --mac-source $safe_mac -j ACCEPT 2>/dev/null", $output, $ret);
    if ($ret !== 0) {
        exec("iptables -I pisowifi_auth 1 -m mac --mac-source $safe_mac -j ACCEPT");
        exec("iptables -t nat -I pisowifi_portal 1 -m mac --mac-source $safe_mac -j RETURN");
        logMsg("Added firewall rule for $mac");
    }
}

function removeFirewallRule($mac) {
    // Remove rule
    $safe_mac = escapeshellarg($mac);
    exec("iptables -D pisowifi_auth -m mac --mac-source $safe_mac -j ACCEPT 2>/dev/null");
    exec("iptables -t nat -D pisowifi_portal -m mac --mac-source $safe_mac -j RETURN 2>/dev/null");
    logMsg("Removed firewall rule for $mac");
}

function getUCIConfig($option, $default) {
    exec("uci get pisowifi.general.$option 2>/dev/null", $output, $ret);
    if ($ret === 0 && isset($output[0])) {
        return trim($output[0]);
    }
    return $default;
}

logMsg("Pisowifi backend started.");

while (true) {
    // 1. Check for new coins
    if (file_exists($coinFile)) {
        $coinData = file_get_contents($coinFile);
        unlink($coinFile); // Consume the coin event
        
        $data = json_decode($coinData, true);
        if ($data && isset($data['mac']) && isset($data['amount'])) {
            $mac = $data['mac'];
            $amount = $data['amount'];
            
            $users = getUsers();
            $now = time();
            
            if (!isset($users[$mac])) {
                $users[$mac] = ['expiry' => $now];
            }
            
            // Get config values
            $coinValue = getUCIConfig('coin_value', 1);
            $timePerCoin = getUCIConfig('time_per_coin', 10);
            
            // Calculate time to add
            $coins = $amount / $coinValue;
            $minutesToAdd = $coins * $timePerCoin; 
            
            if ($users[$mac]['expiry'] < $now) {
                $users[$mac]['expiry'] = $now + ($minutesToAdd * 60);
            } else {
                $users[$mac]['expiry'] += ($minutesToAdd * 60);
            }
            
            saveUsers($users);
            addFirewallRule($mac);
            logMsg("Added $minutesToAdd mins for $mac. New expiry: " . date('Y-m-d H:i:s', $users[$mac]['expiry']));
        }
    }
    
    // 2. Check for expired users
    $users = getUsers();
    $now = time();
    $changed = false;
    
    foreach ($users as $mac => $info) {
        if ($info['expiry'] < $now) {
            removeFirewallRule($mac);
            unset($users[$mac]);
            $changed = true;
            logMsg("User $mac expired.");
        } else {
            // Ensure rule exists (in case firewall restarted)
            addFirewallRule($mac);
        }
    }
    
    if ($changed) {
        saveUsers($users);
    }
    
    sleep(1);
}
?>
EOF_BACKEND
chmod +x /usr/bin/pisowifi-backend.php

# 4. /www/pisowifi/index.php
cat << 'EOF_INDEX' > /www/pisowifi/index.php
<?php
// Pisowifi Captive Portal

// Function to get MAC address from ARP table based on IP
function get_mac_address($ip) {
    $arp = file_get_contents('/proc/net/arp');
    $lines = explode("\n", $arp);
    foreach ($lines as $line) {
        $cols = preg_split('/\s+/', trim($line));
        if (isset($cols[0]) && $cols[0] == $ip) {
            return $cols[3];
        }
    }
    return null;
}

$user_ip = $_SERVER['REMOTE_ADDR'];
$user_mac = get_mac_address($user_ip);

// If MAC not found (e.g., testing locally), use a dummy
if (!$user_mac) {
    $user_mac = "00:00:00:00:00:00"; 
}

// Read status from backend file
$users_file = '/tmp/pisowifi_users.json';
$users = [];
if (file_exists($users_file)) {
    $users = json_decode(file_get_contents($users_file), true);
}

$is_authorized = false;
$time_remaining = 0;

if (isset($users[$user_mac])) {
    $expiry = $users[$user_mac]['expiry'];
    $now = time();
    if ($expiry > $now) {
        $is_authorized = true;
        $time_remaining = $expiry - $now;
    }
}

// Handle simulated coin drop (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simulate_coin'])) {
    $coin_file = '/tmp/pisowifi_coin';
    $data = [
        'mac' => $user_mac,
        'amount' => 1 // 1 peso
    ];
    file_put_contents($coin_file, json_encode($data));
    // Redirect to self to refresh
    header("Location: index.php");
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Pisowifi Portal</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 50px; }
        .status { margin: 20px; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        .connected { color: green; font-weight: bold; }
        .disconnected { color: red; font-weight: bold; }
        button { padding: 10px 20px; font-size: 16px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Welcome to Pisowifi</h1>
    <p>Your IP: <?php echo $user_ip; ?></p>
    <p>Your MAC: <?php echo $user_mac; ?></p>

    <div class="status">
        <?php if ($is_authorized): ?>
            <p class="connected">Status: CONNECTED</p>
            <p>Time Remaining: <?php echo gmdate("H:i:s", $time_remaining); ?></p>
        <?php else: ?>
            <p class="disconnected">Status: DISCONNECTED</p>
            <p>Please insert coin to connect.</p>
        <?php endif; ?>
    </div>

    <form method="post">
        <button type="submit" name="simulate_coin" value="1">Insert 1 Peso (Simulate)</button>
    </form>

    <script>
        // Auto-refresh every 5 seconds to update time
        setInterval(function() {
            location.reload();
        }, 5000);
    </script>
</body>
</html>
EOF_INDEX

# 5. /usr/lib/lua/luci/controller/pisowifi.lua
cat << 'EOF_CONTROLLER' > /usr/lib/lua/luci/controller/pisowifi.lua
module("luci.controller.pisowifi", package.seeall)

function index()
    entry({"admin", "services", "pisowifi"}, template("pisowifi/admin"), "Pisowifi Manager", 60).dependent = false
end
EOF_CONTROLLER

# 6. /www/pisowifi/admin.php
cat << 'EOF_ADMIN' > /www/pisowifi/admin.php
<?php
// admin.php - Pisowifi Administration

$configFile = 'pisowifi'; // UCI config name

// Helper to get config via UCI
function getConfig() {
    global $configFile;
    $output = [];
    exec("uci show $configFile", $output);
    $config = [];
    foreach ($output as $line) {
        if (preg_match("/$configFile\.general\.(\w+)='?(.*?)'?$/", $line, $matches)) {
            $config[$matches[1]] = $matches[2];
        }
    }
    return $config;
}

// Helper to update config via UCI
function updateConfig($key, $value) {
    global $configFile;
    // Sanitize input
    $key = escapeshellarg($key);
    $value = escapeshellarg($value);
    
    // Set value
    exec("uci set $configFile.general.$key=$value");
    exec("uci commit $configFile");
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['coin_value'])) {
        updateConfig('coin_value', $_POST['coin_value']);
    }
    if (isset($_POST['time_per_coin'])) {
        updateConfig('time_per_coin', $_POST['time_per_coin']);
    }
    $message = "Configuration saved.";
}

$config = getConfig();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Pisowifi Admin</title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input { padding: 8px; width: 100%; max-width: 300px; }
        button { padding: 10px 20px; background-color: #007bff; color: white; border: none; cursor: pointer; }
        button:hover { background-color: #0056b3; }
        .message { color: green; margin-bottom: 20px; padding: 10px; background-color: #d4edda; border: 1px solid #c3e6cb; }
        a { text-decoration: none; color: #007bff; }
    </style>
</head>
<body>
    <h1>Pisowifi Administration</h1>
    
    <?php if ($message): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <form method="post">
        <div class="form-group">
            <label for="coin_value">Coin Value (PHP):</label>
            <input type="number" id="coin_value" name="coin_value" value="<?php echo isset($config['coin_value']) ? htmlspecialchars($config['coin_value']) : '1'; ?>" required>
        </div>
        
        <div class="form-group">
            <label for="time_per_coin">Time per Coin (Minutes):</label>
            <input type="number" id="time_per_coin" name="time_per_coin" value="<?php echo isset($config['time_per_coin']) ? htmlspecialchars($config['time_per_coin']) : '10'; ?>" required>
        </div>
        
        <button type="submit">Save Settings</button>
    </form>
    
    <p><a href="index.php">Back to Portal</a></p>
</body>
</html>
EOF_ADMIN

# 7. /usr/lib/lua/luci/view/pisowifi/admin.htm
cat << 'EOF_VIEW' > /usr/lib/lua/luci/view/pisowifi/admin.htm
<%+header%>
<h2>Pisowifi Management</h2>
<p>
    The Pisowifi system is managed via a separate PHP interface.
</p>
<p>
    <a href="/pisowifi/admin.php" target="_blank" class="cbi-button cbi-button-apply">Open Pisowifi Admin Panel</a>
</p>
<%+footer%>
EOF_VIEW

# 8. /etc/uci-defaults/99-pisowifi-setup
cat << 'EOF_SETUP' > /etc/uci-defaults/99-pisowifi-setup
#!/bin/sh

# Set up uhttpd to prefer index.php or handle redirection
# We will replace /www/index.html with a smart PHP redirector if it exists

if [ -f /www/index.html ]; then
    mv /www/index.html /www/index.html.bak
fi

cat << 'EOF_INDEX_PHP' > /www/index.php
<?php
\$host = \$_SERVER['HTTP_HOST'];
// Adjust these IPs to match your router's LAN IP
\$router_ips = ['192.168.1.1', '10.0.0.1', 'openwrt.lan'];

// Check if the request is for the router admin interface
\$is_admin = false;
foreach (\$router_ips as \$ip) {
    if (strpos(\$host, \$ip) !== false) {
        \$is_admin = true;
        break;
    }
}

if (!\$is_admin) {
    // Captive portal user
    header("Location: /pisowifi/");
    exit;
}

// Admin user - redirect to LuCI
header("Location: /cgi-bin/luci");
exit;
?>
EOF_INDEX_PHP

# Ensure permissions
chmod +x /etc/init.d/pisowifi
chmod +x /usr/bin/pisowifi-backend.php

# Enable and start service
/etc/init.d/pisowifi enable
/etc/init.d/pisowifi start

exit 0
EOF_SETUP
chmod +x /etc/uci-defaults/99-pisowifi-setup

echo "Installation complete. Running setup script..."
/etc/uci-defaults/99-pisowifi-setup
echo "Done!"
