<?php include 'header.php'; ?>
<?php
$msg = '';

// Helper to get available network interfaces for bridging
function getInterfaces() {
    $interfaces = [];
    $raw = glob('/sys/class/net/*');
    foreach ($raw as $iface_path) {
        $iface = basename($iface_path);
        // Exclude loopback
        if ($iface != 'lo') {
            $interfaces[] = $iface;
        }
    }
    return $interfaces;
}

// Create/Update Bridge Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_bridge'])) {
        $name = escapeshellarg($_POST['bridge_name']); // e.g. br-lan
        $ports = $_POST['ports']; // array of interfaces
        
        // Ensure name starts with 'br-' if user didn't provide it
        // Or OpenWrt convention: just 'lan' interface with type 'bridge'
        // But for device section (DSA), we define a device.
        
        // We'll use the 'config device' syntax for bridges (modern OpenWrt)
        // Check if device exists
        
        $devName = $_POST['bridge_name'];
        if (strpos($devName, 'br-') !== 0) {
            $devName = 'br-' . $devName;
        }
        
        // Add device section
        exec("uci add network device > /tmp/new_dev_id");
        $id = trim(file_get_contents('/tmp/new_dev_id'));
        exec("uci set network.$id.name='$devName'");
        exec("uci set network.$id.type='bridge'");
        
        // Add ports
        // Clear existing list if editing (though this is add)
        // For 'add', we assume new.
        foreach ($ports as $port) {
            exec("uci add_list network.$id.ports='$port'");
        }
        
        exec("uci commit network");
        exec("/etc/init.d/network reload");
        
        $msg = "Bridge '$devName' created successfully.";
    }
    
    if (isset($_POST['delete_bridge'])) {
        $del_dev = $_POST['delete_bridge'];
        // Find section
        exec("uci show network | grep \".name='$del_dev'\"", $out);
        if (!empty($out)) {
            $section = explode('.', explode('=', $out[0])[0])[1];
            exec("uci delete network.$section");
            exec("uci commit network");
            exec("/etc/init.d/network reload");
            $msg = "Bridge '$del_dev' deleted.";
        }
    }
    
    // Update Ports Logic (Edit)
    if (isset($_POST['update_bridge'])) {
        $bridge_name = $_POST['bridge_name_edit'];
        $new_ports = isset($_POST['ports_edit']) ? $_POST['ports_edit'] : [];
        
        // Find section
        exec("uci show network | grep \".name='$bridge_name'\"", $out);
        if (!empty($out)) {
            $section = explode('.', explode('=', $out[0])[0])[1];
            
            // Clear ports list
            exec("uci delete network.$section.ports");
            
            // Add new ports
            foreach ($new_ports as $port) {
                exec("uci add_list network.$section.ports='$port'");
            }
            
            exec("uci commit network");
            exec("/etc/init.d/network reload");
            $msg = "Bridge '$bridge_name' updated.";
        }
    }
}

// List Bridges
$bridges = [];
exec("uci show network", $uci_out);
$dev_map = [];

foreach ($uci_out as $line) {
    // 1. Modern DSA Bridge (config device)
    // network.@device[0].type='bridge'
    if (preg_match('/network\.(@device\[\d+\])\.type=\'bridge\'/', $line, $m)) {
        $dev_map[$m[1]]['type'] = 'bridge';
        $dev_map[$m[1]]['section'] = $m[1];
    }
    if (preg_match('/network\.(@device\[\d+\])\.name=\'(.+)\'/', $line, $m)) {
        $dev_map[$m[1]]['name'] = $m[2];
    }
    if (preg_match('/network\.(@device\[\d+\])\.ports=\'(.+)\'/', $line, $m)) {
        // uci show returns one line per list item usually? 
        // Or sometimes space separated if it's option ports? 
        // For list, it's usually multiple lines or list syntax. 
        // uci show format: network.@device[0].ports+='eth0' (if list) or .ports='eth0'
        $dev_map[$m[1]]['ports'][] = $m[2];
    }
    
    // 2. Legacy Interface Bridge (config interface)
    // network.lan.type='bridge'
    if (preg_match('/network\.(\w+)\.type=\'bridge\'/', $line, $m)) {
        $section = $m[1];
        // Ensure it's not a device section (already handled)
        if (strpos($section, '@device') === false) {
            $dev_map[$section]['type'] = 'bridge';
            $dev_map[$section]['section'] = $section;
            $dev_map[$section]['name'] = "br-$section"; // Convention
        }
    }
    // network.lan.ifname='eth0 eth1'
    if (preg_match('/network\.(\w+)\.ifname=\'(.+)\'/', $line, $m)) {
        $section = $m[1];
        if (isset($dev_map[$section])) {
            $ifnames = explode(' ', $m[2]);
            foreach($ifnames as $if) {
                $dev_map[$section]['ports'][] = $if;
            }
        }
    }
}

