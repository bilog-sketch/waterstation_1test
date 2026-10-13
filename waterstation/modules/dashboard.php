<?php
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +7 days'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-01', strtotime($monthStart . ' +1 month'));

function sales_between(string $a, string $b): float
{
    return (float)q("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='Completed' AND order_date >= ? AND order_date < ?", [$a, $b])->fetchColumn();
}
$salesToday = sales_between($today, $tomorrow);
$salesWeek = sales_between($weekStart, $weekEnd);
$salesMonth = sales_between($monthStart, $monthEnd);

$received = (int)q("SELECT COUNT(*) FROM orders WHERE status <> 'Cancelled' AND order_date >= ? AND order_date < ?", [$today, $tomorrow])->fetchColumn();
$completed = (int)q("SELECT COUNT(*) FROM orders WHERE status = 'Completed' AND order_date >= ? AND order_date < ?", [$today, $tomorrow])->fetchColumn();
$pendingDel = (int)q("SELECT COUNT(*) FROM deliveries WHERE status IN ('Scheduled','Preparing')")->fetchColumn();
$ongoingDel = (int)q("SELECT COUNT(*) FROM deliveries WHERE status = 'Out for Delivery'")->fetchColumn();
$customers = (int)q('SELECT COUNT(*) FROM customers WHERE is_deleted = 0')->fetchColumn();
$lowStock = (int)q('SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity <= min_level')->fetchColumn();
$expMonth = (float)q('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < ?', [$monthStart, $monthEnd])->fetchColumn();
$net = $salesMonth - $expMonth;

// One query for chart data: daily completed sales for the last 12 months.
$from = date('Y-m-01', strtotime('-11 months'));
$daily = [];
foreach (q("SELECT DATE(order_date) d, SUM(total) s FROM orders WHERE status='Completed' AND order_date >= ? AND order_date < ? GROUP BY DATE(order_date)", [$from, $tomorrow])->fetchAll() as $r) {
    $daily[$r['d']] = (float)$r['s'];
}
$dLabels = $dData = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dLabels[] = date('M j', strtotime($d));
    $dData[] = $daily[$d] ?? 0;
}
$wLabels = $wData = [];
for ($i = 7; $i >= 0; $i--) {
    $ws = strtotime("monday this week -$i weeks");
    $sum = 0;
    for ($k = 0; $k < 7; $k++) $sum += $daily[date('Y-m-d', strtotime("+$k days", $ws))] ?? 0;
    $wLabels[] = date('M j', $ws);
    $wData[] = $sum;
}
$mLabels = $mData = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("first day of -$i months"));
    $sum = 0;
    foreach ($daily as $d => $s) if (substr($d, 0, 7) === $m) $sum += $s;
    $mLabels[] = date('M Y', strtotime($m . '-01'));
    $mData[] = $sum;
}
$recent = q('SELECT o.*, c.name cname FROM orders o LEFT JOIN customers c ON c.id = o.customer_id ORDER BY o.id DESC LIMIT 8')->fetchAll();

page_header('Dashboard', 'dashboard');
page_title('Dashboard', '<a class="btn btn-primary" href="' . e(url('modules/orders.php?action=new')) . '"><i class="bi bi-plus-circle"></i> New Sale</a>');

$cards = [
    ['Sales today', money($salesToday)], ['Sales this week', money($salesWeek)], ['Sales this month', money($salesMonth)],
    ['Orders received today', $received], ['Orders completed today', $completed], ['Pending deliveries', $pendingDel],
    ['Ongoing deliveries', $ongoingDel], ['Total customers', $customers], ['Low-stock items', $lowStock],
    ['Expenses this month', money($expMonth)], ['Net income this month', money($net)],
];
echo '<div class="row g-3 mb-4">';
foreach ($cards as [$label, $value]) {
    $warn = ($label === 'Low-stock items' && $lowStock > 0) ? ' text-danger' : '';
    echo '<div class="col-6 col-md-4 col-xl-3"><div class="card stat-card p-3 h-100"><div class="label">' . e($label) . '</div><div class="value' . $warn . '">' . e((string)$value) . '</div></div></div>';
}
echo '</div>';
?>
<div class="card p-3 mb-4">
  <ul class="nav nav-pills mb-2" id="chartTabs">
    <li class="nav-item"><button class="nav-link active" data-k="d">Daily (14 days)</button></li>
    <li class="nav-item"><button class="nav-link" data-k="w">Weekly (8 weeks)</button></li>
    <li class="nav-item"><button class="nav-link" data-k="m">Monthly (12 months)</button></li>
  </ul>
  <div style="height:300px"><canvas id="salesChart"></canvas></div>
  <div class="small text-muted mt-2">Charts show completed orders only.</div>
</div>
<div class="card p-3">
  <h2 class="h5">Recent orders</h2>
  <?php if (!$recent): ?><div class="empty-state">No orders yet. Click <b>New Sale</b> to record your first one.</div><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Order #</th><th>Date</th><th>Customer</th><th class="text-end">Total</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($recent as $o): ?>
      <tr><td><a href="<?= e(url('modules/orders.php?action=view&id=' . $o['id'])) ?>"><?= e($o['order_number']) ?></a></td>
      <td><?= e(fdatetime($o['order_date'])) ?></td><td><?= e($o['cname'] ?? 'Walk-in') ?></td>
      <td class="text-end"><?= money($o['total']) ?></td><td><?= status_badge($o['status']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
  var sets = {
    d: {l: <?= json_encode($dLabels) ?>, v: <?= json_encode($dData) ?>},
    w: {l: <?= json_encode($wLabels) ?>, v: <?= json_encode($wData) ?>},
    m: {l: <?= json_encode($mLabels) ?>, v: <?= json_encode($mData) ?>}
  };
  var chart = new Chart(document.getElementById('salesChart'), {
    type: 'bar',
    data: {labels: sets.d.l, datasets: [{label: 'Sales (₱)', data: sets.d.v, backgroundColor: '#0b5ed7'}]},
    options: {maintainAspectRatio: false, scales: {y: {beginAtZero: true}}}
  });
  document.querySelectorAll('#chartTabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('#chartTabs button').forEach(function (x) { x.classList.remove('active'); });
      b.classList.add('active');
      var s = sets[b.dataset.k];
      chart.data.labels = s.l; chart.data.datasets[0].data = s.v; chart.update();
    });
  });
})();
</script>
<?php page_footer();
