<?php include 'header.php'; ?>
<?php
$msg = '';

// Helper to get available interfaces (physical + VLANs)
function getNetworkDevices() {
    $devices = [];
    
    // 1. Physical Interfaces
    $raw = glob('/sys/class/net/*');
    foreach ($raw as $iface_path) {
        $iface = basename($iface_path);
        if ($iface != 'lo' && strpos($iface, 'wlan') === false) {
            $devices[] = $iface; // eth0, eth1, br-lan, etc.
        }
    }
    
    // 2. VLAN Interfaces (from UCI)
    exec("uci show network", $uci_out);
    foreach ($uci_out as $line) {
        // network.@device[X].name='eth1.10'
        if (preg_match("/network\.@device\[\d+\]\.name='(.+)'/", $line, $m)) {
            $devName = $m[1];
            // Only add if not already in physical list (VLANs like eth1.10 usually appear in /sys/class/net only if active)
            if (!in_array($devName, $devices)) {
                $devices[] = $devName;
            }
        }
    }
    
    return array_unique($devices);
}

// Helper to get wireless radios
function getRadios() {
    $radios = [];
    exec("uci show wireless | grep '=wifi-device'", $output);
    foreach ($output as $line) {
        // wireless.radio0=wifi-device
        $parts = explode('.', explode('=', $line)[0]);
        if (isset($parts[1])) {
            $radios[] = $parts[1];
        }
    }
    return $radios;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_hotspot'])) {
        $type = $_POST['interface_type']; // 'wireless' or 'wired'
        $ip = escapeshellarg($_POST['ip']);
        $mask = escapeshellarg($_POST['netmask']);
        
        $name = "hotspot_" . time(); // Unique ID for network interface
        
        // --- 1. Network Interface Configuration ---
        exec("uci set network.$name=interface");
        exec("uci set network.$name.proto='static'");
        exec("uci set network.$name.ipaddr=$ip");
        exec("uci set network.$name.netmask=$mask");
        
        if ($type === 'wired') {
            $device = escapeshellarg($_POST['wired_device']);
            // Check if device is a VLAN or Physical
            exec("uci set network.$name.device=$device");
            
            // For wired, we don't need wireless config, but we need to ensure the interface is up
            $ssid_display = "Wired ($device)";
        } else {
            // Wireless
            $ssid = escapeshellarg($_POST['ssid']);
            $radio = escapeshellarg($_POST['radio']);
            $ssid_display = $_POST['ssid'];
            
            // Wireless config attached to this network
            exec("uci add wireless wifi-iface > /tmp/new_iface_id");
            $id = trim(file_get_contents('/tmp/new_iface_id'));
            exec("uci set wireless.$id.device=$radio");
            exec("uci set wireless.$id.mode='ap'");
            exec("uci set wireless.$id.ssid=$ssid");
            exec("uci set wireless.$id.network='$name'");
            exec("uci set wireless.$id.encryption='none'");
        }
        
        // --- 2. DHCP Configuration ---
        exec("uci set dhcp.$name=dhcp");
        exec("uci set dhcp.$name.interface='$name'");
        exec("uci set dhcp.$name.start='100'");
        exec("uci set dhcp.$name.limit='150'");
        exec("uci set dhcp.$name.leasetime='1h'");
        exec("uci set dhcp.$name.force='1'");
        
        // --- 3. Firewall Configuration ---
        // Add to 'pisowifi' zone
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
            // Find existing pisowifi zone and add network to it
            // We use a robust search loop
            exec("uci show firewall", $fw_out);
            $piso_zone_key = "";
            foreach($fw_out as $line) {
                if (strpos($line, ".name='pisowifi'") !== false) {
                    // firewall.@zone[1].name='pisowifi' -> extract firewall.@zone[1]
                    $parts = explode('.', $line);
                    $piso_zone_key = $parts[0] . '.' . $parts[1];
                    break;
                }
            }
            if ($piso_zone_key) {
                exec("uci add_list $piso_zone_key.network='$name'");
            }
        }
        
        exec("uci commit network");
        exec("uci commit dhcp");
        exec("uci commit wireless");
        exec("uci commit firewall");
        
        exec("/etc/init.d/network reload");
        exec("/etc/init.d/firewall reload");
        if ($type === 'wireless') {
            exec("wifi reload");
        }
        
        $msg = "Hotspot Server '$ssid_display' created successfully!";
    }
}

$net_devices = getNetworkDevices();
$radios = getRadios();
?>

<div class="card">
    <h3>Create Hotspot Server</h3>
    <?php if ($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>
    
    <form method="post" id="hotspotForm">
        <label>Interface Type</label>
        <select name="interface_type" id="interface_type" onchange="toggleFields()" required style="width:100%; padding: 10px; margin-bottom: 15px;">
            <option value="wireless">Wireless (WiFi AP)</option>
            <option value="wired">Wired / VLAN (LAN, USB-LAN)</option>
        </select>
        
        <!-- Wireless Fields -->
        <div id="wireless_fields">
            <label>WiFi Name (SSID)</label>
            <input type="text" name="ssid" placeholder="Ex: Pisowifi_Hotspot">
            
            <label>Wireless Radio</label>
            <select name="radio" style="width:100%; padding: 10px; margin-bottom: 15px;">
                <?php foreach ($radios as $r): ?>
                    <option value="<?php echo $r; ?>"><?php echo $r; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Wired Fields -->
        <div id="wired_fields" style="display:none;">
            <label>Network Interface</label>
            <select name="wired_device" style="width:100%; padding: 10px; margin-bottom: 15px;">
                <?php foreach ($net_devices as $dev): ?>
                    <option value="<?php echo $dev; ?>"><?php echo $dev; ?></option>
                <?php endforeach; ?>
            </select>
            <p style="font-size:0.8em; color:#666; margin-top:-10px;">Select physical port (e.g. eth1) or VLAN (e.g. eth1.10). Create VLANs in 'VLAN Settings' first.</p>
        </div>
        
        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
        <h4>Network Settings (Gateway)</h4>
        
        <label>Gateway IP Address</label>
        <input type="text" name="ip" placeholder="Ex: 10.0.20.1" required>
        
        <label>Subnet Mask</label>
        <select name="netmask" style="width:100%; padding: 10px; margin-bottom: 15px;">
            <option value="255.255.255.0">/24 (255.255.255.0) - 254 IPs</option>
            <option value="255.255.254.0">/23 (255.255.254.0) - 510 IPs</option>
            <option value="255.255.252.0">/22 (255.255.252.0) - 1022 IPs</option>
            <option value="255.255.248.0">/21 (255.255.248.0) - 2046 IPs</option>
            <option value="255.0.0.0">/8 (255.0.0.0) - 16M IPs</option>
        </select>
        
        <button type="submit" name="add_hotspot" class="btn btn-primary">Create Hotspot Server</button>
    </form>
</div>

<script>
function toggleFields() {
    var type = document.getElementById('interface_type').value;
    if (type === 'wired') {
        document.getElementById('wired_fields').style.display = 'block';
        document.getElementById('wireless_fields').style.display = 'none';
    } else {
        document.getElementById('wired_fields').style.display = 'none';
        document.getElementById('wireless_fields').style.display = 'block';
    }
}
</script>

<?php include 'footer.php'; ?>
