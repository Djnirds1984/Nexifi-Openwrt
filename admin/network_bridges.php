<?php include 'header.php'; ?>

<?php
$msg = '';

// --- Helper Functions ---

function getPhysicalInterfaces() {
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

function getWifiSsids() {
    $ssids = []; // [section => ssid_name]
    exec("uci show wireless", $lines);
    foreach ($lines as $line) {
        if (preg_match("/wireless\.(@wifi-iface\[\d+\])\.ssid='(.+)'/", $line, $m)) {
            $ssids[$m[1]] = $m[2];
        }
    }
    return $ssids;
}

function getBridges() {
    $bridges = [];
    exec("uci show network", $lines);
    
    // 1. Find all devices of type 'bridge'
    $devices = [];
    foreach ($lines as $line) {
        if (preg_match("/network\.([^.]+)\.type='bridge'/", $line, $m)) {
            $section = $m[1];
            $devices[$section] = ['section' => $section, 'ports' => []];
        }
    }

    // 2. Get details for each bridge device
    foreach ($devices as $section => &$info) {
        $name_cmd = "uci get network.$section.name 2>/dev/null";
        $name = trim(shell_exec($name_cmd));
        if (empty($name)) continue;
        $info['name'] = $name;

        $ports_cmd = "uci get network.$section.ports 2>/dev/null";
        $ports_out = trim(shell_exec($ports_cmd));
        if (!empty($ports_out)) {
            $info['ports'] = explode(' ', $ports_out);
        }
    }
    unset($info);

    // 3. Map Device Name -> Logical Interface
    $deviceToInterface = [];
    foreach ($lines as $line) {
        if (preg_match("/network\.([^.]+)\.device='(.+)'/", $line, $m)) {
            $interface = $m[1];
            $device = $m[2];
            if (strpos($interface, '@device') === false) {
                $deviceToInterface[$device] = $interface;
            }
        }
    }

    // 4. Find WiFi attached to these networks
    $wifi_map = []; 
    $wifi_sec_map = []; 
    
    $ssids = getWifiSsids();
    foreach ($ssids as $section => $ssid) {
        $net = trim(shell_exec("uci get wireless.$section.network 2>/dev/null"));
        if ($net) {
            $wifi_map[$net][] = $ssid;
            $wifi_sec_map[$net][] = $section;
        }
    }

    // Combine all info
    foreach ($devices as $d) {
        $br_name = $d['name'];
        $logic_iface = isset($deviceToInterface[$br_name]) ? $deviceToInterface[$br_name] : '(none)';
        
        $attached_wifi = [];
        $attached_wifi_secs = [];
        
        if (isset($wifi_map[$logic_iface])) {
            $attached_wifi = $wifi_map[$logic_iface];
            $attached_wifi_secs = $wifi_sec_map[$logic_iface];
        } else if (isset($wifi_map[$br_name])) {
             $attached_wifi = $wifi_map[$br_name];
             $attached_wifi_secs = $wifi_sec_map[$br_name];
        }

        $bridges[] = [
            'name' => $br_name,
            'ports' => $d['ports'],
            'interface' => $logic_iface,
            'wifi' => $attached_wifi,
            'wifi_sections' => $attached_wifi_secs
        ];
    }

    return $bridges;
}

// --- Form Handling ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ADD BRIDGE
    if (isset($_POST['add_bridge'])) {
        $bridge_name = trim($_POST['bridge_name']);
        if (!empty($bridge_name)) {
            if (strpos($bridge_name, 'br-') !== 0) {
                $bridge_name = 'br-' . $bridge_name;
            }

            exec("uci add network device > /tmp/new_dev_id");
            $id = trim(file_get_contents('/tmp/new_dev_id'));
            exec("uci set network.$id.name='$bridge_name'");
            exec("uci set network.$id.type='bridge'");
            
            if (isset($_POST['ports']) && is_array($_POST['ports'])) {
                foreach ($_POST['ports'] as $port) {
                    exec("uci add_list network.$id.ports='$port'");
                }
            }

            $iface_name = str_replace('br-', '', $bridge_name);
            exec("uci set network.$iface_name=interface");
            exec("uci set network.$iface_name.proto='static'"); 
            exec("uci set network.$iface_name.device='$bridge_name'");
            exec("uci set network.$iface_name.ipaddr='192.168.1" . rand(10,99) . ".1'");
            exec("uci set network.$iface_name.netmask='255.255.255.0'");

            exec("uci commit network");
            exec("/etc/init.d/network reload");
            $msg = "Bridge $bridge_name created with interface $iface_name.";
        }
    }

    // DELETE BRIDGE
    if (isset($_POST['delete_bridge'])) {
        $del_name = $_POST['delete_bridge_name'];
        
        exec("uci show network | grep \".name='$del_name'\"", $out);
        if (!empty($out)) {
            $dev_section = explode('.', explode('=', $out[0])[0])[1];
            exec("uci delete network.$dev_section");
            
            exec("uci show network | grep \".device='$del_name'\"", $out_iface);
            if (!empty($out_iface)) {
                 $iface_section = explode('.', explode('=', $out_iface[0])[0])[1];
                 exec("uci delete network.$iface_section");
            }

            exec("uci commit network");
            exec("/etc/init.d/network reload");
            $msg = "Bridge $del_name deleted.";
        }
    }

    // UPDATE BRIDGE
    if (isset($_POST['update_bridge'])) {
        $bridge_name = $_POST['edit_bridge_name_orig'];
        $new_ports = isset($_POST['edit_ports']) ? $_POST['edit_ports'] : [];
        $new_wifi_sections = isset($_POST['edit_wifi']) ? $_POST['edit_wifi'] : [];

        exec("uci show network | grep \".name='$bridge_name'\"", $out);
        if (!empty($out)) {
            $dev_section = explode('.', explode('=', $out[0])[0])[1];
            exec("uci delete network.$dev_section.ports");
            foreach ($new_ports as $p) {
                exec("uci add_list network.$dev_section.ports='$p'");
            }
        }

        exec("uci show network | grep \".device='$bridge_name'\"", $out_iface);
        $logic_iface = "";
        if (!empty($out_iface)) {
             $logic_iface = explode('.', explode('=', $out_iface[0])[0])[1];
        }

        if ($logic_iface) {
            foreach ($new_wifi_sections as $sec) {
                exec("uci set wireless.$sec.network='$logic_iface'");
            }
        }

        exec("uci commit network");
        exec("uci commit wireless");
        exec("/etc/init.d/network reload");
        exec("wifi reload");
        $msg = "Bridge $bridge_name updated.";
    }
}

