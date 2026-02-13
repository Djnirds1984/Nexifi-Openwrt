<?php include 'header.php'; ?>

<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; gap: 10px;">
        <a href="network.php" class="btn btn-secondary">Overview</a>
        <a href="network_bridges.php" class="btn btn-secondary">Bridges</a>
        <a href="network_vlans.php" class="btn btn-primary">VLANs</a>
    </div>
</div>

<?php
$msg = '';

// Helper to get available network interfaces
function getInterfaces() {
    $interfaces = [];
    $raw = glob('/sys/class/net/*');
    foreach ($raw as $iface_path) {
        $iface = basename($iface_path);
        // Exclude loopback and wifi radios (usually handled separately, but let's include phy0/wlan0 if needed)
        // Usually we want eth0, eth1, wlan0, br-lan, etc.
        if ($iface != 'lo') {
            $interfaces[] = $iface;
        }
    }
    return $interfaces;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_vlan'])) {
        $parent = escapeshellarg($_POST['parent']);
        $vid = intval($_POST['vid']);
        $desc = escapeshellarg($_POST['desc']); // Optional description
        
        if ($vid < 1 || $vid > 4094) {
            $msg = "Error: VLAN ID must be between 1 and 4094.";
        } else {
            // Check if device already exists in network config
            // We'll create a new device section for the VLAN
            // Using modern OpenWrt device configuration (DSA-ish or just simple VLAN device)
            
            // Name convention: parent.vid (e.g., eth1.10)
            $devName = $_POST['parent'] . '.' . $vid;
            
            // Add device section
            exec("uci add network device > /tmp/new_dev_id");
            $id = trim(file_get_contents('/tmp/new_dev_id'));
            exec("uci set network.$id.name='$devName'");
            exec("uci set network.$id.type='8021q'");
            exec("uci set network.$id.ifname='$parent'");
            exec("uci set network.$id.vid='$vid'");
            
            // Optional: Store description in a comment or separate config if needed
            // For now, we rely on the device existing.
            
            exec("uci commit network");
            exec("/etc/init.d/network reload");
            
            $msg = "VLAN Interface $devName created successfully.";
        }
    }
    
    if (isset($_POST['delete_vlan'])) {
        $del_dev = $_POST['delete_vlan'];
        // Find the uci section for this device name
        // Iterate through network config to find section with option name='$del_dev'
        exec("uci show network | grep \".name='$del_dev'\"", $out);
        if (!empty($out)) {
            // extract section ID: network.@device[X].name='...'
            $section = explode('.', explode('=', $out[0])[0])[1]; // @device[X]
            exec("uci delete network.$section");
            exec("uci commit network");
            exec("/etc/init.d/network reload");
            $msg = "VLAN Interface $del_dev deleted.";
        } else {
            $msg = "Error: VLAN configuration not found.";
        }
    }
}

// List existing VLANs
$vlans = [];
// Scan UCI network devices
exec("uci show network", $uci_out);
$current_dev = null;
$dev_map = [];

foreach ($uci_out as $line) {
    // network.@device[0].type='8021q'
    if (preg_match('/network\.(@device\[\d+\])\.type=\'8021q\'/', $line, $m)) {
        $dev_map[$m[1]]['type'] = 'vlan';
    }
    // network.@device[0].name='eth1.10'
    if (preg_match('/network\.(@device\[\d+\])\.name=\'(.+)\'/', $line, $m)) {
        $dev_map[$m[1]]['name'] = $m[2];
    }
    // network.@device[0].vid='10'
    if (preg_match('/network\.(@device\[\d+\])\.vid=\'(\d+)\'/', $line, $m)) {
        $dev_map[$m[1]]['vid'] = $m[2];
    }
    // network.@device[0].ifname='eth1'
    if (preg_match('/network\.(@device\[\d+\])\.ifname=\'(.+)\'/', $line, $m)) {
        $dev_map[$m[1]]['parent'] = $m[2];
    }
}

foreach ($dev_map as $d) {
    if (isset($d['type']) && $d['type'] == 'vlan' && isset($d['name'])) {
        $vlans[] = $d;
    }
}

$interfaces = getInterfaces();
?>

<div class="card">
    <h3>Create New VLAN</h3>
    <?php if ($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>
    
    <form method="post">
        <label>Parent Interface</label>
        <select name="parent" required>
            <?php foreach ($interfaces as $iface): ?>
                <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
            <?php endforeach; ?>
        </select>
        <p style="font-size:0.8em; color:#666; margin-top:-10px;">Select the physical interface (e.g., eth0, eth1) where the VLAN tag will be applied.</p>
        
        <label>VLAN ID</label>
        <input type="number" name="vid" placeholder="Ex: 10" min="1" max="4094" required>
        
        <label>Description (Optional)</label>
        <input type="text" name="desc" placeholder="Ex: Guest Network">
        
        <button type="submit" name="add_vlan" class="btn btn-primary">Create VLAN Interface</button>
    </form>
</div>

<div class="card">
    <h3>Configured VLANs</h3>
    <table>
        <thead>
            <tr>
                <th>Interface Name</th>
                <th>Parent</th>
                <th>VLAN ID</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vlans)): ?>
                <tr><td colspan="4" style="text-align:center;">No VLANs configured</td></tr>
            <?php else: ?>
                <?php foreach ($vlans as $v): ?>
                <tr>
                    <td><?php echo $v['name']; ?></td>
                    <td><?php echo isset($v['parent']) ? $v['parent'] : '-'; ?></td>
                    <td><?php echo isset($v['vid']) ? $v['vid'] : '-'; ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Are you sure? This might break hotspots using this VLAN.');">
                            <input type="hidden" name="delete_vlan" value="<?php echo $v['name']; ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.8em;">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'footer.php'; ?>
