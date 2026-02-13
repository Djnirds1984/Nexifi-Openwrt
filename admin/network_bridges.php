<?php include 'header.php'; ?>

<?php
$msg = '';

// --- Helper Functions ---

// Get all physical interfaces (excluding lo)
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

// Get all WiFi SSIDs and their config sections
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

// Get all configured bridges
function getBridges() {
    $bridges = [];
    exec("uci show network", $lines);
    
    // 1. Find all devices of type 'bridge'
    $devices = [];
    foreach ($lines as $line) {
        // network.@device[0].type='bridge'
        // network.@device[0].name='br-lan'
        if (preg_match("/network\.([^.]+)\.type='bridge'/", $line, $m)) {
            $section = $m[1];
            $devices[$section] = ['section' => $section, 'ports' => []];
        }
    }

    // 2. Get details for each bridge device
    foreach ($devices as $section => &$info) {
        // Get Name
        $name_cmd = "uci get network.$section.name 2>/dev/null";
        $name = trim(shell_exec($name_cmd));
        if (empty($name)) continue;
        $info['name'] = $name;

        // Get Ports (list)
        $ports_cmd = "uci get network.$section.ports 2>/dev/null";
        $ports_out = trim(shell_exec($ports_cmd));
        if (!empty($ports_out)) {
            $info['ports'] = explode(' ', $ports_out);
        }
    }
    unset($info);

    // 3. Find Logical Interfaces that use these bridges (or are the bridge itself in legacy configs)
    // Legacy: interface 'lan' has type 'bridge' (old OpenWrt) -> we focus on 'device' config for modern OpenWrt (DSA)
    // But we also need to know which Logical Interface uses this device to map WiFi.
    
    // Map Device Name -> Logical Interface
    $deviceToInterface = [];
    foreach ($lines as $line) {
        // network.lan.device='br-lan'
        if (preg_match("/network\.([^.]+)\.device='(.+)'/", $line, $m)) {
            $interface = $m[1];
            $device = $m[2];
            // Ignore if it's a device definition itself
            if (strpos($interface, '@device') === false) {
                $deviceToInterface[$device] = $interface;
            }
        }
    }

    // 4. Find WiFi attached to these networks
    $wifi_map = []; // interface_name => [ssid_name]
    $wifi_sec_map = []; // interface_name => [section1, section2]
    
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
    
    // 1. ADD BRIDGE
    if (isset($_POST['add_bridge'])) {
        $bridge_name = trim($_POST['bridge_name']);
        if (!empty($bridge_name)) {
            // Ensure br- prefix
            if (strpos($bridge_name, 'br-') !== 0) {
                $bridge_name = 'br-' . $bridge_name;
            }

            // Create Device Section
            exec("uci add network device > /tmp/new_dev_id");
            $id = trim(file_get_contents('/tmp/new_dev_id'));
            exec("uci set network.$id.name='$bridge_name'");
            exec("uci set network.$id.type='bridge'");
            
            // Add Ports
            if (isset($_POST['ports']) && is_array($_POST['ports'])) {
                foreach ($_POST['ports'] as $port) {
                    exec("uci add_list network.$id.ports='$port'");
                }
            }

            // Create Logical Interface for this bridge (so we can attach wifi/dhcp later)
            // Name it same as bridge suffix e.g. br-test -> test
            $iface_name = str_replace('br-', '', $bridge_name);
            exec("uci set network.$iface_name=interface");
            exec("uci set network.$iface_name.proto='static'"); // Default to static, user can change later
            exec("uci set network.$iface_name.device='$bridge_name'");
            // Assign a dummy IP to prevent errors? Or leave unconfigured? 
            // Better to let user configure IP in standard network page.
            exec("uci set network.$iface_name.ipaddr='192.168.1" . rand(10,99) . ".1'");
            exec("uci set network.$iface_name.netmask='255.255.255.0'");

            exec("uci commit network");
            exec("/etc/init.d/network reload");
            $msg = "Bridge $bridge_name created with interface $iface_name.";
        }
    }

    // 2. DELETE BRIDGE
    if (isset($_POST['delete_bridge'])) {
        $del_name = $_POST['delete_bridge_name'];
        
        // Find device section
        exec("uci show network | grep \".name='$del_name'\"", $out);
        if (!empty($out)) {
            // Delete Device
            $dev_section = explode('.', explode('=', $out[0])[0])[1];
            exec("uci delete network.$dev_section");
            
            // Delete Logical Interface that uses this device
            // Find interface where device='$del_name'
            exec("uci show network | grep \".device='$del_name'\"", $out_iface);
            if (!empty($out_iface)) {
                 $iface_section = explode('.', explode('=', $out_iface[0])[0])[1];
                 exec("uci delete network.$iface_section");
            }

            exec("uci commit network");
            exec("/etc/init.d/network reload");
            $msg = "Bridge $del_name and associated interface deleted.";
        }
    }

    // 3. UPDATE BRIDGE
    if (isset($_POST['update_bridge'])) {
        $bridge_name = $_POST['edit_bridge_name_orig'];
        $new_ports = isset($_POST['edit_ports']) ? $_POST['edit_ports'] : [];
        $new_wifi_sections = isset($_POST['edit_wifi']) ? $_POST['edit_wifi'] : [];

        // Update Ports
        exec("uci show network | grep \".name='$bridge_name'\"", $out);
        if (!empty($out)) {
            $dev_section = explode('.', explode('=', $out[0])[0])[1];
            // Clear ports
            exec("uci delete network.$dev_section.ports");
            // Add new ports
            foreach ($new_ports as $p) {
                exec("uci add_list network.$dev_section.ports='$p'");
            }
        }

        // Update WiFi
        // Find the logical interface for this bridge
        exec("uci show network | grep \".device='$bridge_name'\"", $out_iface);
        $logic_iface = "";
        if (!empty($out_iface)) {
             $logic_iface = explode('.', explode('=', $out_iface[0])[0])[1];
        }

        if ($logic_iface) {
            // For selected SSIDs, set their network to this interface
            foreach ($new_wifi_sections as $sec) {
                exec("uci set wireless.$sec.network='$logic_iface'");
            }
            // For UNSELECTED SSIDs that currently point to this interface? 
            // Hard to track without more state. 
            // Current implementation only ADDS/MOVES wifi to this bridge.
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

<div class="container mt-4">
    <div class="row mb-3">
        <div class="col-md-8">
            <h2>Network Bridges</h2>
            <p class="text-muted">Manage network bridges (br-lan, br-wan, etc.) and attach physical ports or WiFi networks.</p>
        </div>
        <div class="col-md-4 text-right">
            <button class="btn btn-success" data-toggle="modal" data-target="#addBridgeModal">
                <i class="fa fa-plus"></i> Add New Bridge
            </button>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Bridge Device</th>
                        <th>Logical Interface</th>
                        <th>Physical Ports</th>
                        <th>Attached WiFi</th>
                        <th width="150">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bridges)): ?>
                        <tr><td colspan="5" class="text-center">No bridges found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bridges as $b): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($b['name']); ?></strong></td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($b['interface']); ?></span></td>
                                <td>
                                    <?php foreach ($b['ports'] as $p): ?>
                                        <span class="badge badge-secondary"><?php echo htmlspecialchars($p); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php foreach ($b['wifi'] as $w): ?>
                                        <span class="badge badge-warning"><i class="fa fa-wifi"></i> <?php echo htmlspecialchars($w); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick='openEditModal(<?php echo json_encode($b); ?>)'>
                                        <i class="fa fa-edit"></i>
                                    </button>
                                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Are you sure? This will delete the bridge and its interface.');">
                                        <input type="hidden" name="delete_bridge_name" value="<?php echo htmlspecialchars($b['name']); ?>">
                                        <button type="submit" name="delete_bridge" class="btn btn-sm btn-danger">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Bridge Modal -->