$phy_interfaces = getPhysicalInterfaces();
$wifi_ssids = getWifiSsids();
$bridges = getBridges();

?>

<style>
    /* Custom Styles to replace Bootstrap/FontAwesome dependencies */
    .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .badge { padding: 4px 8px; border-radius: 12px; font-size: 0.85em; color: white; display: inline-block; margin-right: 5px; margin-bottom: 2px; }
    .badge-info { background: #17a2b8; }
    .badge-secondary { background: #6c757d; }
    .badge-warning { background: #ffc107; color: #212529; }
    
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
    .btn-edit { background-color: #007bff; }
    .btn-edit:hover { background-color: #0056b3; }
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
    
    .form-row { display: flex; gap: 20px; flex-wrap: wrap; }
    .form-col { flex: 1; min-width: 250px; }
    
    label { font-weight: 600; color: #333; display: block; margin-bottom: 5px; }
    select[multiple] { height: 120px; }
    .help-text { font-size: 0.85em; color: #666; margin-top: 4px; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 10px; background: white; border-radius: 5px; overflow: hidden; }
    th { background: #f8f9fa; color: #333; font-weight: 600; border-bottom: 2px solid #dee2e6; }
    td { border-bottom: 1px solid #dee2e6; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
</style>

<div class="main-container">
    <div class="header-flex">
        <div>
            <h2>Network Bridges</h2>
            <p style="color: #666; margin: 5px 0 0 0;">Manage network bridges and interface assignments.</p>
        </div>
        <button onclick="toggleAddForm()" class="btn btn-primary" id="addBtn">+ Add New Bridge</button>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-info" style="background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 4px; margin-bottom: 20px;">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <!-- Add Form -->
    <div id="addForm" class="form-card hidden">
        <h3 class="form-title">Create New Bridge</h3>
        <form method="post">
            <div class="form-group">
                <label>Bridge Name</label>
                <div style="display: flex; align-items: center;">
                    <span style="background: #e9ecef; padding: 10px; border: 1px solid #ccc; border-right: none; border-radius: 4px 0 0 4px;">br-</span>
                    <input type="text" name="bridge_name" required pattern="[a-zA-Z0-9_]+" placeholder="custom" style="flex: 1; border-radius: 0 4px 4px 0;">
                </div>
                <div class="help-text">A new logical interface will also be created automatically.</div>
            </div>
            
            <div class="form-group">
                <label>Physical Ports</label>
                <select name="ports[]" multiple class="form-control">
                    <?php foreach ($phy_interfaces as $iface): ?>
                        <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help-text">Hold Ctrl/Cmd to select multiple ports.</div>
            </div>

            <div style="margin-top: 20px;">
                <input type="hidden" name="add_bridge" value="1">
                <button type="submit" class="btn btn-primary">Create Bridge</button>
                <button type="button" class="btn btn-cancel" onclick="toggleAddForm()">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Edit Form -->
    <div id="editForm" class="form-card hidden">
        <h3 class="form-title">Edit Bridge: <span id="edit_title_display" style="color: #007bff;"></span></h3>
        <form method="post">
            <input type="hidden" name="edit_bridge_name_orig" id="edit_bridge_name_orig">
            
            <div class="form-row">
                <div class="form-col">
                    <label>Physical Ports</label>
                    <select name="edit_ports[]" id="edit_ports" multiple class="form-control">
                        <?php foreach ($phy_interfaces as $iface): ?>
                            <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-col">
                    <label>Attach WiFi Networks</label>
                    <select name="edit_wifi[]" id="edit_wifi" multiple class="form-control">
                        <?php foreach ($wifi_ssids as $sec => $ssid): ?>
                            <option value="<?php echo $sec; ?>"><?php echo $ssid; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help-text">Select SSIDs to bridge to this network.</div>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <input type="hidden" name="update_bridge" value="1">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('editForm').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Bridges Table -->
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Bridge Device</th>
                    <th>Logical Interface</th>
                    <th>Physical Ports</th>
                    <th>Attached WiFi</th>
                    <th width="160">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bridges)): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 20px;">No bridges found.</td></tr>
                <?php else: ?>
                    <?php foreach ($bridges as $b): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($b['name']); ?></strong></td>
                            <td>
                                <span class="badge badge-info"><?php echo htmlspecialchars($b['interface']); ?></span>
                            </td>
                            <td>
                                <?php foreach ($b['ports'] as $p): ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($p); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php foreach ($b['wifi'] as $w): ?>
                                    <span class="badge badge-warning">WiFi: <?php echo htmlspecialchars($w); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <button class="action-btn btn-edit" onclick='openEditForm(<?php echo json_encode($b); ?>)'>Edit</button>
                                
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this bridge?');">
                                    <input type="hidden" name="delete_bridge_name" value="<?php echo htmlspecialchars($b['name']); ?>">
                                    <button type="submit" name="delete_bridge" class="action-btn btn-delete">Delete</button>
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
        var editForm = document.getElementById('editForm');
        
        if (form.style.display === 'none' || form.style.display === '') {
            form.style.display = 'block';
            editForm.style.display = 'none'; // Close edit if open
            // Scroll to form
            form.scrollIntoView({ behavior: 'smooth' });
        } else {
            form.style.display = 'none';
        }
    }

    function openEditForm(bridge) {
        var addForm = document.getElementById('addForm');
        var editForm = document.getElementById('editForm');
        
        // Hide Add Form
        addForm.style.display = 'none';
        
        // Show Edit Form
        editForm.style.display = 'block';
        
        // Populate Fields
        document.getElementById('edit_title_display').innerText = bridge.name;
        document.getElementById('edit_bridge_name_orig').value = bridge.name;
        
        // Reset Multi-selects
        var portSelect = document.getElementById('edit_ports');
        for (var i = 0; i < portSelect.options.length; i++) {
            portSelect.options[i].selected = false;
        }
        
        var wifiSelect = document.getElementById('edit_wifi');
        for (var i = 0; i < wifiSelect.options.length; i++) {
            wifiSelect.options[i].selected = false;
        }
        
        // Select Ports
        if (bridge.ports && Array.isArray(bridge.ports)) {
            for (var i = 0; i < portSelect.options.length; i++) {
                if (bridge.ports.indexOf(portSelect.options[i].value) !== -1) {
                    portSelect.options[i].selected = true;
                }
            }
        }
        
        // Select WiFi
        if (bridge.wifi_sections && Array.isArray(bridge.wifi_sections)) {
            for (var i = 0; i < wifiSelect.options.length; i++) {
                if (bridge.wifi_sections.indexOf(wifiSelect.options[i].value) !== -1) {
                    wifiSelect.options[i].selected = true;
                }
            }
        }
        
        // Scroll to form
        editForm.scrollIntoView({ behavior: 'smooth' });
    }
</script>

<?php include 'footer.php'; ?>
