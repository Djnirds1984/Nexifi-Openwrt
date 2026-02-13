<?php include 'header.php'; ?>

<?php
$msg = '';

// Helper to get available network interfaces
function getInterfaces() {
    $interfaces = [];
    $raw = glob('/sys/class/net/*');
    if ($raw) {
        foreach ($raw as $iface_path) {
            $iface = basename($iface_path);
            if ($iface != 'lo') {
                $interfaces[] = $iface;
            }
        }
    }
    return $interfaces;
}

// Helper to get VLANs
function getVlans() {
    $vlans = [];
    exec("uci show network", $uci_out);
    
    // 1. Find 8021q Devices
    $devices = [];
    foreach ($uci_out as $line) {
        // network.@device[0].type='8021q'
        if (preg_match('/network\.(@device\[\d+\])\.type=\'8021q\'/', $line, $m)) {
            $devices[$m[1]] = ['section' => $m[1]];
        }
    }
    
    // 2. Get Device Details
    foreach ($devices as $section => &$d) {
        $name_cmd = "uci get network.$section.name 2>/dev/null";
        $d['name'] = trim(shell_exec($name_cmd));
        
        $vid_cmd = "uci get network.$section.vid 2>/dev/null";
        $d['vid'] = trim(shell_exec($vid_cmd));
        
        $parent_cmd = "uci get network.$section.ifname 2>/dev/null";
        $d['parent'] = trim(shell_exec($parent_cmd));
    }
    unset($d);

    // 3. Find Logical Interfaces using these devices
    // Map DeviceName -> InterfaceName
    $deviceToInterface = [];
    foreach ($uci_out as $line) {
        if (preg_match("/network\.([^.]+)\.device='(.+)'/", $line, $m)) {
            $interface = $m[1];
            $devName = $m[2];
            if (strpos($interface, '@device') === false) {
                $deviceToInterface[$devName] = $interface;
            }
        }
    }

    // Combine
    foreach ($devices as $d) {
        if (!empty($d['name'])) {
            $d['interface'] = isset($deviceToInterface[$d['name']]) ? $deviceToInterface[$d['name']] : '(no interface)';
            $vlans[] = $d;
        }
    }
    
    return $vlans;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ADD VLAN
    if (isset($_POST['add_vlan'])) {
        $parent = trim($_POST['parent']);
        $vid = intval($_POST['vid']);
        $desc = trim($_POST['desc']); 
        
        if ($vid < 1 || $vid > 4094) {
            $msg = "Error: VLAN ID must be between 1 and 4094.";
        } elseif (empty($parent)) {
            $msg = "Error: Parent interface is required.";
        } else {
            // 1. Create Device: parent.vid
            $devName = $parent . '.' . $vid;
            
            // Check if exists
            exec("uci show network | grep \".name='$devName'\"", $exists);
            if (empty($exists)) {
                exec("uci add network device > /tmp/new_dev_id");
                $id = trim(file_get_contents('/tmp/new_dev_id'));
                exec("uci set network.$id.name='$devName'");
                exec("uci set network.$id.type='8021q'");
                exec("uci set network.$id.ifname='$parent'");
                exec("uci set network.$id.vid='$vid'");
                
                // 2. Create Logical Interface
                // Name suggestion: vlan10 or custom
                $ifaceName = "vlan" . $vid;
                
                // Check if interface name exists, append if needed (rare)
                exec("uci get network.$ifaceName", $iface_check);
                if (strpos(implode($iface_check), 'Entry not found') === false) {
                    // Exists, maybe append parent name
                    $ifaceName = "vlan" . $vid . "_" . $parent;
                }

                exec("uci set network.$ifaceName=interface");
                exec("uci set network.$ifaceName.device='$devName'");
                exec("uci set network.$ifaceName.proto='static'"); // Set static but no IP (unconfigured)
                // We could set a dummy IP or let user configure it later
                // exec("uci set network.$ifaceName.ipaddr='10.0.$vid.1'");
                // exec("uci set network.$ifaceName.netmask='255.255.255.0'");
                
                exec("uci commit network");
                exec("/etc/init.d/network reload");
                
                $msg = "VLAN $devName created with interface '$ifaceName'.";
            } else {
                $msg = "Error: Device $devName already exists.";
            }
        }
    }
    
    // DELETE VLAN
    if (isset($_POST['delete_vlan'])) {
        $del_dev = $_POST['delete_vlan']; // Device name e.g. eth0.10
        
        // 1. Delete Device
        exec("uci show network | grep \".name='$del_dev'\"", $out);
        if (!empty($out)) {
            $section = explode('.', explode('=', $out[0])[0])[1];
            exec("uci delete network.$section");
            
            // 2. Delete Logical Interface using this device
            exec("uci show network | grep \".device='$del_dev'\"", $out_iface);
            if (!empty($out_iface)) {
                 $iface_section = explode('.', explode('=', $out_iface[0])[0])[1];
                 exec("uci delete network.$iface_section");
            }

            exec("uci commit network");
            exec("/etc/init.d/network reload");
            $msg = "VLAN $del_dev and associated interface deleted.";
        }
    }
}

