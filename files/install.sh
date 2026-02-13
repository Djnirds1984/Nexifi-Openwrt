#!/bin/sh

echo "Installing Pisowifi..."

# Install dependencies
echo "Updating package lists..."
opkg update

echo "Installing required packages (iptables, php, etc)..."
# Install iptables modules for NAT and Conntrack, and PHP packages
# iptables-mod-nat-extra is needed for REDIRECT target
# iptables-mod-conntrack-extra is needed for conntrack match
# php8-mod-session is needed for admin login (session_start)
opkg install iptables-nft iptables-mod-nat-extra iptables-mod-conntrack-extra php8-cli php8-cgi php8-mod-session
# opkg install php8-mod-json || true # Optional or might be included

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

# Configure WiFi
# Set SSID to 'Pisowifi' and open encryption
# Note: We loop through available radios (radio0, radio1, etc.)
# We need to be careful with existing config.
# A safer approach is to add a new interface if we want, or modify default.
# Here we try to modify the default interface usually named 'default_radio0', 'default_radio1'
# If not found, we just enable the radio and set ssid on the first interface found.

radios=$(uci show wireless | grep "=wifi-device" | cut -d. -f2 | cut -d= -f1)

for radio in $radios; do
    # Enable radio
    uci set wireless.$radio.disabled='0'
    
    # Find the first interface on this radio (usually default_$radio)
    iface=$(uci show wireless | grep ".device='$radio'" | head -n 1 | cut -d. -f2)
    
    if [ -z "$iface" ]; then
        # Create new iface if none exists
        iface="default_$radio"
        uci set wireless.$iface=wifi-iface
        uci set wireless.$iface.device=$radio
        uci set wireless.$iface.network=lan
        uci set wireless.$iface.mode=ap
    fi
    
    # Configure SSID and Encryption
    uci set wireless.$iface.ssid='Pisowifi'
    uci set wireless.$iface.encryption='none'
done

uci commit wireless
wifi reload

# Configure uhttpd to execute PHP
uci set uhttpd.main.index_page='index.php'
# Ensure PHP interpreter is set (remove if exists then add to avoid duplicates)
# We need to make sure we don't break existing lua config
uci -q del_list uhttpd.main.interpreter='.php=/usr/bin/php-cgi'
uci add_list uhttpd.main.interpreter='.php=/usr/bin/php-cgi'
# Also ensure .html is handled or at least index.php is prioritized
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
$kickFile = '/tmp/pisowifi_kick'; // File to request user disconnection
$salesFile = '/etc/pisowifi_sales.csv'; // Persistent sales log
$logFile = '/tmp/pisowifi.log';

function logMsg($msg) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

