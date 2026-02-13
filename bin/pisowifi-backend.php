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
