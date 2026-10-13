<?php
require __DIR__ . '/../includes/bootstrap.php';
require_login();

const REPORTS = [
    'sales_daily' => 'Daily sales', 'sales_weekly' => 'Weekly sales', 'sales_monthly' => 'Monthly sales', 'sales_annual' => 'Annual sales',
    'orders' => 'Order history', 'customers' => 'Customer list', 'bestsellers' => 'Best-selling products',
    'inventory' => 'Inventory and low-stock', 'deliveries' => 'Delivery report', 'expenses' => 'Expense report', 'pnl' => 'Estimated profit and loss',
];

function range_sql(string $col, string $from, string $to, array &$params, bool $isDatetime): string
{
    $sql = '';
    if ($from !== '') { $sql .= " AND $col >= ?"; $params[] = $isDatetime ? "$from 00:00:00" : $from; }
    if ($to !== '') {
        if ($isDatetime) { $sql .= " AND $col < ?"; $params[] = date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00'; }
        else { $sql .= " AND $col <= ?"; $params[] = $to; }
    }
    return $sql;
}

/** Each report returns ['cols' => [[label, type]...], 'rows' => [...], 'foot' => [...]|null] */
function build_report(string $key, string $from, string $to): array
{
    $p = [];
    if (strpos($key, 'sales_') === 0) {
        $period = substr($key, 6);
        $r = range_sql('order_date', $from, $to, $p, true);
        $daily = q("SELECT DATE(order_date) d, COUNT(*) n, SUM(subtotal) sub, SUM(discount) disc, SUM(total) tot FROM orders WHERE status='Completed'$r GROUP BY DATE(order_date) ORDER BY d", $p)->fetchAll();
        $b = [];
        foreach ($daily as $d) {
            $ts = strtotime($d['d']);
            if ($period === 'daily') { $k = $d['d']; $label = date('F j, Y', $ts); }
            elseif ($period === 'weekly') { $ws = strtotime('monday this week', $ts); $k = date('Y-m-d', $ws); $label = 'Week of ' . date('F j, Y', $ws); }
            elseif ($period === 'monthly') { $k = date('Y-m', $ts); $label = date('F Y', $ts); }
            else { $k = date('Y', $ts); $label = $k; }
            $b[$k] ??= [$label, 0, 0.0, 0.0, 0.0];
            $b[$k][1] += (int)$d['n']; $b[$k][2] += (float)$d['sub']; $b[$k][3] += (float)$d['disc']; $b[$k][4] += (float)$d['tot'];
        }
        ksort($b);
        $rows = array_values($b);
        $foot = ['TOTAL', array_sum(array_column($rows, 1)), array_sum(array_column($rows, 2)), array_sum(array_column($rows, 3)), array_sum(array_column($rows, 4))];
        return ['cols' => [['Period', 'text'], ['Completed orders', 'int'], ['Gross sales', 'money'], ['Discounts', 'money'], ['Net sales', 'money']], 'rows' => $rows, 'foot' => $foot];
    }
    switch ($key) {
        case 'orders':
            $r = range_sql('o.order_date', $from, $to, $p, true);
            $rows = q("SELECT o.order_number, o.order_date, COALESCE(c.name,'Walk-in') cname, o.payment_method, o.status, o.total FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE 1=1$r ORDER BY o.order_date, o.id", $p)->fetchAll(PDO::FETCH_NUM);
            $tot = 0.0; foreach ($rows as $x) if ($x[4] === 'Completed') $tot += (float)$x[5];
            return ['cols' => [['Order #', 'text'], ['Date', 'datetime'], ['Customer', 'text'], ['Payment', 'text'], ['Status', 'text'], ['Total', 'money']], 'rows' => $rows,
                'foot' => ['COMPLETED TOTAL (cancelled excluded)', '', '', '', '', $tot], 'note' => 'Cancelled orders are listed but are not counted in the total.'];
        case 'customers':
            $r = range_sql('c.registered_at', $from, $to, $p, false);
            $rows = q("SELECT c.customer_code, c.name, c.contact_number, c.type, c.registered_at, COUNT(CASE WHEN o.status='Completed' THEN 1 END) n, COALESCE(SUM(CASE WHEN o.status='Completed' THEN o.total END),0) spent
                FROM customers c LEFT JOIN orders o ON o.customer_id=c.id WHERE c.is_deleted=0$r GROUP BY c.id ORDER BY c.name", $p)->fetchAll(PDO::FETCH_NUM);
            return ['cols' => [['ID', 'text'], ['Name', 'text'], ['Contact', 'text'], ['Type', 'text'], ['Registered', 'date'], ['Completed orders', 'int'], ['Total spent', 'money']], 'rows' => $rows,
                'foot' => ['TOTAL', count($rows) . ' customers', '', '', '', array_sum(array_column($rows, 5)), array_sum(array_column($rows, 6))]];
        case 'bestsellers':
            $r = range_sql('o.order_date', $from, $to, $p, true);
            $rows = q("SELECT oi.product_name, SUM(oi.quantity) qty, SUM(oi.line_total) amt FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status='Completed'$r GROUP BY oi.product_name ORDER BY qty DESC, amt DESC", $p)->fetchAll(PDO::FETCH_NUM);
            return ['cols' => [['Product / service', 'text'], ['Units sold', 'int'], ['Revenue (before order discounts)', 'money']], 'rows' => $rows, 'foot' => ['TOTAL', array_sum(array_column($rows, 1)), array_sum(array_column($rows, 2))]];
        case 'inventory':
            $pp = [];
            $dr = range_sql('t.txn_date', $from, $to, $pp, false);
            $rows = q("SELECT i.name, i.category, i.quantity, i.min_level, IF(i.is_active=0,'Inactive',IF(i.quantity<=i.min_level,'LOW STOCK','OK')) st,
                COALESCE(SUM(CASE WHEN t.type='in' THEN t.quantity END),0) qin, COALESCE(SUM(CASE WHEN t.type IN ('out','order') THEN t.quantity END),0) qout
                FROM inventory_items i LEFT JOIN inventory_transactions t ON t.item_id=i.id$dr GROUP BY i.id ORDER BY (i.quantity<=i.min_level) DESC, i.name", $pp)->fetchAll(PDO::FETCH_NUM);
            return ['cols' => [['Item', 'text'], ['Category', 'text'], ['Current stock', 'num'], ['Minimum', 'num'], ['Status', 'text'], ['Stock in (period)', 'num'], ['Stock out (period)', 'num']], 'rows' => $rows, 'foot' => null,
                'note' => 'Current stock and status are as of now; stock in/out columns follow the date range.'];
        case 'deliveries':
            $r = range_sql('d.scheduled_at', $from, $to, $p, true);
            $rows = q("SELECT d.scheduled_at, o.order_number, COALESCE(c.name,'Walk-in') cname, d.address, COALESCE(d.rider_name,'') rider, d.status, d.delivery_fee, COALESCE(d.received_by,'') rb
                FROM deliveries d JOIN orders o ON o.id=d.order_id LEFT JOIN customers c ON c.id=o.customer_id WHERE 1=1$r ORDER BY d.scheduled_at", $p)->fetchAll(PDO::FETCH_NUM);
            $fees = 0.0; foreach ($rows as $x) if ($x[5] === 'Delivered') $fees += (float)$x[6];
            return ['cols' => [['Scheduled', 'datetime'], ['Order #', 'text'], ['Customer', 'text'], ['Address', 'text'], ['Rider', 'text'], ['Status', 'text'], ['Fee', 'money'], ['Received by', 'text']], 'rows' => $rows,
                'foot' => [count($rows) . ' deliveries', '', '', '', '', '', $fees, ''], 'note' => 'Fee total counts delivered items only.'];
        case 'expenses':
            $r = range_sql('expense_date', $from, $to, $p, false);
            $rows = q("SELECT expense_date, category, COALESCE(description,'') d, amount FROM expenses WHERE 1=1$r ORDER BY expense_date, id", $p)->fetchAll(PDO::FETCH_NUM);
            return ['cols' => [['Date', 'date'], ['Category', 'text'], ['Description', 'text'], ['Amount', 'money']], 'rows' => $rows, 'foot' => ['TOTAL', '', '', array_sum(array_column($rows, 3))]];
        case 'pnl':
            $r = range_sql('order_date', $from, $to, $p, true);
            $sales = (float)q("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='Completed'$r", $p)->fetchColumn();
            $p2 = [];
            $r2 = range_sql('expense_date', $from, $to, $p2, false);
            $ex = q("SELECT category, SUM(amount) a FROM expenses WHERE 1=1$r2 GROUP BY category ORDER BY category", $p2)->fetchAll(PDO::FETCH_NUM);
            $te = array_sum(array_column($ex, 1));
            $rows = [['Net sales (completed orders)', $sales]];
            foreach ($ex as $e) $rows[] = ['Expense: ' . $e[0], -(float)$e[1]];
            $rows[] = ['Total expenses', -$te];
            return ['cols' => [['Item', 'text'], ['Amount', 'money']], 'rows' => $rows, 'foot' => ['ESTIMATED NET INCOME', $sales - $te],
                'note' => 'Estimate only: completed sales minus recorded expenses. It does not include cost of goods, depreciation, or taxes.'];
    }
    throw new UserError('Unknown report.');
}

function fmt_cell($v, string $t): string
{
    if ($v === '' || $v === null) return '';
    return match ($t) { 'money' => money($v), 'int' => number_format((float)$v), 'num' => e((float)$v), 'date' => e(fdate($v)), 'datetime' => e(fdatetime($v)), default => e($v) };
}
function csv_cell($v, string $t)
{
    return in_array($t, ['money', 'num', 'int'], true) && $v !== '' ? round((float)$v, 2) : $v;
}

$key = array_key_exists(get('r'), REPORTS) ? get('r') : 'sales_daily';
$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-d'));
if ($from !== '' && !valid_date($from)) $from = date('Y-m-01');
if ($to !== '' && !valid_date($to)) $to = date('Y-m-d');
if ($from !== '' && $to !== '' && $from > $to) { flash('error', 'The start date is after the end date, so the dates were swapped.'); [$from, $to] = [$to, $from]; }

$rep = build_report($key, $from, $to);
$title = REPORTS[$key];
$rangeText = ($from ? fdate($from) : 'Beginning') . ' to ' . ($to ? fdate($to) : 'today');

if (get('export') === 'csv') {
    $headers = array_column($rep['cols'], 0);
    $out = [];
    foreach ($rep['rows'] as $row) { $o = []; foreach ($row as $i => $v) $o[] = csv_cell($v, $rep['cols'][$i][1]); $out[] = $o; }
    if (!empty($rep['foot'])) { $o = []; foreach ($rep['foot'] as $i => $v) $o[] = csv_cell($v, $rep['cols'][$i][1]); $out[] = $o; }
    audit('report_exported', 'report', $key, "csv $from..$to");
    output_csv(preg_replace('/[^a-z_]/', '', $key) . '_' . date('Ymd') . '.csv', $headers, $out);
}

function report_table(array $rep): string
{
    $h = '<table class="table table-sm table-striped"><thead><tr>';
    foreach ($rep['cols'] as [$label, $t]) $h .= '<th' . (in_array($t, ['money', 'int', 'num'], true) ? ' class="text-end"' : '') . '>' . e($label) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rep['rows'] as $row) {
        $h .= '<tr>';
        foreach ($row as $i => $v) { $t = $rep['cols'][$i][1]; $h .= '<td' . (in_array($t, ['money', 'int', 'num'], true) ? ' class="text-end"' : '') . '>' . fmt_cell($v, $t) . '</td>'; }
        $h .= '</tr>';
    }
    if (!$rep['rows']) $h .= '<tr><td colspan="' . count($rep['cols']) . '" class="text-center text-muted">No records for this date range.</td></tr>';
    $h .= '</tbody>';
    if (!empty($rep['foot'])) {
        $h .= '<tfoot><tr class="fw-bold">';
        foreach ($rep['foot'] as $i => $v) { $t = $rep['cols'][$i][1]; $h .= '<td' . (in_array($t, ['money', 'int', 'num'], true) ? ' class="text-end"' : '') . '>' . ($i === 0 ? e($v) : fmt_cell($v, $t)) . '</td>'; }
        $h .= '</tr></tfoot>';
    }
    return $h . '</table>';
}

if (get('export') === 'pdf') {
    audit('report_exported', 'report', $key, "pdf $from..$to");
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . e($title) . '</title><style>
      body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#111}h1{font-size:16px;margin:0}table{width:100%;border-collapse:collapse;margin-top:10px}
      th,td{border:1px solid #bbb;padding:4px 6px;text-align:left}th{background:#e8f0fe}.text-end{text-align:right}tfoot td{font-weight:bold;background:#f3f3f3}.muted{color:#555}</style></head><body>
      <h1>' . e(setting('station_name')) . '</h1><div class="muted">' . e(setting('station_address')) . '</div>
      <h2 style="font-size:14px;margin:10px 0 0">' . e($title) . '</h2><div class="muted">Period: ' . e($rangeText) . ' &middot; Generated ' . e(fdatetime(date('Y-m-d H:i:s'))) . '</div>'
        . report_table($rep) . (!empty($rep['note']) ? '<p class="muted">' . e($rep['note']) . '</p>' : '') . '</body></html>';
    output_pdf($html, $key . '_' . date('Ymd') . '.pdf');
}

$self = url('modules/reports.php');
$qs = http_build_query(['r' => $key, 'from' => $from, 'to' => $to]);
page_header('Reports', 'reports');
page_title('Reports', '<a class="btn btn-outline-secondary" href="' . e($self . '?' . $qs . '&export=csv') . '"><i class="bi bi-filetype-csv"></i> CSV</a> '
    . '<a class="btn btn-outline-secondary" target="_blank" href="' . e($self . '?' . $qs . '&export=pdf') . '"><i class="bi bi-file-earmark-pdf"></i> PDF / Print</a>');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-4"><select name="r" class="form-select"><?php foreach (REPORTS as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === $key ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
  <div class="col-auto"><button class="btn btn-primary">Generate</button></div>
</form>
<div class="card p-3">
  <h2 class="h5 mb-0"><?= e($title) ?></h2><div class="text-muted small mb-2">Period: <?= e($rangeText) ?></div>
  <div class="table-responsive"><?= report_table($rep) ?></div>
  <?php if (!empty($rep['note'])): ?><div class="small text-muted"><?= e($rep['note']) ?></div><?php endif; ?>
</div>
<?php page_footer();
