<?php include 'header.php'; ?>
<?php
$sales_file = '/etc/pisowifi_sales.csv';
$sales = [];
if (file_exists($sales_file)) {
    $lines = array_reverse(file($sales_file)); // Newest first
    foreach ($lines as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) >= 4) {
            $sales[] = [
                'date' => $parts[0],
                'mac' => $parts[1],
                'amount' => $parts[2],
                'minutes' => $parts[3]
            ];
        }
    }
}
// Simple pagination or limit
$sales = array_slice($sales, 0, 50);
?>

<div class="card">
    <h3>Sales Log (Last 50)</h3>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>MAC Address</th>
                <th>Amount</th>
                <th>Minutes Added</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sales)): ?>
                <tr><td colspan="4" style="text-align:center;">No sales recorded yet</td></tr>
            <?php else: ?>
                <?php foreach ($sales as $sale): ?>
                <tr>
                    <td><?php echo $sale['date']; ?></td>
                    <td><?php echo $sale['mac']; ?></td>
                    <td>₱<?php echo $sale['amount']; ?></td>
                    <td><?php echo $sale['minutes']; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'footer.php'; ?>