foreach ($dev_map as $d) {
    if (isset($d['type']) && $d['type'] == 'bridge') {
        $bridges[] = $d;
    }
}

$interfaces = getInterfaces();
?>

<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; gap: 10px;">
        <a href="network.php" class="btn btn-secondary">Overview</a>
        <a href="network_bridges.php" class="btn btn-primary">Bridges</a>
        <a href="network_vlans.php" class="btn btn-secondary">VLANs</a>
    </div>
</div>

<div class="card">
    <h3>Create New Bridge</h3>
    <?php if ($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>
    
    <form method="post">
        <label>Bridge Name</label>
        <input type="text" name="bridge_name" placeholder="Ex: lan (will become br-lan)" required>
        
        <label>Bridge Ports (Select multiple)</label>
        <select name="ports[]" multiple style="height: 150px;" required>
            <?php foreach ($interfaces as $iface): ?>
                <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
            <?php endforeach; ?>
        </select>
        <p style="font-size:0.8em; color:#666; margin-top:-10px;">Hold Ctrl (Cmd) to select multiple interfaces.</p>
        
        <button type="submit" name="add_bridge" class="btn btn-primary">Create Bridge</button>
    </form>
</div>

<div class="card">
    <h3>Configured Bridges</h3>
    <table>
        <thead>
            <tr>
                <th>Bridge Name</th>
                <th>Ports</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bridges)): ?>
                <tr><td colspan="3" style="text-align:center;">No bridges configured</td></tr>
            <?php else: ?>
                <?php foreach ($bridges as $b): ?>
                <tr>
                    <td><?php echo $b['name']; ?></td>
                    <td><?php echo isset($b['ports']) ? implode(', ', $b['ports']) : '-'; ?></td>
                    <td>
                        <button onclick="editBridge('<?php echo $b['name']; ?>', '<?php echo isset($b['ports']) ? implode(',', $b['ports']) : ''; ?>')" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.8em;">Edit</button>
                        
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this bridge?');">
                            <input type="hidden" name="delete_bridge" value="<?php echo $b['name']; ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.8em;">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Edit Modal (Simple implementation via hidden div) -->
<div id="editForm" class="card" style="display:none; border: 1px solid #007bff;">
    <h3>Edit Bridge: <span id="edit_bridge_name_display"></span></h3>
    <form method="post">
        <input type="hidden" name="bridge_name_edit" id="edit_bridge_name">
        <input type="hidden" name="update_bridge" value="1">
        
        <label>Update Ports</label>
        <select name="ports_edit[]" id="edit_ports" multiple style="height: 150px;">
            <?php foreach ($interfaces as $iface): ?>
                <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
            <?php endforeach; ?>
        </select>
        
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <button type="button" onclick="document.getElementById('editForm').style.display='none'" class="btn btn-secondary">Cancel</button>
    </form>
</div>

<script>
function editBridge(name, portsCsv) {
    document.getElementById('editForm').style.display = 'block';
    document.getElementById('edit_bridge_name').value = name;
    document.getElementById('edit_bridge_name_display').innerText = name;
    
    var ports = portsCsv.split(',');
    var select = document.getElementById('edit_ports');
    
    for (var i = 0; i < select.options.length; i++) {
        select.options[i].selected = ports.indexOf(select.options[i].value) !== -1;
    }
    
    // Scroll to edit form
    document.getElementById('editForm').scrollIntoView({behavior: 'smooth'});
}
</script>

<?php include 'footer.php'; ?>
