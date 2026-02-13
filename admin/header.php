<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}
$page = basename($_SERVER['PHP_SELF'], ".php");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Pisowifi Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">Pisowifi Panel</div>
        <ul class="nav-links">
            <li><a href="index.php" class="<?php echo $page == 'index' ? 'active' : ''; ?>">Dashboard</a></li>
            <li><a href="hotspots.php" class="<?php echo $page == 'hotspots' ? 'active' : ''; ?>">Hotspot Settings</a></li>
            <li><a href="network.php" class="<?php echo (strpos($page, 'network') === 0) ? 'active' : ''; ?>">Network</a></li>
            <li><a href="users.php" class="<?php echo $page == 'users' ? 'active' : ''; ?>">Users</a></li>
            <li><a href="sales.php" class="<?php echo $page == 'sales' ? 'active' : ''; ?>">Sales</a></li>
            <li><a href="status.php" class="<?php echo $page == 'status' ? 'active' : ''; ?>">System Status</a></li>
            <li><a href="settings.php" class="<?php echo $page == 'settings' ? 'active' : ''; ?>">Settings</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </div>
    <div class="main-content">
