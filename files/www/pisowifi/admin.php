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
