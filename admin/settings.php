<?php include 'header.php'; ?>
<?php
$configFile = 'pisowifi';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $coin_val = escapeshellarg($_POST['coin_value']);
    $time_val = escapeshellarg($_POST['time_per_coin']);
    $portal_url = escapeshellarg($_POST['portal_url']);
    
    // System Settings
    exec("uci set $configFile.general.coin_value=$coin_val");
    exec("uci set $configFile.general.time_per_coin=$time_val");
    exec("uci set $configFile.general.portal_url=$portal_url");
    
    exec("uci commit $configFile");
    
    // Reload services (optional if backend polls)
    // exec("wifi reload"); // Not needed if we don't touch wireless here
    
    $msg = "Settings saved successfully.";
}

// Read current config
exec("uci get $configFile.general.coin_value 2>/dev/null", $o1);
exec("uci get $configFile.general.time_per_coin 2>/dev/null", $o2);
exec("uci get $configFile.general.portal_url 2>/dev/null", $o3);

$current_coin = isset($o1[0]) ? $o1[0] : '1';
$current_time = isset($o2[0]) ? $o2[0] : '10';
$current_url = isset($o3[0]) ? $o3[0] : 'http://10.0.0.1/pisowifi';
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
        
        <h4>Portal Settings</h4>
        <label>Portal URL (Redirect)</label>
        <input type="text" name="portal_url" value="<?php echo $current_url; ?>" required>
        
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
</div>

<?php include 'footer.php'; ?>
