<?php
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
    // Use absolute URL to force captive portal detection on some devices
    header("Location: http://10.0.0.1/pisowifi/");
    exit;
}

// Admin user - redirect to LuCI
header("Location: /cgi-bin/luci");
exit;
?>
