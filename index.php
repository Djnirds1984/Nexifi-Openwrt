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
