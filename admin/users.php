<?php include 'header.php'; ?>
<?php
if (isset($_POST['kick'])) {
    $kick_mac = $_POST['kick'];
    file_put_contents('/tmp/pisowifi_kick', $kick_mac);
    // Wait a bit for backend to process
    sleep(1);
}

$users_file = '/tmp/pisowifi_users.json';
$users = [];
if (file_exists($users_file)) {
    $users = json_decode(file_get_contents($users_file), true);
}
?>

<div class="card">
    <h3>Active Users</h3>
    <table>
        <thead>
            <tr>
                <th>MAC Address</th>
                <th>Expires At</th>
                <th>Time Remaining</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="4" style="text-align:center;">No active users</td></tr>
            <?php else: ?>
                <?php foreach ($users as $mac => $info): ?>
                <tr>
                    <td><?php echo $mac; ?></td>
                    <td><?php echo date('Y-m-d H:i:s', $info['expiry']); ?></td>
                    <td><?php echo gmdate("H:i:s", max(0, $info['expiry'] - time())); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="kick" value="<?php echo $mac; ?>">
                            <button type="submit" class="btn btn-danger">Disconnect</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'footer.php'; ?>
