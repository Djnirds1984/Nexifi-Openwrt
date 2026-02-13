<?php include 'header.php'; ?>
<?php
$msg = '';

// Helper to get radios
function getRadios() {
    $radios = [];
    exec("uci show wireless | grep '=wifi-device'", $output);
    foreach ($output as $line) {
        $parts = explode('.', explode('=', $line)[0]);
        if (isset($parts[1])) {
            $radios[] = $parts[1];
        }
    }
    return $radios;
}

// Helper to get Networks
function getNetworks() {
    $networks = [];
    exec("uci show network", $output);
    foreach ($output as $line) {
        if (preg_match('/^network\.(\w+)=interface/', $line, $m)) {
            if ($m[1] != 'loopback') {
                $networks[] = $m[1];
            }
        }
    }
    return $networks;
}

// Create/Update SSID Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_ssid'])) {
        $ssid = escapeshellarg($_POST['ssid']);
        $radio = escapeshellarg($_POST['radio']);
        $encryption = escapeshellarg($_POST['encryption']); // none, psk2
        $key = escapeshellarg($_POST['key']);
        
        // Add wifi-iface without network attachment (user will bridge it later)
        exec("uci add wireless wifi-iface > /tmp/new_iface_id");
        $id = trim(file_get_contents('/tmp/new_iface_id'));
        exec("uci set wireless.$id.device=$radio");
        exec("uci set wireless.$id.mode='ap'");
        exec("uci set wireless.$id.ssid=$ssid");
        // exec("uci set wireless.$id.network=$network"); // REMOVED: Managed via Bridge
        exec("uci set wireless.$id.encryption=$encryption");
        if ($_POST['encryption'] !== 'none') {
            exec("uci set wireless.$id.key=$key");
        }
        
        // Ensure radio is enabled
        exec("uci set wireless.$radio.disabled='0'");
        exec("uci set wireless.$radio.country='PH'");
        
        exec("uci commit wireless");
        exec("wifi reload");
        $msg = "SSID '$ssid' created successfully.";
    }
    
    if (isset($_POST['delete_ssid'])) {
        $section = $_POST['delete_ssid'];
        exec("uci delete wireless.$section");
        exec("uci commit wireless");
        exec("wifi reload");
        $msg = "SSID deleted.";
    }
}

$radios = getRadios();
$networks = getNetworks();

// List Wireless Interfaces
$wifis = [];
exec("uci show wireless", $wifi_out);
$wifi_map = [];
foreach ($wifi_out as $line) {
    if (preg_match("/wireless\.(@wifi-iface\[\d+\])\.(\w+)='?(.*?)'?$/", $line, $m)) {
        $wifi_map[$m[1]][$m[2]] = $m[3];
        $wifi_map[$m[1]]['section'] = $m[1];
    }
}
foreach ($wifi_map as $w) {
    if (isset($w['mode']) && $w['mode'] == 'ap') {
        $wifis[] = $w;
    }
}
?>

<div class="card">
    <h3>Wireless Settings (SSID Manager)</h3>
    <?php if ($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>
    
    <form method="post">
        <label>SSID Name</label>
        <input type="text" name="ssid" required placeholder="Ex: MyWiFi">
        
        <label>Radio Device</label>
        <select name="radio" style="width:100%; padding: 10px; margin-bottom: 15px;">
            <?php foreach ($radios as $r): ?>
                <option value="<?php echo $r; ?>"><?php echo $r; ?></option>
            <?php endforeach; ?>
        </select>
        
        <!-- Network Attachment Removed - Use Bridge Settings -->
        
        <label>Security</label>
        <select name="encryption" id="enc_type" onchange="toggleKey()" style="width:100%; padding: 10px; margin-bottom: 15px;">
            <option value="none">Open (No Password)</option>
            <option value="psk2">WPA2-PSK (Password Protected)</option>
        </select>
        
        <div id="key_field" style="display:none;">
            <label>Password</label>
            <input type="password" name="key" placeholder="Min 8 characters">
        </div>
        
        <button type="submit" name="add_ssid" class="btn btn-primary">Create SSID</button>
    </form>
</div>

<div class="card">
    <h3>Active Access Points</h3>
    <table>
        <thead>
            <tr>
                <th>SSID</th>
                <th>Radio</th>
                <th>Network</th>
                <th>Encryption</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($wifis)): ?>
                <tr><td colspan="5" style="text-align:center;">No SSIDs configured</td></tr>
            <?php else: ?>
                <?php foreach ($wifis as $w): ?>
                <tr>
                    <td><?php echo isset($w['ssid']) ? $w['ssid'] : '-'; ?></td>
                    <td><?php echo isset($w['device']) ? $w['device'] : '-'; ?></td>
                    <td><?php echo isset($w['network']) ? $w['network'] : '-'; ?></td>
                    <td><?php echo isset($w['encryption']) ? $w['encryption'] : 'none'; ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete this SSID?');">
                            <input type="hidden" name="delete_ssid" value="<?php echo $w['section']; ?>">
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
function toggleKey() {
    var enc = document.getElementById('enc_type').value;
    document.getElementById('key_field').style.display = (enc === 'none') ? 'none' : 'block';
}
</script>

<?php include 'footer.php'; ?>
