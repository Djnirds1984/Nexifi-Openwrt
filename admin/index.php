<?php include 'header.php'; ?>
<?php
$users_file = '/tmp/pisowifi_users.json';
$active_users = 0;
if (file_exists($users_file)) {
    $users = json_decode(file_get_contents($users_file), true);
    $active_users = count($users);
}

// Calculate sales today
$sales_file = '/etc/pisowifi_sales.csv';
$sales_today = 0;
$today = date('Y-m-d');
if (file_exists($sales_file)) {
    $lines = file($sales_file);
    foreach ($lines as $line) {
        $parts = explode(',', $line);
        if (count($parts) >= 3 && strpos($parts[0], $today) === 0) {
            $sales_today += floatval($parts[2]);
        }
    }
}
?>

<h2>Dashboard</h2>
<div class="stats-grid">
    <div class="stat-box">
        <div class="stat-number"><?php echo $active_users; ?></div>
        <div>Active Users</div>
    </div>
    <div class="stat-box green">
        <div class="stat-number">₱<?php echo number_format($sales_today, 2); ?></div>
        <div>Sales Today</div>
    </div>
    <div class="stat-box orange">
        <div class="stat-number">OK</div>
        <div>System Status</div>
    </div>
</div>

<div class="card" style="margin-top: 20px;">
    <h3>Quick Actions</h3>
    <p>Use the sidebar to manage users, view sales logs, or change settings.</p>
</div>

<?php include 'footer.php'; ?>