<div class="modal fade" id="addBridgeModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
          <div class="modal-header">
            <h5 class="modal-title">Create New Bridge</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <div class="form-group">
                <label>Bridge Name</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text">br-</span>
                    </div>
                    <input type="text" name="bridge_name" class="form-control" placeholder="custom" required pattern="[a-zA-Z0-9_]+">
                </div>
                <small class="form-text text-muted">A new interface will also be created.</small>
            </div>
            <div class="form-group">
                <label>Physical Ports</label>
                <select name="ports[]" class="form-control" multiple style="height: 120px;">
                    <?php foreach ($phy_interfaces as $iface): ?>
                        <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">Hold Ctrl to select multiple.</small>
            </div>
            <input type="hidden" name="add_bridge" value="1">
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary">Create Bridge</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Bridge Modal -->
<div class="modal fade" id="editBridgeModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
          <div class="modal-header">
            <h5 class="modal-title">Edit Bridge: <span id="edit_title"></span></h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="edit_bridge_name_orig" id="edit_bridge_name_orig">
            
            <div class="form-group">
                <label>Physical Ports</label>
                <select name="edit_ports[]" id="edit_ports" class="form-control" multiple style="height: 120px;">
                    <?php foreach ($phy_interfaces as $iface): ?>
                        <option value="<?php echo $iface; ?>"><?php echo $iface; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Attach WiFi Networks (SSIDs)</label>
                <select name="edit_wifi[]" id="edit_wifi" class="form-control" multiple style="height: 120px;">
                    <?php foreach ($wifi_ssids as $sec => $ssid): ?>
                        <option value="<?php echo $sec; ?>"><?php echo $ssid; ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">Selected SSIDs will be bridged to this network.</small>
            </div>

            <input type="hidden" name="update_bridge" value="1">
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditModal(bridge) {
    $('#edit_title').text(bridge.name);
    $('#edit_bridge_name_orig').val(bridge.name);
    
    // Reset selections
    $('#edit_ports option').prop('selected', false);
    $('#edit_wifi option').prop('selected', false);
    
    // Select Ports
    if (bridge.ports && Array.isArray(bridge.ports)) {
        bridge.ports.forEach(function(port) {
            $('#edit_ports option[value="'+port+'"]').prop('selected', true);
        });
    }

    // Select WiFi Sections
    if (bridge.wifi_sections && Array.isArray(bridge.wifi_sections)) {
        bridge.wifi_sections.forEach(function(sec) {
            $('#edit_wifi option[value="'+sec+'"]').prop('selected', true);
        });
    }
}

// Re-open modal if needed or handle UI interactions
</script>

<?php include 'footer.php'; ?>
