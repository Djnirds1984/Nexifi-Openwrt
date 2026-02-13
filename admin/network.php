<?php include 'header.php'; ?>
<?php
// network.php - Network Overview

$msg = '';

// Delete Network Interface Logic
if (isset($_POST['delete_network'])) {
    $del_net = $_POST['delete_network'];
    if ($del_net !== 'lan' && $del_net !== 'wan' && $del_net !== 'loopback') {
        exec("uci delete network.$del_net");
        exec("uci delete dhcp.$del_net");
        exec("uci commit network");
        exec("uci commit dhcp");
        exec("/etc/init.d/network reload");
        $msg = "Network interface '$del_net' deleted.";
    } else {
        $msg = "Cannot delete core network '$del_net'.";
    }
}

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

// Get UCI Networks (Configuration)
function getUciNetworks() {
    $networks = [];
    exec("uci show network", $out);
    foreach ($out as $line) {
        if (preg_match('/^network\.(\w+)=interface/', $line, $m)) {
            $name = $m[1];
            $networks[$name] = ['name' => $name, 'proto' => '?', 'ip' => '?', 'device' => '?'];
            
            exec("uci get network.$name.proto 2>/dev/null", $p);
            if (isset($p[0])) $networks[$name]['proto'] = $p[0];
            
            exec("uci get network.$name.ipaddr 2>/dev/null", $i);
            if (isset($i[0])) $networks[$name]['ip'] = $i[0];
            
            exec("uci get network.$name.device 2>/dev/null", $d);
            if (isset($d[0])) $networks[$name]['device'] = $d[0];
        }
    }
    return $networks;
}

$interfaces = getInterfaceStatus();
$uci_networks = getUciNetworks();
?>

<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; gap: 10px;">
        <a href="network.php" class="btn btn-primary">Overview</a>
        <a href="network_bridges.php" class="btn btn-secondary">Bridges</a>
        <a href="network_vlans.php" class="btn btn-secondary">VLANs</a>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>

<div class="card">
    <h3>Configured Networks (UCI)</h3>
    <p style="font-size:0.9em; color:#666;">These are the logical networks defined in OpenWrt. You can delete old/zombie networks here.</p>
    <table>
        <thead>
            <tr>
                <th>Network Name</th>
                <th>Proto</th>
                <th>IP Address</th>
                <th>Device</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($uci_networks as $net): ?>
            <tr>
                <td><?php echo $net['name']; ?></td>
                <td><?php echo $net['proto']; ?></td>
                <td><?php echo $net['ip']; ?></td>
                <td><?php echo $net['device']; ?></td>
                <td>
                    <?php if ($net['name'] !== 'lan' && $net['name'] !== 'wan' && $net['name'] !== 'loopback'): ?>
                        <form method="post" onsubmit="return confirm('Delete network <?php echo $net['name']; ?>? This will remove its IP and DHCP config.');">
                            <input type="hidden" name="delete_network" value="<?php echo $net['name']; ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.8em;">Delete</button>
                        </form>
                    <?php else: ?>
                        <span style="color:#999; font-size:0.8em;">System</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>Physical Interface Status (ifconfig)</h3>
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
