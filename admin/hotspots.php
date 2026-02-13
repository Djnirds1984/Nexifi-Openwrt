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
        
        if ($type === 'wired') {
            $ip = escapeshellarg($_POST['ip']);
            $mask = escapeshellarg($_POST['netmask']);
            $name = "hotspot_" . time(); // Unique ID for network interface
            
            // --- 1. Network Interface Configuration (Wired Only) ---
            exec("uci set network.$name=interface");
            exec("uci set network.$name.proto='static'");
            exec("uci set network.$name.ipaddr=$ip");
            exec("uci set network.$name.netmask=$mask");
            
            $device = escapeshellarg($_POST['wired_device']);
            exec("uci set network.$name.device=$device");
            $ssid_display = "Wired ($device)";
            
            // --- 2. DHCP Configuration (Wired Only) ---
            exec("uci set dhcp.$name=dhcp");
            exec("uci set dhcp.$name.interface='$name'");
            exec("uci set dhcp.$name.start='100'");
            exec("uci set dhcp.$name.limit='150'");
            exec("uci set dhcp.$name.leasetime='1h'");
            exec("uci set dhcp.$name.force='1'");
            
            // --- 3. Firewall Configuration (Wired Only) ---
            // Add to 'pisowifi' zone (assuming logic similar to below)
            // ... (Reuse firewall logic if needed or assume user handles it for bridges?)
            // User requested "setup ng ssid sa wifi, hindi na kailangan ito ng ip"
            // So wired logic can remain full stack if user wants, or we can leave it.
            // Let's assume wired logic stays "Full Hotspot" for now unless asked.
            // BUT wait, I should reuse the firewall logic block below but make it conditional?
            // Let's copy the firewall logic here for wired to be safe.
            
            exec("uci show firewall | grep 'name=.pisowifi.'", $fw_check);
            if (empty($fw_check)) {
                exec("uci add firewall zone > /tmp/new_zone_id");
                $zid = trim(file_get_contents('/tmp/new_zone_id'));
                exec("uci set firewall.$zid.name='pisowifi'");
                exec("uci set firewall.$zid.input='ACCEPT'");
                exec("uci set firewall.$zid.output='ACCEPT'");
                exec("uci set firewall.$zid.forward='REJECT'");
                exec("uci set firewall.$zid.masq='1'");
                exec("uci add_list firewall.$zid.network='$name'");
                exec("uci add firewall forwarding > /tmp/new_fwd_id");
                $fid = trim(file_get_contents('/tmp/new_fwd_id'));
                exec("uci set firewall.$fid.src='pisowifi'");
                exec("uci set firewall.$fid.dest='wan'");
            } else {
                exec("uci show firewall", $fw_out);
                $piso_zone_key = "";
                foreach($fw_out as $line) {
                    if (strpos($line, ".name='pisowifi'") !== false) {
                        $parts = explode('.', $line);
                        $piso_zone_key = $parts[0] . '.' . $parts[1];
                        break;
                    }
                }
                if ($piso_zone_key) {
                    exec("uci add_list $piso_zone_key.network='$name'");
                }
            }
            
        } else {
            // Wireless - Just Attach SSID to Network (Bridge)
            $ssid = escapeshellarg($_POST['ssid']);
            $radio_selection = $_POST['radio']; 
            $network_attach = escapeshellarg($_POST['network_attach']); // New Field
            $ssid_display = $_POST['ssid'];
            
            // Function to add wifi-iface
            function addWifiIface($radio_dev, $ssid_val, $network_name) {
                exec("uci set wireless.$radio_dev.disabled='0'");
                exec("uci set wireless.$radio_dev.country='PH'");
                
                exec("uci add wireless wifi-iface > /tmp/new_iface_id");
                $id = trim(file_get_contents('/tmp/new_iface_id'));
                exec("uci set wireless.$id.device=$radio_dev");
                exec("uci set wireless.$id.mode='ap'");
                exec("uci set wireless.$id.ssid=$ssid_val");
                exec("uci set wireless.$id.network=$network_name"); // Point to existing network
                exec("uci set wireless.$id.encryption='none'");
            }
            
            if ($radio_selection === 'dual_band') {
                addWifiIface('radio0', $ssid, $network_attach);
                addWifiIface('radio1', $ssid, $network_attach);
                $ssid_display .= " (Dual Band)";
            } else {
                addWifiIface($radio_selection, $ssid, $network_attach);
            }
            // NO DHCP/IP/Firewall setup here - User handles it on the Bridge/Network
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
    
    // Delete Hotspot Logic
    if (isset($_POST['delete_hotspot'])) {
        $del_id = $_POST['delete_hotspot']; // Can be 'hotspot_123' (network) or 'cfg050f0' (wifi-iface)
        $del_type = $_POST['delete_type']; // 'managed' or 'unmanaged'
        
        if ($del_type === 'managed') {
            // Full Cleanup
            exec("uci delete network.$del_id");
            exec("uci delete dhcp.$del_id");
            
            // Delete linked wifi-ifaces
            exec("uci show wireless | grep \".network='$del_id'\"", $w_out);
            foreach ($w_out as $line) {
                if (preg_match("/wireless\.(@wifi-iface\[\d+\])\./", $line, $m)) {
                    exec("uci delete wireless." . $m[1]);
                }
            }
            
            // Delete Firewall Rule
            exec("uci show firewall | grep \".name='pisowifi'\"", $fw_out);
            $piso_zone_key = "";
            foreach($fw_out as $line) {
                if (strpos($line, ".name='pisowifi'") !== false) {
                    $parts = explode('.', $line);
                    $piso_zone_key = $parts[0] . '.' . $parts[1];
                    break;
                }
            }
            if ($piso_zone_key) {
                exec("uci del_list $piso_zone_key.network='$del_id'");
            }
            
        } elseif ($del_type === 'unmanaged_wifi') {
            // Just delete the wifi-iface
            exec("uci delete wireless.$del_id");
        }
        
        exec("uci commit network");
        exec("uci commit dhcp");
        exec("uci commit wireless");
        exec("uci commit firewall");
        
        exec("/etc/init.d/network reload");
        exec("/etc/init.d/firewall reload");
        exec("wifi reload");
        
        $msg = "Hotspot deleted successfully.";
    }
}

