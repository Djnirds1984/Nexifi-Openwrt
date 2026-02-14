<?php include 'header.php'; ?>
<?php
$msg = '';

// Helper to get available interfaces (physical + VLANs)
function getAvailableInterfaces() {
    $interfaces = [];
    
    // 1. Physical Interfaces
    $raw = glob('/sys/class/net/*');
    if ($raw) {
        foreach ($raw as $iface_path) {
            $iface = basename($iface_path);
            if ($iface != 'lo' && strpos($iface, 'wlan') === false) {
                $interfaces[] = $iface; 
            }
        }
    }
    
    // 2. VLAN Devices from UCI
    exec("uci show network", $uci_out);
    foreach ($uci_out as $line) {
        // network.@device[X].name='eth1.10'
        if (preg_match("/network\.@device\[\d+\]\.name=['\"]?(.+?)['\"]?$/", $line, $m)) {
            $devName = $m[1];
            if (!in_array($devName, $interfaces)) {
                $interfaces[] = $devName;
            }
        }
        // Also check if there are any devices defined by name directly (less common but possible)
        if (preg_match("/network\.([^.]+)\.name=['\"]?(.+?)['\"]?$/", $line, $m)) {
             $devName = $m[2];
             // Simple filter for common VLAN patterns or explicit bridges
             if ((strpos($devName, '.') !== false || strpos($devName, 'br-') !== false) && !in_array($devName, $interfaces)) {
                 $interfaces[] = $devName;
             }
        }
    }
    
    return array_unique($interfaces);
}

// Get Existing Hotspots
function getHotspots() {
    $hotspots = [];
    exec("uci show network", $net_out);
    exec("uci show dhcp", $dhcp_out);
    
    // Find interfaces that look like hotspots (have associated DHCP and are static IP)
    // Heuristic: If it has 'proto=static' and is in DHCP config
    
    $interfaces = [];
    foreach ($net_out as $line) {
        if (preg_match('/^network\.(\w+)=interface/', $line, $m)) {
            if ($m[1] != 'loopback' && $m[1] != 'lan' && $m[1] != 'wan') {
                $interfaces[$m[1]] = ['id' => $m[1]];
            }
        }
    }
    
    foreach ($interfaces as $id => &$data) {
        // Get IP
        $ip_cmd = "uci get network.$id.ipaddr 2>/dev/null";
        $data['ip'] = trim((string)shell_exec($ip_cmd));
        
        // Get Device
        $dev_cmd = "uci get network.$id.device 2>/dev/null";
        $data['device'] = trim((string)shell_exec($dev_cmd));
        if (!$data['device']) {
             // Try ifname
             $ifname_cmd = "uci get network.$id.ifname 2>/dev/null";
             $data['device'] = trim((string)shell_exec($ifname_cmd));
        }

        // Check DHCP
        $dhcp_check = "uci get dhcp.$id 2>/dev/null";
        $dhcp_res = trim((string)shell_exec($dhcp_check));
        
        if ($data['ip'] && $dhcp_res == 'dhcp') {
            $hotspots[] = $data;
        }
    }
    
    return $hotspots;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_hotspot'])) {
        $interface_dev = $_POST['interface_dev']; // e.g., eth0.10
        $ip = $_POST['ip'];
        $netmask = $_POST['netmask'];
        
        // Create a logical interface name based on the device
        // e.g. hotspot_eth0_10
        $clean_dev = preg_replace('/[^a-zA-Z0-9]/', '_', $interface_dev);
        $name = "hotspot_" . $clean_dev;
        
        // Check if already exists
        $check = [];
        $return_code = 0;
        exec("uci -q get network.$name", $check, $return_code);
        if ($return_code === 0) {
            $msg = "Error: Hotspot for this interface already exists ($name).";
        } else {
            // 1. Create Network Interface
            exec("uci set network.$name=interface");
            exec("uci set network.$name.proto='static'");
            exec("uci set network.$name.device='$interface_dev'");
            exec("uci set network.$name.ipaddr='$ip'");
            exec("uci set network.$name.netmask='$netmask'");
            
            // 2. Create DHCP
            exec("uci set dhcp.$name=dhcp");
            exec("uci set dhcp.$name.interface='$name'");
            exec("uci set dhcp.$name.start='100'");
            exec("uci set dhcp.$name.limit='150'");
            exec("uci set dhcp.$name.leasetime='1h'");
            exec("uci set dhcp.$name.force='1'"); // authoritative
            
            // 3. Firewall (Pisowifi Zone)
            // Ensure zone exists
            exec("uci show firewall | grep \".name='pisowifi'\"", $fw_check);
            $zone_section = "";
            
            if (empty($fw_check)) {
                exec("uci add firewall zone > /tmp/new_zone_id");
                $zone_section = trim((string)file_get_contents('/tmp/new_zone_id'));
                exec("uci set firewall.$zone_section.name='pisowifi'");
                exec("uci set firewall.$zone_section.input='ACCEPT'"); // Captive portal needs input for DNS/HTTP
                exec("uci set firewall.$zone_section.output='ACCEPT'");
                exec("uci set firewall.$zone_section.forward='REJECT'"); // Control via CoovaChilli usually, or REJECT by default
                exec("uci set firewall.$zone_section.masq='1'");
                
                // Forwarding to WAN
                exec("uci add firewall forwarding > /tmp/new_fwd_id");
                $fid = trim((string)file_get_contents('/tmp/new_fwd_id'));
                exec("uci set firewall.$fid.src='pisowifi'");
                exec("uci set firewall.$fid.dest='wan'");
            } else {
                // Find existing section
                foreach ($fw_check as $line) {
                    if (preg_match("/firewall\.([^.]+)\.name='pisowifi'/", $line, $m)) {
                        $zone_section = $m[1];
                        break;
                    }
                }
            }
            
            if ($zone_section) {
                exec("uci add_list firewall.$zone_section.network='$name'");
            }

            exec("uci commit network");
            exec("uci commit dhcp");
            exec("uci commit firewall");
            
            exec("/etc/init.d/network reload");
            exec("/etc/init.d/firewall reload");
            exec("/etc/init.d/dnsmasq reload");
            
            $msg = "Hotspot Server created on $interface_dev ($ip)";
        }
    }
    
    if (isset($_POST['delete_hotspot'])) {
        $del_id = $_POST['delete_hotspot'];
        
        exec("uci delete network.$del_id");
        exec("uci delete dhcp.$del_id");
        
        // Remove from firewall zone
        exec("uci show firewall | grep \".name='pisowifi'\"", $fw_out);
        $zone_section = "";
        foreach ($fw_out as $line) {
            if (preg_match("/firewall\.([^.]+)\.name='pisowifi'/", $line, $m)) {
                 $zone_section = $m[1];
                 break;
            }
        }
        if ($zone_section) {
            exec("uci del_list firewall.$zone_section.network='$del_id'");
        }

        exec("uci commit network");
        exec("uci commit dhcp");
        exec("uci commit firewall");
        
        exec("/etc/init.d/network reload");
        exec("/etc/init.d/firewall reload");
        exec("/etc/init.d/dnsmasq reload");
        
        $msg = "Hotspot deleted.";
    }
}

