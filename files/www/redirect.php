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
header("Location: " . $portal, true, 302);
exit;