function getNetworks() {
    $networks = [];
    exec("uci show network", $output);
    foreach ($output as $line) {
        // network.lan=interface
        if (preg_match('/^network\.(\w+)=interface/', $line, $m)) {
            if ($m[1] != 'loopback') {
                $networks[] = $m[1];
            }
        }
    }
    return $networks;
}

$net_devices = getNetworkDevices();
$radios = getRadios();
$networks = getNetworks();
?>

<div class="card">
    <h3>Create Hotspot Server</h3>
    <?php if ($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>
    
    <form method="post" id="hotspotForm">
        <label>Interface Type</label>
        <select name="interface_type" id="interface_type" onchange="toggleFields()" required style="width:100%; padding: 10px; margin-bottom: 15px;">
            <option value="wireless">Wireless (WiFi AP)</option>
            <option value="wired">Wired (Full Hotspot Setup)</option>
        </select>
        
        <!-- Wireless Fields -->
        <div id="wireless_fields">
            <label>WiFi Name (SSID)</label>
            <input type="text" name="ssid" placeholder="Ex: Pisowifi_Hotspot">
            
            <label>Wireless Radio</label>
            <select name="radio" style="width:100%; padding: 10px; margin-bottom: 15px;">
                <option value="dual_band">Dual Band (2.4GHz + 5GHz) - Band Steering</option>
                <?php foreach ($radios as $r): ?>
                    <option value="<?php echo $r; ?>"><?php echo $r; ?> (Single Band)</option>
                <?php endforeach; ?>
            </select>
            
            <label>Attach to Network (Bridge/Interface)</label>
            <select name="network_attach" style="width:100%; padding: 10px; margin-bottom: 15px;">
                <?php foreach ($networks as $net): ?>
                    <option value="<?php echo $net; ?>" <?php echo ($net == 'lan') ? 'selected' : ''; ?>><?php echo $net; ?></option>
                <?php endforeach; ?>
            </select>
            <p style="font-size:0.8em; color:#666; margin-top:-10px;">Select the network interface (e.g., 'lan' or your custom bridge) to bridge this WiFi to. IP/DHCP must be configured on that network.</p>
        </div>
        
        <!-- Wired Fields -->
        <div id="wired_fields" style="display:none;">
            <label>Network Interface</label>
            <select name="wired_device" style="width:100%; padding: 10px; margin-bottom: 15px;">
                <?php foreach ($net_devices as $dev): ?>
                    <option value="<?php echo $dev; ?>"><?php echo $dev; ?></option>
                <?php endforeach; ?>
            </select>
            <p style="font-size:0.8em; color:#666; margin-top:-10px;">Select physical port (e.g. eth1) or VLAN (e.g. eth1.10).</p>
            
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
            <h4>Network Settings (Gateway)</h4>
            
            <label>Gateway IP Address</label>
            <input type="text" name="ip" placeholder="Ex: 10.0.20.1">
            
            <label>Subnet Mask</label>
            <select name="netmask" style="width:100%; padding: 10px; margin-bottom: 15px;">
                <option value="255.255.255.0">/24 (255.255.255.0) - 254 IPs</option>
                <option value="255.255.254.0">/23 (255.255.254.0) - 510 IPs</option>
                <option value="255.255.252.0">/22 (255.255.252.0) - 1022 IPs</option>
                <option value="255.255.248.0">/21 (255.255.248.0) - 2046 IPs</option>
                <option value="255.0.0.0">/8 (255.0.0.0) - 16M IPs</option>
            </select>
        </div>
        
        <button type="submit" name="add_hotspot" class="btn btn-primary">Create Hotspot Server</button>
    </form>
</div>

<div class="card">
    <h3>Existing Hotspot Servers</h3>
    <table>
        <thead>
            <tr>
                <th>Name / SSID</th>
                <th>Type</th>
                <th>Device</th>
                <th>Gateway IP</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($hotspots)): ?>
                <tr><td colspan="5" style="text-align:center;">No hotspot servers configured</td></tr>
            <?php else: ?>
                <?php foreach ($hotspots as $h): ?>
                <tr>
                    <td><?php echo $h['ssid']; ?></td>
                    <td><?php echo ucfirst($h['type']); ?></td>
                    <td><?php echo $h['device']; ?></td>
                    <td><?php echo $h['ip']; ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Are you sure you want to delete this hotspot?');">
                            <input type="hidden" name="delete_hotspot" value="<?php echo $h['id']; ?>">
                            <input type="hidden" name="delete_type" value="<?php echo $h['managed'] ? 'managed' : 'unmanaged_wifi'; ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.8em;">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
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
