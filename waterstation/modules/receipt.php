<?php
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$id = (int)get('id');
$o = q('SELECT o.*, c.name cname, c.customer_code, c.contact_number cphone, u.full_name staff
        FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users u ON u.id = o.processed_by WHERE o.id = ?', [$id])->fetch();
if (!$o) { http_response_code(404); exit('Order not found.'); }
$items = q('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id])->fetchAll();

ob_start(); ?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Receipt <?= e($o['receipt_number']) ?></title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; margin: 0; padding: 12px; background: #fff; }
  .r { width: 340px; margin: 0 auto; }
  h1 { font-size: 16px; margin: 0; text-align: center; } .c { text-align: center; } .muted { color: #555; }
  table { width: 100%; border-collapse: collapse; } td, th { padding: 3px 0; vertical-align: top; }
  .line td, .line th { border-top: 1px dashed #777; } .right { text-align: right; }
  .total td { font-size: 14px; font-weight: bold; border-top: 1px solid #000; }
  .void { text-align: center; font-size: 20px; font-weight: bold; color: #c00; border: 2px solid #c00; padding: 4px; margin: 8px 0; }
  .noprint { text-align: center; margin-bottom: 10px; } @media print { .noprint { display: none; } }
</style></head><body>
<?php if (!isset($forPdf)): ?><div class="noprint"><button onclick="window.print()">Print receipt</button> <a href="?id=<?= (int)$id ?>&pdf=1">Download PDF</a></div><?php endif; ?>
<div class="r">
  <h1><?= e(setting('station_name')) ?></h1>
  <div class="c muted"><?= e(setting('station_address')) ?><br><?= e(setting('station_contact')) ?></div>
  <?php if ($o['status'] === 'Cancelled'): ?><div class="void">CANCELLED</div><?php endif; ?>
  <table style="margin-top:8px">
    <tr><td>Receipt No.</td><td class="right"><b><?= e($o['receipt_number']) ?></b></td></tr>
    <tr><td>Order No.</td><td class="right"><?= e($o['order_number']) ?></td></tr>
    <tr><td>Date / Time</td><td class="right"><?= e(fdatetime($o['order_date'])) ?></td></tr>
    <tr><td>Customer</td><td class="right"><?= e($o['cname'] ?? 'Walk-in') ?><?= $o['customer_code'] ? ' (' . e($o['customer_code']) . ')' : '' ?></td></tr>
  </table>
  <table style="margin-top:8px">
    <tr class="line"><th align="left">Item</th><th class="right">Qty</th><th class="right">Price</th><th class="right">Amount</th></tr>
    <?php foreach ($items as $it): ?>
      <tr><td><?= e($it['product_name']) ?></td><td class="right"><?= (int)$it['quantity'] ?></td><td class="right"><?= money($it['unit_price']) ?></td><td class="right"><?= money($it['line_total']) ?></td></tr>
    <?php endforeach; ?>
    <tr class="line"><td colspan="3">Subtotal</td><td class="right"><?= money($o['subtotal']) ?></td></tr>
    <tr><td colspan="3">Discount</td><td class="right">- <?= money($o['discount']) ?></td></tr>
    <tr class="total"><td colspan="3">TOTAL</td><td class="right"><?= money($o['total']) ?></td></tr>
    <tr><td colspan="3">Payment method</td><td class="right"><?= e($o['payment_method']) ?></td></tr>
  </table>
  <p class="muted" style="margin-top:8px">Processed by: <?= e($o['staff'] ?: 'Administrator') ?></p>
  <p class="c"><?= e(setting('receipt_footer')) ?></p>
</div></body></html>
<?php
$html = ob_get_clean();
if (get('pdf') === '1') {
    $forPdf = true;
    $html = str_replace('<div class="noprint">', '<div class="noprint" style="display:none">', $html);
    output_pdf($html, 'receipt-' . $o['receipt_number'] . '.pdf');
}
echo $html;
