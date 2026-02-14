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

// Helper to get radio device details
function getRadioDetails() {
    $devices = [];
    exec("uci show wireless", $lines);
    foreach ($lines as $line) {
        if (preg_match("/^wireless\.([^.]+)=wifi-device$/", $line, $m)) {
            $devices[$m[1]] = ['name' => $m[1]];
        } elseif (preg_match("/^wireless\.([^.]+)\.(\w+)='?(.*?)'?$/", $line, $m)) {
            if (isset($devices[$m[1]])) {
                $devices[$m[1]][$m[2]] = $m[3];
            }
        }
    }
    return $devices;
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
        $id = trim((string)file_get_contents('/tmp/new_iface_id'));
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

    if (isset($_POST['update_ssid'])) {
        $section = $_POST['section'];
        $ssid = escapeshellarg($_POST['ssid'] ?? '');
        $encryption = $_POST['encryption'] ?? 'none';
        $hidden = isset($_POST['hidden']) ? '1' : '0';
        $isolate = isset($_POST['isolate']) ? '1' : '0';
        $key = $_POST['key'] ?? '';

        if (!empty($ssid)) {
            exec("uci set wireless.$section.ssid=$ssid");
        }
        exec("uci set wireless.$section.encryption='" . escapeshellarg($encryption) . "'");
        exec("uci set wireless.$section.hidden='$hidden'");
        exec("uci set wireless.$section.isolate='$isolate'");

        if ($encryption === 'none') {
            exec("uci -q delete wireless.$section.key");
        } else {
            exec("uci set wireless.$section.key=" . escapeshellarg($key));
        }

        exec("uci commit wireless");
        exec("wifi reload");
        $msg = "SSID updated successfully.";
    }

    if (isset($_POST['update_radio'])) {
        $radio = $_POST['radio_name'];
        $country = $_POST['country'] ?? '';
        $channel = $_POST['channel'] ?? '';
        $htmode = $_POST['htmode'] ?? '';
        $txpower = $_POST['txpower'] ?? '';
        $disabled = isset($_POST['disabled']) ? '1' : '0';

        if ($country !== '') exec("uci set wireless.$radio.country='" . escapeshellarg($country) . "'");
        if ($channel !== '') exec("uci set wireless.$radio.channel='" . escapeshellarg($channel) . "'");
        if ($htmode !== '') exec("uci set wireless.$radio.htmode='" . escapeshellarg($htmode) . "'");
        if ($txpower !== '') exec("uci set wireless.$radio.txpower='" . escapeshellarg($txpower) . "'");
        exec("uci set wireless.$radio.disabled='$disabled'");

        exec("uci commit wireless");
        exec("wifi reload");
        $msg = "Radio '$radio' updated successfully.";
    }
}

$radios = getRadios();
$radio_details = getRadioDetails();
$networks = getNetworks();

// List Wireless Interfaces
$wifis = [];
exec("uci show wireless", $wifi_out);
$wifi_sections = [];
foreach ($wifi_out as $line) {
    if (preg_match("/^wireless\.(@wifi-iface\[\d+\])=wifi-iface$/", $line, $m)) {
        $wifi_sections[$m[1]] = ['section' => $m[1]];
    } elseif (preg_match("/^wireless\.([^.]+)=wifi-iface$/", $line, $m)) {
        $wifi_sections[$m[1]] = ['section' => $m[1]];
    }
}
foreach ($wifi_out as $line) {
    if (preg_match("/^wireless\.(@wifi-iface\[\d+\])\.(\w+)=['\"]?(.*?)['\"]?$/", $line, $m)) {
        if (isset($wifi_sections[$m[1]])) {
            $wifi_sections[$m[1]][$m[2]] = $m[3];
        }
    } elseif (preg_match("/^wireless\.([^.]+)\.(\w+)=['\"]?(.*?)['\"]?$/", $line, $m)) {
        if (isset($wifi_sections[$m[1]])) {
            $wifi_sections[$m[1]][$m[2]] = $m[3];
        }
    }
}
foreach ($wifi_sections as $w) {
    if (isset($w['mode']) && $w['mode'] === 'ap') {
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
    <h3>Radio Devices</h3>
    <table>
        <thead>
            <tr>
                <th>Radio</th>
                <th>Country</th>
                <th>Channel</th>
                <th>HT Mode</th>
                <th>Tx Power</th>
                <th>Disabled</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($radio_details as $rd): ?>
            <tr>
                <form method="post">
                    <td><?php echo $rd['name']; ?><input type="hidden" name="radio_name" value="<?php echo $rd['name']; ?>"></td>
                    <td><input type="text" name="country" value="<?php echo isset($rd['country']) ? htmlspecialchars($rd['country']) : ''; ?>" placeholder="e.g., US"></td>
                    <td><input type="text" name="channel" value="<?php echo isset($rd['channel']) ? htmlspecialchars($rd['channel']) : ''; ?>" placeholder="auto or number"></td>
                    <td>
                        <select name="htmode">
                            <?php $hm = isset($rd['htmode']) ? $rd['htmode'] : ''; ?>
                            <option value="" <?php echo $hm==''?'selected':''; ?>>Auto</option>
                            <option value="HT20" <?php echo $hm=='HT20'?'selected':''; ?>>HT20</option>
                            <option value="HT40" <?php echo $hm=='HT40'?'selected':''; ?>>HT40</option>
                            <option value="VHT80" <?php echo $hm=='VHT80'?'selected':''; ?>>VHT80</option>
                            <option value="HE80" <?php echo $hm=='HE80'?'selected':''; ?>>HE80</option>
                        </select>
                    </td>
                    <td><input type="text" name="txpower" value="<?php echo isset($rd['txpower']) ? htmlspecialchars($rd['txpower']) : ''; ?>" placeholder="dBm"></td>
                    <td><input type="checkbox" name="disabled" <?php echo (isset($rd['disabled']) && $rd['disabled']=='1') ? 'checked' : ''; ?>></td>
                    <td><button type="submit" name="update_radio" class="btn btn-secondary">Update</button></td>
                </form>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
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
                <th>Hidden</th>
                <th>Isolate</th>
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
                    <td><?php echo isset($w['hidden']) ? $w['hidden'] : '0'; ?></td>
                    <td><?php echo isset($w['isolate']) ? $w['isolate'] : '0'; ?></td>
                    <td>
                        <form method="post" style="display:inline-block; margin-right:8px;">
                            <input type="hidden" name="section" value="<?php echo $w['section']; ?>">
                            <input type="text" name="ssid" value="<?php echo isset($w['ssid']) ? htmlspecialchars($w['ssid']) : ''; ?>" placeholder="SSID" style="width:150px;">
                            <select name="encryption" style="width:140px;">
                                <?php $enc = isset($w['encryption']) ? $w['encryption'] : 'none'; ?>
                                <option value="none" <?php echo $enc=='none'?'selected':''; ?>>Open</option>
                                <option value="psk2" <?php echo $enc=='psk2'?'selected':''; ?>>WPA2-PSK</option>
                            </select>
                            <input type="password" name="key" placeholder="Password" style="width:150px;">
                            <label style="margin-left:6px;"><input type="checkbox" name="hidden" <?php echo (isset($w['hidden']) && $w['hidden']=='1')?'checked':''; ?>> Hidden</label>
                            <label style="margin-left:6px;"><input type="checkbox" name="isolate" <?php echo (isset($w['isolate']) && $w['isolate']=='1')?'checked':''; ?>> Isolate</label>
                            <button type="submit" name="update_ssid" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.8em;">Update</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this SSID?');" style="display:inline-block;">
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
