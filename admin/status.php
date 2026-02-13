<?php include 'header.php'; ?>
<?php
// Helper to execute and return output
function runCmd($cmd) {
    exec($cmd . " 2>&1", $output);
    return implode("\n", $output);
}

// 1. Wireless Status
$wifi_status = runCmd("iwinfo | grep -E 'ESSID|Mode|Channel|Tx-Power|Signal'");

// 2. Network Interfaces
$net_status = runCmd("ifconfig | grep -E 'Link encap|inet addr'");

// 3. System Load
$sys_load = runCmd("uptime");

// 4. Services Status
$backend_status = runCmd("ps | grep pisowifi-backend | grep -v grep");
$backend_running = !empty($backend_status);

// 5. Firewall Rules
$fw_status = runCmd("iptables -L pisowifi_auth -n | head -n 5");

?>

<div class="card">
    <h3>System Diagnostics</h3>
    
    <div class="stat-box <?php echo $backend_running ? 'green' : 'orange'; ?>" style="margin-bottom: 20px;">
        <div class="stat-number"><?php echo $backend_running ? 'RUNNING' : 'STOPPED'; ?></div>
        <div>Backend Service Status</div>
    </div>

    <h4>Wireless Status (iwinfo)</h4>
    <pre style="background:#333; color:#fff; padding:10px; border-radius:5px; overflow-x:auto;"><?php echo htmlspecialchars($wifi_status ?: "No wireless info available. Driver might be missing."); ?></pre>
    
    <h4>Network Interfaces (ifconfig)</h4>
    <pre style="background:#333; color:#fff; padding:10px; border-radius:5px; overflow-x:auto;"><?php echo htmlspecialchars($net_status); ?></pre>
    
    <h4>Firewall Check (pisowifi_auth)</h4>
    <pre style="background:#333; color:#fff; padding:10px; border-radius:5px; overflow-x:auto;"><?php echo htmlspecialchars($fw_status); ?></pre>
    
    <h4>System Load</h4>
    <pre style="background:#333; color:#fff; padding:10px; border-radius:5px; overflow-x:auto;"><?php echo htmlspecialchars($sys_load); ?></pre>
</div>

<?php include 'footer.php'; ?>
