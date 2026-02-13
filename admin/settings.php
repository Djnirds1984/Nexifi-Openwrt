<?php include 'header.php'; ?>
<?php
$configFile = 'pisowifi';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $coin_val = escapeshellarg($_POST['coin_value']);
    $time_val = escapeshellarg($_POST['time_per_coin']);
    $portal_url = escapeshellarg($_POST['portal_url']);
    $ssid = escapeshellarg($_POST['ssid']);
    
    // System Settings
    exec("uci set $configFile.general.coin_value=$coin_val");
    exec("uci set $configFile.general.time_per_coin=$time_val");
    exec("uci set $configFile.general.portal_url=$portal_url");
    
    // Hotspot/Wireless Settings
    // Find the first AP interface to update SSID
    exec("uci show wireless | grep '=wifi-iface' | grep '.mode='ap'' | head -n 1 | cut -d. -f2 | cut -d= -f1", $iface_output);
    if (isset($iface_output[0]) && $iface_output[0]) {
        $iface = $iface_output[0];
        exec("uci set wireless.$iface.ssid=$ssid");
    }
    
    exec("uci commit $configFile");
    exec("uci commit wireless");
    
    // Reload services
    exec("wifi reload");
    
    $msg = "Settings saved successfully. WiFi may restart.";
}

// Read current config
exec("uci get $configFile.general.coin_value 2>/dev/null", $o1);
exec("uci get $configFile.general.time_per_coin 2>/dev/null", $o2);
exec("uci get $configFile.general.portal_url 2>/dev/null", $o3);

// Get current SSID
exec("uci show wireless | grep '.ssid=' | head -n 1 | cut -d= -f2 | tr -d \"'\"", $o4);

$current_coin = isset($o1[0]) ? $o1[0] : '1';
$current_time = isset($o2[0]) ? $o2[0] : '10';
$current_url = isset($o3[0]) ? $o3[0] : 'http://10.0.0.1/pisowifi';
$current_ssid = isset($o4[0]) ? $o4[0] : 'Pisowifi';
?>

<div class="card">
    <h3>General Settings</h3>
    <?php if ($msg): ?>
        <div class="alert alert-success"><?php echo $msg; ?></div>
    <?php endif; ?>
    
    <form method="post">
        <h4>Pricing & Time</h4>
        <label>Coin Value (PHP)</label>
        <input type="number" name="coin_value" value="<?php echo $current_coin; ?>" required>
        
        <label>Time per Coin (Minutes)</label>
        <input type="number" name="time_per_coin" value="<?php echo $current_time; ?>" required>
        
        <h4>Hotspot Settings</h4>
        <label>WiFi Name (SSID)</label>
        <input type="text" name="ssid" value="<?php echo $current_ssid; ?>" required>
        
        <label>Portal URL (Redirect)</label>
        <input type="text" name="portal_url" value="<?php echo $current_url; ?>" required>
        
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
</div>

<?php include 'footer.php'; ?>