$interfaces = getInterfaces();
$vlans = getVlans();

?>

<style>
    /* Consistent Styles */
    .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .badge { padding: 4px 8px; border-radius: 12px; font-size: 0.85em; color: white; display: inline-block; margin-right: 5px; }
    .badge-info { background: #17a2b8; }
    .badge-primary { background: #007bff; }
    .badge-secondary { background: #6c757d; }
    
    .action-btn { 
        padding: 6px 12px; 
        font-size: 0.9em; 
        margin-right: 5px; 
        cursor: pointer; 
        border: none; 
        border-radius: 4px; 
        color: white; 
        display: inline-block;
    }
    .btn-delete { background-color: #dc3545; }
    .btn-delete:hover { background-color: #c82333; }
    .btn-cancel { background-color: #6c757d; margin-left: 10px; }
    
    .hidden { display: none; }
    
    .form-card { 
        background: #fff; 
        padding: 20px; 
        border: 1px solid #dee2e6; 
        border-radius: 5px; 
        margin-bottom: 20px; 
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .form-title { margin-top: 0; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
    
    .form-group { margin-bottom: 15px; }
    label { font-weight: 600; color: #333; display: block; margin-bottom: 5px; }
    .help-text { font-size: 0.85em; color: #666; margin-top: 4px; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 10px; background: white; border-radius: 5px; overflow: hidden; }
    th { background: #f8f9fa; color: #333; font-weight: 600; border-bottom: 2px solid #dee2e6; }
    td { border-bottom: 1px solid #dee2e6; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
</style>

<div class="main-container">
    <div class="header-flex">
        <div>
            <h2>VLAN Management</h2>
            <p style="color: #666; margin: 5px 0 0 0;">Create and manage 802.1Q VLANs.</p>
        </div>
        <button onclick="toggleAddForm()" class="btn btn-primary" id="addBtn">+ Add New VLAN</button>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-info" style="background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 4px; margin-bottom: 20px;">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <!-- Add Form -->
    <div id="addForm" class="form-card hidden">
        <h3 class="form-title">Create New VLAN</h3>
        <form method="post">
            <div class="form-group">
                <label>Parent Interface</label>
                <select name="parent" class="form-control" required>
                    <?php foreach ($interfaces as $iface): ?>
                        <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help-text">Select the physical interface (e.g., eth0, eth1) to attach the VLAN tag to.</div>
            </div>
            
            <div class="form-group">
                <label>VLAN ID</label>
                <input type="number" name="vid" class="form-control" placeholder="10" min="1" max="4094" required>
                <div class="help-text">A unique ID between 1 and 4094.</div>
            </div>

            <div class="form-group">
                <label>Description / Note</label>
                <input type="text" name="desc" class="form-control" placeholder="Optional description">
            </div>

            <div style="margin-top: 20px;">
                <input type="hidden" name="add_vlan" value="1">
                <button type="submit" class="btn btn-primary">Create VLAN</button>
                <button type="button" class="btn btn-cancel" onclick="toggleAddForm()">Cancel</button>
            </div>
        </form>
    </div>

    <!-- VLANs Table -->
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>VLAN Device</th>
                    <th>VLAN ID</th>
                    <th>Parent Interface</th>
                    <th>Logical Interface</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vlans)): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 20px;">No VLANs configured.</td></tr>
                <?php else: ?>
                    <?php foreach ($vlans as $v): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($v['name']); ?></strong></td>
                            <td><span class="badge badge-primary"><?php echo htmlspecialchars($v['vid']); ?></span></td>
                            <td><span class="badge badge-secondary"><?php echo htmlspecialchars($v['parent']); ?></span></td>
                            <td>
                                <?php if ($v['interface'] && $v['interface'] != '(no interface)'): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($v['interface']); ?></span>
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic;">(Device only)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Are you sure? This will delete the VLAN device and its interface.');">
                                    <input type="hidden" name="delete_vlan" value="<?php echo htmlspecialchars($v['name']); ?>">
                                    <button type="submit" name="delete_vlan" class="action-btn btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function toggleAddForm() {
        var form = document.getElementById('addForm');
        if (form.style.display === 'none' || form.style.display === '') {
            form.style.display = 'block';
            form.scrollIntoView({ behavior: 'smooth' });
        } else {
            form.style.display = 'none';
        }
    }
</script>

<?php include 'footer.php'; ?>
