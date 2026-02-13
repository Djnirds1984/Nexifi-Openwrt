<?php include 'header.php'; ?>
<?php
// network.php - Network Overview

// Helper to get interface status
function getInterfaceStatus() {
    $status = [];
    exec("ifconfig -a", $output);
    $current_iface = '';
    
    foreach ($output as $line) {
        if (preg_match('/^(\w+[\.\:\-\w]*)\s+Link/', $line, $m)) {
            $current_iface = $m[1];
            $status[$current_iface] = ['up' => false, 'ip' => '-', 'mac' => '-'];
        }
        
        if ($current_iface) {
            if (strpos($line, 'UP') !== false && strpos($line, 'RUNNING') !== false) {
                $status[$current_iface]['up'] = true;
            }
            if (preg_match('/inet addr:([\d\.]+)/', $line, $m)) {
                $status[$current_iface]['ip'] = $m[1];
            }
            if (preg_match('/HWaddr\s+([0-9A-Fa-f\:]+)/', $line, $m)) {
                $status[$current_iface]['mac'] = $m[1];
            }
        }
    }
    return $status;
}

$interfaces = getInterfaceStatus();
?>

<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; gap: 10px;">
        <a href="network.php" class="btn btn-primary">Overview</a>
        <a href="network_bridges.php" class="btn" style="background: #6c757d;">Bridges</a>
        <a href="network_vlans.php" class="btn" style="background: #6c757d;">VLANs</a>
    </div>
</div>

<div class="card">
    <h3>Network Interfaces Overview</h3>
    <table>
        <thead>
            <tr>
                <th>Interface</th>
                <th>Status</th>
                <th>IP Address</th>
                <th>MAC Address</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($interfaces as $name => $info): ?>
            <tr>
                <td><?php echo $name; ?></td>
                <td>
                    <?php if ($info['up']): ?>
                        <span style="color: green; font-weight: bold;">UP</span>
                    <?php else: ?>
                        <span style="color: red;">DOWN</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $info['ip']; ?></td>
                <td><?php echo $info['mac']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'footer.php'; ?>