function logSale($mac, $amount, $minutes) {
    global $salesFile;
    $date = date('Y-m-d H:i:s');
    $line = "$date,$mac,$amount,$minutes\n";
    file_put_contents($salesFile, $line, FILE_APPEND);
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
    // 0. Check for kick requests
    if (file_exists($kickFile)) {
        $kickMac = trim(file_get_contents($kickFile));
        unlink($kickFile);
        if ($kickMac) {
            $users = getUsers();
            if (isset($users[$kickMac])) {
                removeFirewallRule($kickMac);
                unset($users[$kickMac]);
                saveUsers($users);
                logMsg("Kicked user $kickMac");
            }
        }
    }

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
            logSale($mac, $amount, $minutesToAdd);
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

# 6. Admin Panel (Multi-file)
mkdir -p /www/pisowifi/admin
mkdir -p /www/pisowifi/assets

# Create Style
cat << 'EOF_CSS' > /www/pisowifi/assets/style.css
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; margin: 0; display: flex; height: 100vh; }
.sidebar { width: 250px; background: #343a40; color: #fff; display: flex; flex-direction: column; }
.sidebar-header { padding: 20px; text-align: center; font-size: 1.5em; font-weight: bold; background: #212529; }
.nav-links { list-style: none; padding: 0; margin: 0; flex: 1; }
.nav-links li a { display: block; padding: 15px 20px; color: #c2c7d0; text-decoration: none; border-bottom: 1px solid #4b545c; }
.nav-links li a:hover, .nav-links li a.active { background: #007bff; color: white; }
.main-content { flex: 1; padding: 20px; overflow-y: auto; }
.card { background: white; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
.card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; color: #333; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
.stat-box { background: #007bff; color: white; padding: 20px; border-radius: 5px; text-align: center; }
.stat-box.green { background: #28a745; }
.stat-box.orange { background: #fd7e14; }
.stat-number { font-size: 2em; font-weight: bold; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
th { background-color: #f8f9fa; }
.btn { padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer; color: white; text-decoration: none; display: inline-block; }
.btn-danger { background-color: #dc3545; }
.btn-primary { background-color: #007bff; }
input[type="text"], input[type="number"], input[type="password"] { width: 100%; padding: 10px; margin: 5px 0 15px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
.alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
.alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
.alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
EOF_CSS

# Create Admin Header
cat << 'EOF_HEADER' > /www/pisowifi/admin/header.php
<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}
$page = basename($_SERVER['PHP_SELF'], ".php");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Pisowifi Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">Pisowifi Panel</div>
        <ul class="nav-links">
            <li><a href="index.php" class="<?php echo $page == 'index' ? 'active' : ''; ?>">Dashboard</a></li>
            <li><a href="users.php" class="<?php echo $page == 'users' ? 'active' : ''; ?>">Users</a></li>
            <li><a href="sales.php" class="<?php echo $page == 'sales' ? 'active' : ''; ?>">Sales</a></li>
            <li><a href="settings.php" class="<?php echo $page == 'settings' ? 'active' : ''; ?>">Settings</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </div>
    <div class="main-content">
EOF_HEADER

# Create Admin Footer
cat << 'EOF_FOOTER' > /www/pisowifi/admin/footer.php
    </div>
</body>
</html>
EOF_FOOTER

# Create Login
cat << 'EOF_LOGIN' > /www/pisowifi/admin/login.php
<?php
session_start();
if (isset($_SESSION['loggedin'])) {
    header('Location: index.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user = $_POST['username'];
    $pass = $_POST['password'];
    // Default credentials: admin / admin
    if ($user === 'admin' && $pass === 'admin') {
        $_SESSION['loggedin'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid credentials';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Pisowifi</title>
    <style>
        body { font-family: sans-serif; background: #e9ecef; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: white; padding: 40px; border-radius: 5px; box-shadow: 0 0 15px rgba(0,0,0,0.1); width: 300px; }
        h2 { text-align: center; margin-top: 0; }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .error { color: red; text-align: center; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Admin Login</h2>
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <form method="post">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
EOF_LOGIN

# Create Logout
cat << 'EOF_LOGOUT' > /www/pisowifi/admin/logout.php
<?php
session_start();
session_destroy();
header('Location: login.php');
?>
EOF_LOGOUT

# Create Dashboard
cat << 'EOF_DASHBOARD' > /www/pisowifi/admin/index.php
<?php include 'header.php'; ?>
<?php
$users_file = '/tmp/pisowifi_users.json';
$active_users = 0;
if (file_exists($users_file)) {
    $users = json_decode(file_get_contents($users_file), true);
    $active_users = count($users);
}

// Calculate sales today
$sales_file = '/etc/pisowifi_sales.csv';
$sales_today = 0;
$today = date('Y-m-d');
if (file_exists($sales_file)) {
    $lines = file($sales_file);
    foreach ($lines as $line) {
        $parts = explode(',', $line);
        if (count($parts) >= 3 && strpos($parts[0], $today) === 0) {
            $sales_today += floatval($parts[2]);
        }
    }
}
?>

<h2>Dashboard</h2>
<div class="stats-grid">
    <div class="stat-box">
        <div class="stat-number"><?php echo $active_users; ?></div>
        <div>Active Users</div>
    </div>
    <div class="stat-box green">
        <div class="stat-number">₱<?php echo number_format($sales_today, 2); ?></div>
        <div>Sales Today</div>
    </div>
    <div class="stat-box orange">
        <div class="stat-number">OK</div>
        <div>System Status</div>
    </div>
</div>

<div class="card" style="margin-top: 20px;">
    <h3>Quick Actions</h3>
    <p>Use the sidebar to manage users, view sales logs, or change settings.</p>
</div>

<?php include 'footer.php'; ?>
EOF_DASHBOARD

# Create Users Page
cat << 'EOF_USERS' > /www/pisowifi/admin/users.php
<?php include 'header.php'; ?>
<?php
if (isset($_POST['kick'])) {
    $kick_mac = $_POST['kick'];
    file_put_contents('/tmp/pisowifi_kick', $kick_mac);
    // Wait a bit for backend to process
    sleep(1);
}

$users_file = '/tmp/pisowifi_users.json';
$users = [];
if (file_exists($users_file)) {
    $users = json_decode(file_get_contents($users_file), true);
}
?>

<div class="card">
    <h3>Active Users</h3>
    <table>
        <thead>
            <tr>
                <th>MAC Address</th>
                <th>Expires At</th>
                <th>Time Remaining</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="4" style="text-align:center;">No active users</td></tr>
            <?php else: ?>
                <?php foreach ($users as $mac => $info): ?>
                <tr>
                    <td><?php echo $mac; ?></td>
                    <td><?php echo date('Y-m-d H:i:s', $info['expiry']); ?></td>
                    <td><?php echo gmdate("H:i:s", max(0, $info['expiry'] - time())); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="kick" value="<?php echo $mac; ?>">
                            <button type="submit" class="btn btn-danger">Disconnect</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'footer.php'; ?>
EOF_USERS

# Create Sales Page
cat << 'EOF_SALES' > /www/pisowifi/admin/sales.php
<?php include 'header.php'; ?>
<?php
$sales_file = '/etc/pisowifi_sales.csv';
$sales = [];
if (file_exists($sales_file)) {
    $lines = array_reverse(file($sales_file)); // Newest first
    foreach ($lines as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) >= 4) {
            $sales[] = [
                'date' => $parts[0],
                'mac' => $parts[1],
                'amount' => $parts[2],
                'minutes' => $parts[3]
            ];
        }
    }
}
// Simple pagination or limit
$sales = array_slice($sales, 0, 50);
?>

<div class="card">
    <h3>Sales Log (Last 50)</h3>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>MAC Address</th>
                <th>Amount</th>
                <th>Minutes Added</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sales)): ?>
                <tr><td colspan="4" style="text-align:center;">No sales recorded yet</td></tr>
            <?php else: ?>
                <?php foreach ($sales as $sale): ?>
                <tr>
                    <td><?php echo $sale['date']; ?></td>
                    <td><?php echo $sale['mac']; ?></td>
                    <td>₱<?php echo $sale['amount']; ?></td>
                    <td><?php echo $sale['minutes']; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'footer.php'; ?>
EOF_SALES

# Create Settings Page
cat << 'EOF_SETTINGS' > /www/pisowifi/admin/settings.php
<?php include 'header.php'; ?>
<?php
$configFile = 'pisowifi';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $coin_val = escapeshellarg($_POST['coin_value']);
    $time_val = escapeshellarg($_POST['time_per_coin']);
    
    exec("uci set $configFile.general.coin_value=$coin_val");
    exec("uci set $configFile.general.time_per_coin=$time_val");
    exec("uci commit $configFile");
    $msg = "Settings saved successfully.";
}

// Read current config
exec("uci get $configFile.general.coin_value 2>/dev/null", $o1);
exec("uci get $configFile.general.time_per_coin 2>/dev/null", $o2);
$current_coin = isset($o1[0]) ? $o1[0] : '1';
$current_time = isset($o2[0]) ? $o2[0] : '10';
?>

<div class="card">
    <h3>System Settings</h3>
    <?php if ($msg): ?>
        <div class="alert alert-success"><?php echo $msg; ?></div>
    <?php endif; ?>
    
    <form method="post">
        <label>Coin Value (PHP)</label>
        <input type="number" name="coin_value" value="<?php echo $current_coin; ?>" required>
        
        <label>Time per Coin (Minutes)</label>
        <input type="number" name="time_per_coin" value="<?php echo $current_time; ?>" required>
        
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
</div>

<?php include 'footer.php'; ?>
EOF_SETTINGS

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
$host = $_SERVER['HTTP_HOST'];
// Adjust these IPs to match your router's LAN IP
$router_ips = ['192.168.1.1', '10.0.0.1', 'openwrt.lan'];

// Check if the request is for the router admin interface
$is_admin = false;
foreach ($router_ips as $ip) {
    if (strpos($host, $ip) !== false) {
        $is_admin = true;
        break;
    }
}

if (!$is_admin) {
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
