<?php include 'header.php'; ?>
<?php
$msg = '';

// Helper to get available network interfaces for bridging (Physical + Wireless)
function getInterfaces() {
    $interfaces = [];
    
    // 1. Physical
    $raw = glob('/sys/class/net/*');
    foreach ($raw as $iface_path) {
        $iface = basename($iface_path);
        if ($iface != 'lo') {
            $interfaces[] = $iface;
        }
    }
    
    // 2. Wireless SSIDs (as network options)
    // We need to know the 'network' name of the wifi-iface? 
    // Actually, in OpenWrt, you bridge the wifi by setting `option network 'lan'` in wireless config.
    // BUT the user wants to ADD the wifi to the bridge from the Bridge page.
    // This means we need to update the `wireless` config to point to this bridge.
    // This is tricky because usually bridge ports are defined in `network` config.
    // However, wireless is a "consumer" of a network.
    // So we should list SSIDs here, and if selected, we update `wireless` config.
    
    return $interfaces;
}

// Create/Update Bridge Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_bridge'])) {
        $name = escapeshellarg($_POST['bridge_name']); // e.g. br-lan
        $ports = $_POST['ports']; // array of interfaces
        
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
    
    if (isset($_POST['update_bridge'])) {
        $bridge_name = $_POST['bridge_name_edit']; // e.g. br-lan or lan (if using alias)
        $new_ports = isset($_POST['ports_edit']) ? $_POST['ports_edit'] : [];
        $wireless_attach = isset($_POST['wifi_attach']) ? $_POST['wifi_attach'] : []; // Array of wifi-iface sections
        
        // 1. Update Network Config (Physical Ports)
        // Find network section
        exec("uci show network | grep \".name='$bridge_name'\"", $out);
        $net_section = "";
        if (!empty($out)) {
            $net_section = explode('.', explode('=', $out[0])[0])[1];
        } else {
            // Fallback: maybe it's a legacy interface like 'lan'
            $net_section = str_replace('br-', '', $bridge_name);
        }
        
        if ($net_section) {
            exec("uci delete network.$net_section.ports");
            foreach ($new_ports as $port) {
                exec("uci add_list network.$net_section.ports='$port'");
            }
        }
        
        // 2. Update Wireless Config (Attach SSIDs to this Bridge)
        // First, detach ANY wifi that was attached to this bridge but NOT in the new selection?
        // Or simply: Iterate through all selected SSIDs and set `option network '$bridge_name'` (or the interface name)
        
        // The bridge name in `wireless` option network should be the INTERFACE name, not device name.
        // If we created a device `br-hotspot`, we likely have an interface `hotspot` that uses it?
        // Or does `wireless` attach to the *device* directly? No, it attaches to the *interface* (logical).
        
        // Assumption: The user created a "Hotspot Network" in Hotspot Manager. 
        // That created an interface (e.g. `hotspot_vlan10`) which uses device `br-hotspot_vlan10` (if bridged).
        // If we are editing the BRIDGE `br-hotspot_vlan10`, we need to find the INTERFACE that uses it.
        
        // Find interface using this device
        exec("uci show network | grep \".device='$bridge_name'\"", $net_check);
        $target_interface = "";
        if (!empty($net_check)) {
             // network.hotspot_vlan10.device='br-hotspot_vlan10'
             $target_interface = explode('.', explode('=', $net_check[0])[0])[1];
        } else {
            // Maybe the bridge name IS the interface name (legacy 'lan')
            if ($bridge_name == 'br-lan') $target_interface = 'lan';
            else $target_interface = $bridge_name; // fallback
        }
        
        foreach ($wireless_attach as $wifi_section) {
            exec("uci set wireless.$wifi_section.network='$target_interface'");
        }
        
        exec("uci commit network");
        exec("uci commit wireless");
        exec("/etc/init.d/network reload");
        exec("wifi reload");
        $msg = "Bridge '$bridge_name' updated with ports and WiFi.";
    }

// ...

// Get Wireless SSIDs for the form
$ssids = [];
exec("uci show wireless", $w_out);
foreach ($w_out as $line) {
    if (preg_match("/wireless\.(@wifi-iface\[\d+\])\.ssid='(.+)'/", $line, $m)) {
        $ssids[$m[1]] = $m[2];
    }
}
?>

<!-- Update Edit Form -->
<div id="editForm" class="card" style="display:none; border: 1px solid #007bff;">
    <h3>Edit Bridge: <span id="edit_bridge_name_display"></span></h3>
    <form method="post">
        <input type="hidden" name="bridge_name_edit" id="edit_bridge_name">
        <input type="hidden" name="update_bridge" value="1">
        
        <div style="display:flex; gap:20px;">
            <div style="flex:1;">
                <label>Physical Ports</label>
                <select name="ports_edit[]" id="edit_ports" multiple style="height: 150px; width:100%;">
                    <?php foreach ($interfaces as $iface): ?>
                        <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;">
                <label>Attach WiFi SSIDs</label>
                <select name="wifi_attach[]" id="edit_wifi" multiple style="height: 150px; width:100%;">
                    <?php foreach ($ssids as $sec => $ssid): ?>
                        <option value="<?php echo $sec; ?>"><?php echo $ssid; ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="font-size:0.8em; color:#666;">Select SSIDs to bridge to this network.</p>
            </div>
        </div>
        
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