$avail_interfaces = getAvailableInterfaces();
$hotspots = getHotspots();

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
            <h2>Hotspot Manager</h2>
            <p style="color: #666; margin: 5px 0 0 0;">Create and manage Hotspot Servers (DHCP/Gateway) on interfaces.</p>
        </div>
        <button onclick="toggleAddForm()" class="btn btn-primary" id="addBtn">+ Add New Hotspot</button>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-info" style="background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 4px; margin-bottom: 20px;">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <!-- Add Form -->
    <div id="addForm" class="form-card hidden">
        <h3 class="form-title">Create New Hotspot Server</h3>
        <form method="post">
            <div class="form-group">
                <label>Interface to Install Hotspot</label>
                <select name="interface_dev" class="form-control" required>
                    <?php foreach ($avail_interfaces as $iface): ?>
                        <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help-text">Select the physical port, VLAN (e.g., eth0.10), or Bridge where the hotspot will run.</div>
            </div>
            
            <div class="form-group">
                <label>Gateway IP Address</label>
                <input type="text" name="ip" class="form-control" placeholder="Ex: 10.0.10.1" required pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$">
                <div class="help-text">The router's IP address on this hotspot network.</div>
            </div>

            <div class="form-group">
                <label>Subnet Mask</label>
                <select name="netmask" class="form-control">
                    <option value="255.255.255.0">/24 (255.255.255.0) - 254 IPs</option>
                    <option value="255.255.254.0">/23 (255.255.254.0) - 510 IPs</option>
                    <option value="255.255.252.0">/22 (255.255.252.0) - 1022 IPs</option>
                    <option value="255.255.248.0">/21 (255.255.248.0) - 2046 IPs</option>
                    <option value="255.0.0.0">/8 (255.0.0.0) - 16M IPs</option>
                </select>
            </div>

            <div style="margin-top: 20px;">
                <input type="hidden" name="add_hotspot" value="1">
                <button type="submit" class="btn btn-primary">Create Hotspot</button>
                <button type="button" class="btn btn-cancel" onclick="toggleAddForm()">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Hotspots Table -->
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Hotspot Name</th>
                    <th>Interface Device</th>
                    <th>Gateway IP</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($hotspots)): ?>
                    <tr><td colspan="4" style="text-align:center; padding: 20px;">No hotspot servers configured.</td></tr>
                <?php else: ?>
                    <?php foreach ($hotspots as $h): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($h['id']); ?></strong></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($h['device']); ?></span></td>
                            <td><?php echo htmlspecialchars($h['ip']); ?></td>
                            <td>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Are you sure? This will delete the hotspot network and DHCP.');">
                                    <input type="hidden" name="delete_hotspot" value="<?php echo htmlspecialchars($h['id']); ?>">
                                    <button type="submit" name="delete_hotspot" class="action-btn btn-delete">Delete</button>
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
