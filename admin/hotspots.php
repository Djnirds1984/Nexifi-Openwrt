<?php include 'header.php'; ?>
<?php
$msg = '';

// Helper to get next available index for a UCI section type
function getNextIndex($config, $type) {
    exec("uci show $config | grep '=$type'", $output);
    return count($output);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_hotspot'])) {
        $ssid = escapeshellarg($_POST['ssid']);
        $ip = escapeshellarg($_POST['ip']);
        $mask = escapeshellarg($_POST['netmask']); // e.g., 255.255.255.0
        $vlan = isset($_POST['vlan']) ? intval($_POST['vlan']) : 0;
        $radio = escapeshellarg($_POST['radio']); // radio0 or radio1
        
        $name = "hotspot_" . time(); // Unique ID
        
        // 1. Network Interface
        exec("uci set network.$name=interface");
        exec("uci set network.$name.proto='static'");
        exec("uci set network.$name.ipaddr=$ip");
        exec("uci set network.$name.netmask=$mask");
        
        // VLAN Configuration (OpenWrt DSA style: br-lan.10) or Aliases
        if ($vlan > 0) {
            exec("uci set network.$name.device='br-lan.$vlan'");
        } else {
            // If not VLAN, it might be just a bridge or direct assignment. 
            // For simple virtual APs, we don't necessarily need a physical device in network config 
            // if we bridge it later, but standard OpenWrt practice for isolated network:
            // Just define the interface. The wifi-iface will attach to it.
        }
        
        // 2. DHCP
        exec("uci set dhcp.$name=dhcp");
        exec("uci set dhcp.$name.interface='$name'");
        exec("uci set dhcp.$name.start='100'");
        exec("uci set dhcp.$name.limit='150'");
        exec("uci set dhcp.$name.leasetime='1h'");
        exec("uci set dhcp.$name.force='1'"); // Force required for subnets
        
        // 3. Wireless
        // We use 'uci add' to create anonymous section or named
        // Let's use named for easier management if possible, but wireless usually uses anonymous
        // We'll append.
        exec("uci add wireless wifi-iface > /tmp/new_iface_id");
        $id = trim(file_get_contents('/tmp/new_iface_id'));
        exec("uci set wireless.$id.device=$radio");
        exec("uci set wireless.$id.mode='ap'");
        exec("uci set wireless.$id.ssid=$ssid");
        exec("uci set wireless.$id.network='$name'");
        exec("uci set wireless.$id.encryption='none'");
        
        // 4. Firewall
        // Add to 'pisowifi' zone (assuming it exists from setup)
        // Check if pisowifi zone exists
        exec("uci show firewall | grep 'name=.pisowifi.'", $fw_check);
        if (empty($fw_check)) {
            // Create zone if missing
            exec("uci add firewall zone > /tmp/new_zone_id");
            $zid = trim(file_get_contents('/tmp/new_zone_id'));
            exec("uci set firewall.$zid.name='pisowifi'");
            exec("uci set firewall.$zid.input='ACCEPT'");
            exec("uci set firewall.$zid.output='ACCEPT'");
            exec("uci set firewall.$zid.forward='REJECT'");
            exec("uci set firewall.$zid.masq='1'");
            exec("uci add_list firewall.$zid.network='$name'");
            
            // Allow forwarding to WAN
            exec("uci add firewall forwarding > /tmp/new_fwd_id");
            $fid = trim(file_get_contents('/tmp/new_fwd_id'));
            exec("uci set firewall.$fid.src='pisowifi'");
            exec("uci set firewall.$fid.dest='wan'");
        } else {
            // Add network to existing zone
            // Finding the named section is tricky with anonymous config.
            // We'll iterate to find the zone named 'pisowifi'
            // Simplified: We assume we can add_list.
            // A robust way:
            $zones = [];
            exec("uci show firewall", $fw_out);
            $piso_zone_key = "";
            foreach($fw_out as $line) {
                if (strpos($line, ".name='pisowifi'") !== false) {
                    $piso_zone_key = explode('.', explode('=', $line)[0])[1];
                    break;
                }
            }
            if ($piso_zone_key) {
                exec("uci add_list firewall.$piso_zone_key.network='$name'");
            }
        }
        
        exec("uci commit network");
        exec("uci commit dhcp");
        exec("uci commit wireless");
        exec("uci commit firewall");
        
        exec("/etc/init.d/network reload");
        exec("/etc/init.d/firewall reload");
        exec("wifi reload");
        
        $msg = "Hotspot '$ssid' created successfully!";
    }
}

// Get existing hotspots (scan wireless config)
$hotspots = [];
exec("uci show wireless", $w_out);
// Parsing is complex. Simplified approach: look for ssid and network.
// We'll just list interfaces that have mode='ap'
// This is a placeholder for the logic to list them.
?>

<div class="card">
    <h3>Create New Hotspot</h3>
    <?php if ($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>
    
    <form method="post">
        <label>SSID Name</label>
        <input type="text" name="ssid" placeholder="Ex: Pisowifi_VLAN10" required>
        
        <label>Wireless Radio</label>
        <select name="radio" style="width:100%; padding: 10px; margin-bottom: 15px;">
            <option value="radio0">Radio 0 (2.4GHz/5GHz)</option>
            <option value="radio1">Radio 1 (if available)</option>
        </select>
        
        <label>Gateway IP Address</label>
        <input type="text" name="ip" placeholder="Ex: 10.0.10.1" required>
        
        <label>Subnet Mask</label>
        <select name="netmask" style="width:100%; padding: 10px; margin-bottom: 15px;">
            <option value="255.255.255.0">/24 (255.255.255.0) - 254 IPs</option>
            <option value="255.255.254.0">/23 (255.255.254.0) - 510 IPs</option>
            <option value="255.255.252.0">/22 (255.255.252.0) - 1022 IPs</option>
            <option value="255.255.248.0">/21 (255.255.248.0) - 2046 IPs</option>
            <option value="255.0.0.0">/8 (255.0.0.0) - 16M IPs</option>
        </select>
        
        <label>VLAN ID (Optional)</label>
        <input type="number" name="vlan" placeholder="Ex: 10 (Leave empty for none)">
        
        <button type="submit" name="add_hotspot" class="btn btn-primary">Create Hotspot</button>
    </form>
</div>

<div class="card">
    <h3>Existing Hotspots</h3>
    <p><i>List of current configured APs (Coming soon)</i></p>
</div>

<?php include 'footer.php'; ?>
