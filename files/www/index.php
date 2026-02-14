<?php
$portal = 'http://10.0.0.1/pisowifi/';
$o = [];
exec("uci -q get pisowifi.general.portal_url", $o, $ret);
if ($ret === 0 && isset($o[0]) && $o[0] !== '') {
    $portal = $o[0];
    if (substr($portal, -1) !== '/') {
        $portal .= '/';
    }
}
$host = $_SERVER['HTTP_HOST'];
// Adjust these IPs to match your router's LAN IP
$router_ips = ['192.168.1.1', '10.0.0.1', 'openwrt.lan'];

// Check if the request is for the router admin interface
$is_admin = false;
foreach ($router_ips as $ip) {
    if (strpos($host, $ip) !== false) {
        $is_admin = true;
        break;
    }
}

if (!$is_admin) {
    // Captive portal user
    // Return 302 Redirect to the portal
    // This is the standard trigger for CNA (Captive Network Assistant)
    header("Location: " . $portal, true, 302);
    exit;
}

// Admin user - redirect to LuCI
header("Location: /cgi-bin/luci");
exit;
?>
