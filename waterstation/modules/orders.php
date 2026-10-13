<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$action = get('action', 'list');
$self = url('modules/orders.php');

/** Read item rows from POST. $locked = product_id => price already on the order (edit mode keeps old prices). */
function parse_items(array $locked = []): array
{
    $pids = $_POST['item_product'] ?? [];
    $qtys = $_POST['item_qty'] ?? [];
    if (!is_array($pids) || !is_array($qtys)) throw new UserError('Invalid item data.');
    $merged = [];
    foreach ($pids as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($qtys[$i] ?? 0);
        if ($pid <= 0 && $qty <= 0) continue;
        if ($pid <= 0) continue;
        if ($qty < 1 || $qty > 9999) throw new UserError('Quantity must be a whole number from 1 to 9999.');
        $merged[$pid] = ($merged[$pid] ?? 0) + $qty;
    }
    if (!$merged) throw new UserError('Add at least one item to the order.');
    $out = [];
    foreach ($merged as $pid => $qty) {
        $p = q('SELECT id, name, unit_price, status FROM products WHERE id = ?', [$pid])->fetch();
        if (!$p) throw new UserError('One of the selected products no longer exists.');
        if ($p['status'] !== 'active' && !isset($locked[$pid])) throw new UserError('"' . $p['name'] . '" is inactive and cannot be added.');
        $price = isset($locked[$pid]) ? (float)$locked[$pid] : (float)$p['unit_price'];
        $out[] = ['product_id' => $pid, 'product_name' => $p['name'], 'unit_price' => $price, 'quantity' => $qty];
    }
    return $out;
}

function write_items(int $orderId, array $items): void
{
    foreach ($items as $it) {
        q('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, line_total) VALUES (?,?,?,?,?,?)',
            [$orderId, $it['product_id'], $it['product_name'], $it['unit_price'], $it['quantity'], round($it['unit_price'] * $it['quantity'], 2)]);
    }
}

$formData = null;
$prefill = [];

if (is_post()) {
    csrf_verify();
    $do = post('do');
    try {
        if ($do === 'save') {
            $id = (int)post('id');
            $pdo = db();
            $notes = post('notes');
            if (mb_strlen($notes) > 255) throw new UserError('Notes are too long (max 255).');

            if ($id) {
                $o = q('SELECT * FROM orders WHERE id = ?', [$id])->fetch();
                if (!$o) throw new UserError('Order not found.');
                if (!order_is_open($o['status'])) {
                    q('UPDATE orders SET notes = ? WHERE id = ?', [$notes ?: null, $id]);
                    audit('order_notes_edited', 'order', $id, $o['order_number']);
                    flash('success', 'Notes updated. (Completed/cancelled orders can only have notes edited.)');
                    redirect('modules/orders.php?action=view&id=' . $id);
                }
            }
            $payment = opt(PAYMENT_METHODS, post('payment_method'), '');
            if ($payment === '') throw new UserError('Choose a payment method.');
            $discount = parse_money(post('discount') ?: '0', true);
            if ($discount === null) throw new UserError('Discount must be a valid amount.');
            $customerId = (int)post('customer_id') ?: null;
            if ($customerId && !q('SELECT id FROM customers WHERE id = ? AND is_deleted = 0', [$customerId])->fetch()) throw new UserError('Selected customer was not found.');
            $newName = post('new_customer_name');
            $newContact = post('new_customer_contact');
            if (!$customerId && $newName !== '') {
                if (mb_strlen($newName) > 120) throw new UserError('New customer name is too long.');
                if ($newContact !== '' && !preg_match('/^[0-9+\-\s()]{7,25}$/', $newContact)) throw new UserError('New customer contact number looks invalid.');
            }

            $locked = [];
            if ($id) foreach (q('SELECT product_id, unit_price FROM order_items WHERE order_id = ?', [$id])->fetchAll() as $r) $locked[(int)$r['product_id']] = $r['unit_price'];
            $items = parse_items($locked);
            $t = calc_totals($items, $discount);
            if ($discount > $t['subtotal']) throw new UserError('Discount cannot be more than the subtotal (' . money($t['subtotal']) . ').');

            $pdo->beginTransaction();
            try {
                if (!$customerId && $newName !== '') {
                    $code = next_seq($pdo, 'customers', 'customer_code', 'CUST-', 4);
                    q('INSERT INTO customers (customer_code,name,contact_number,type,registered_at) VALUES (?,?,?,?,CURDATE())', [$code, $newName, $newContact ?: null, 'Walk-in']);
                    $customerId = (int)$pdo->lastInsertId();
                    audit('customer_created', 'customer', $customerId, "$code $newName (from order form)");
                }
                if ($id) {
                    $cur = q('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$id])->fetch();
                    if (!order_is_open($cur['status'])) throw new UserError('This order was completed or cancelled in the meantime and can no longer be edited.');
                    q('DELETE FROM order_items WHERE order_id = ?', [$id]);
                    write_items($id, $items);
                    q('UPDATE orders SET customer_id=?, subtotal=?, discount=?, total=?, payment_method=?, notes=? WHERE id=?',
                        [$customerId, $t['subtotal'], $t['discount'], $t['total'], $payment, $notes ?: null, $id]);
                    audit('order_edited', 'order', $id, "{$cur['order_number']}: total " . number_format((float)$cur['total'], 2) . ' -> ' . number_format($t['total'], 2));
                    $pdo->commit();
                    flash('success', 'Order updated.');
                    redirect('modules/orders.php?action=view&id=' . $id);
                }
                $orderNo = next_seq($pdo, 'orders', 'order_number', 'ORD-' . date('Ymd') . '-', 4);
                $receiptNo = 'RCT-' . substr($orderNo, 4);
                q('INSERT INTO orders (order_number, receipt_number, customer_id, order_date, subtotal, discount, total, payment_method, status, processed_by, notes)
                    VALUES (?,?,?,NOW(),?,?,?,?,\'Pending\',?,?)',
                    [$orderNo, $receiptNo, $customerId, $t['subtotal'], $t['discount'], $t['total'], $payment, uid(), $notes ?: null]);
                $newId = (int)$pdo->lastInsertId();
                write_items($newId, $items);
                audit('order_created', 'order', $newId, "$orderNo total " . number_format($t['total'], 2) . " $payment");
                if (post('complete_now') === '1') change_order_status($pdo, $newId, 'Completed', uid());
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            flash('success', "Order $orderNo saved" . (post('complete_now') === '1' ? ' and completed.' : '.'));
            redirect('modules/orders.php?action=view&id=' . $newId . (post('complete_now') === '1' ? '&print=1' : ''));
        } elseif ($do === 'status') {
            $id = (int)post('id');
            $new = opt(ORDER_STATUSES, post('status'), '');
            if ($new === '') throw new UserError('Invalid status.');
            change_order_status(db(), $id, $new, uid());
            flash('success', 'Order status changed to ' . $new . '.' . ($new === 'Completed' ? ' Stock was updated.' : ''));
            redirect('modules/orders.php?action=view&id=' . $id);
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        if ($do === 'save') {
            $action = post('id') ? 'edit' : 'new';
            $_GET['id'] = post('id');
            $formData = $_POST;
            foreach ((array)($_POST['item_product'] ?? []) as $i => $pid) {
                if ((int)$pid > 0) $prefill[] = ['pid' => (int)$pid, 'qty' => max(1, (int)(($_POST['item_qty'] ?? [])[$i] ?? 1))];
            }
        } else {
            redirect('modules/orders.php?action=view&id=' . (int)post('id'));
        }
    }
}

/* ---------------- View ---------------- */
if ($action === 'view') {
    $id = (int)get('id');
    $o = q('SELECT o.*, c.name cname, c.customer_code, c.contact_number cphone, c.address caddr, u.full_name staff
            FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users u ON u.id = o.processed_by WHERE o.id = ?', [$id])->fetch();
    if (!$o) { flash('error', 'Order not found.'); redirect('modules/orders.php'); }
    $items = q('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id])->fetchAll();
    $del = q('SELECT * FROM deliveries WHERE order_id = ?', [$id])->fetch();
    page_header($o['order_number'], 'orders');
    $btns = '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('modules/receipt.php?id=' . $id)) . '" target="_blank"><i class="bi bi-printer"></i> Receipt</a> '
        . '<a class="btn btn-outline-secondary btn-sm" href="' . e(url('modules/receipt.php?id=' . $id . '&pdf=1')) . '"><i class="bi bi-file-earmark-pdf"></i> PDF</a> ';
    if (order_is_open($o['status'])) $btns .= '<a class="btn btn-outline-primary btn-sm" href="' . e($self . '?action=edit&id=' . $id) . '">Edit</a>';
    page_title($o['order_number'], $btns);
    if (get('print') === '1') echo '<script>window.open(' . json_encode(url('modules/receipt.php?id=' . $id)) . ', "_blank");</script>';
    ?>
    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card p-3 mb-3">
          <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Item</th><th class="text-end">Price</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr></thead><tbody>
            <?php foreach ($items as $it): ?>
              <tr><td><?= e($it['product_name']) ?></td><td class="text-end"><?= money($it['unit_price']) ?></td><td class="text-end"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= money($it['line_total']) ?></td></tr>
            <?php endforeach; ?></tbody>
            <tfoot>
              <tr><td colspan="3" class="text-end">Subtotal</td><td class="text-end"><?= money($o['subtotal']) ?></td></tr>
              <tr><td colspan="3" class="text-end">Discount</td><td class="text-end">- <?= money($o['discount']) ?></td></tr>
              <tr class="fw-bold"><td colspan="3" class="text-end">TOTAL</td><td class="text-end"><?= money($o['total']) ?></td></tr>
            </tfoot></table></div>
        </div>
        <div class="card p-3">
          <h2 class="h6">Change status</h2>
          <?php $next = order_transitions()[$o['status']]; if (!$next): ?>
            <div class="text-muted">This order is <?= e($o['status']) ?> and can no longer change status.</div>
          <?php else: foreach ($next as $s):
              $isCancel = $s === 'Cancelled';
              echo confirm_form($self, ['do' => 'status', 'id' => $id, 'status' => $s],
                  $isCancel ? 'Cancel order' : ($s === 'Completed' ? 'Mark Completed' : e($s)),
                  $isCancel ? 'Cancel this order? Cancelled orders do not count in sales and cannot be reopened.' : 'Change this order to ' . $s . '?',
                  $isCancel ? 'btn-outline-danger btn-sm' : ($s === 'Completed' ? 'btn-success btn-sm' : 'btn-outline-primary btn-sm')) . ' ';
          endforeach; ?>
            <div class="form-text mt-2">"Mark Completed" deducts stock for tracked items.</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card p-3 mb-3"><dl class="mb-0">
          <dt>Status</dt><dd><?= status_badge($o['status']) ?></dd>
          <dt>Date &amp; time</dt><dd><?= e(fdatetime($o['order_date'])) ?></dd>
          <dt>Customer</dt><dd><?= $o['customer_id'] ? '<a href="' . e(url('modules/customers.php?action=view&id=' . $o['customer_id'])) . '">' . e($o['cname']) . '</a> (' . e($o['customer_code']) . ')' : 'Walk-in' ?></dd>
          <dt>Payment</dt><dd><?= e($o['payment_method']) ?> (paid in full)</dd>
          <dt>Receipt no.</dt><dd><?= e($o['receipt_number']) ?></dd>
          <dt>Processed by</dt><dd><?= e($o['staff'] ?: '-') ?></dd>
          <dt>Notes</dt><dd><?= e($o['notes'] ?: '-') ?></dd>
        </dl></div>
        <div class="card p-3"><h2 class="h6">Delivery</h2>
          <?php if ($del): ?>
            <?= status_badge($del['status']) ?> <span class="small"><?= e(fdatetime($del['scheduled_at'])) ?></span><br>
            <a href="<?= e(url('modules/deliveries.php?action=view&id=' . $del['id'])) ?>">Open delivery</a>
          <?php elseif (order_is_open($o['status'])): ?>
            <a class="btn btn-outline-primary btn-sm" href="<?= e(url('modules/deliveries.php?action=new&order_id=' . $id)) ?>"><i class="bi bi-truck"></i> Schedule delivery</a>
          <?php else: ?><span class="text-muted">None</span><?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    page_footer();
    exit;
}

/* ---------------- New / Edit form ---------------- */
if ($action === 'new' || $action === 'edit') {
    $o = ['id' => '', 'customer_id' => get('customer_id'), 'payment_method' => 'Cash', 'discount' => '0', 'notes' => '', 'status' => 'Pending'];
    $existingPrices = [];
    if ($action === 'edit') {
        $o = q('SELECT * FROM orders WHERE id = ?', [(int)get('id')])->fetch();
        if (!$o) { flash('error', 'Order not found.'); redirect('modules/orders.php'); }
        foreach (q('SELECT product_id, quantity, unit_price FROM order_items WHERE order_id = ?', [$o['id']])->fetchAll() as $r) {
            $existingPrices[(int)$r['product_id']] = (float)$r['unit_price'];
            if (!$formData) $prefill[] = ['pid' => (int)$r['product_id'], 'qty' => (int)$r['quantity']];
        }
    }
    if ($formData) $o = array_merge($o, array_intersect_key($formData, $o));
    $open = $action === 'new' || order_is_open($o['status']);
    $products = q("SELECT id, name, unit_price, status FROM products WHERE status = 'active' OR id IN (" . ($existingPrices ? implode(',', array_map('intval', array_keys($existingPrices))) : '0') . ') ORDER BY name')->fetchAll();
    $pj = [];
    foreach ($products as $p) $pj[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'price' => (float)($existingPrices[(int)$p['id']] ?? $p['unit_price'])];
    $customers = q('SELECT id, customer_code, name FROM customers WHERE is_deleted = 0 ORDER BY name LIMIT 2000')->fetchAll();

    page_header($action === 'new' ? 'New Sale' : 'Edit Order', $action === 'new' ? 'sale' : 'orders');
    page_title($action === 'new' ? 'New Sale' : 'Edit order ' . $o['order_number']);
    if (!$open): ?><div class="alert alert-info">This order is <?= e($o['status']) ?>. Only the notes can be edited.</div><?php endif; ?>
    <form method="post" action="<?= e($self) ?>" id="orderForm">
      <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= e($o['id']) ?>">
      <div class="row g-3">
        <?php if ($open): ?>
        <div class="col-lg-7">
          <div class="card p-3 mb-3">
            <div class="mb-2 fw-semibold">Quick add</div>
            <div class="d-flex flex-wrap gap-2" id="quick">
              <?php foreach ($products as $p): if ($p['status'] !== 'active') continue; ?>
                <button type="button" class="btn btn-outline-primary quick-btn" data-id="<?= (int)$p['id'] ?>" data-no-loading><?= e($p['name']) ?><br><small><?= money($p['unit_price']) ?></small></button>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="card p-3">
            <div class="table-responsive"><table class="table align-middle mb-2">
              <thead><tr><th>Item</th><th style="width:110px">Qty</th><th class="text-end">Price</th><th class="text-end">Amount</th><th></th></tr></thead>
              <tbody id="rows"></tbody></table></div>
            <div><button type="button" class="btn btn-sm btn-outline-secondary" id="addRow" data-no-loading><i class="bi bi-plus"></i> Add line</button></div>
            <div class="text-muted small mt-2" id="emptyMsg">Tap a product above to add it.</div>
          </div>
        </div>
        <?php endif; ?>
        <div class="<?= $open ? 'col-lg-5' : 'col-lg-6' ?>">
          <div class="card p-3">
            <?php if ($open): ?>
            <div class="mb-3"><label class="form-label">Customer</label>
              <select name="customer_id" id="cust" class="form-select"><option value="">Walk-in (no record)</option>
                <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$o['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['customer_code']) ?>)</option><?php endforeach; ?>
              </select></div>
            <div id="newCust" class="mb-3 border rounded p-2 bg-light">
              <div class="small fw-semibold mb-1">Or register a new customer (optional)</div>
              <input name="new_customer_name" class="form-control form-control-sm mb-1" placeholder="Name" maxlength="120" value="<?= e($formData['new_customer_name'] ?? '') ?>">
              <input name="new_customer_contact" class="form-control form-control-sm" placeholder="Contact number" maxlength="25" value="<?= e($formData['new_customer_contact'] ?? '') ?>">
            </div>
            <div class="row">
              <div class="col-6 mb-3"><label class="form-label">Payment</label><select name="payment_method" class="form-select"><?= select_options(PAYMENT_METHODS, $o['payment_method']) ?></select></div>
              <div class="col-6 mb-3"><label class="form-label">Discount (₱)</label><input name="discount" id="disc" class="form-control" inputmode="decimal" value="<?= e($o['discount']) ?>"></div>
            </div>
            <?php endif; ?>
            <div class="mb-3"><label class="form-label">Notes</label><input name="notes" class="form-control" maxlength="255" value="<?= e($o['notes']) ?>"></div>
            <?php if ($open): ?>
            <table class="table table-sm mb-3">
              <tr><td>Subtotal</td><td class="text-end" id="tSub">₱0.00</td></tr>
              <tr><td>Discount</td><td class="text-end" id="tDisc">₱0.00</td></tr>
              <tr class="fs-5 fw-bold"><td>Total</td><td class="text-end text-primary" id="tTotal">₱0.00</td></tr>
            </table>
            <?php if ($action === 'new'): ?>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="complete_now" value="1" id="cn" <?= ($formData === null || !empty($formData['complete_now'])) ? 'checked' : '' ?>>
              <label class="form-check-label" for="cn">Paid &amp; released now (mark Completed and deduct stock)</label></div>
            <?php endif; endif; ?>
            <button class="btn btn-primary btn-lg w-100"><?= $action === 'new' ? 'Save sale' : 'Save changes' ?></button>
            <a class="btn btn-link w-100" href="<?= e($self) ?>">Cancel</a>
          </div>
        </div>
      </div>
    </form>
    <?php if ($open): ?>
    <script>
    (function () {
      var P = <?= json_encode($pj) ?>, PRE = <?= json_encode($prefill) ?>;
      var byId = {}; P.forEach(function (p) { byId[p.id] = p; });
      var rows = document.getElementById('rows'), emptyMsg = document.getElementById('emptyMsg');
      function peso(n) { return '₱' + n.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
      function recalc() {
        var sub = 0;
        rows.querySelectorAll('tr').forEach(function (tr) {
          var p = byId[tr.querySelector('select').value], q = parseInt(tr.querySelector('input').value, 10) || 0;
          var amt = p ? Math.round(p.price * 100) * q / 100 : 0;
          tr.querySelector('.price').textContent = p ? peso(p.price) : '';
          tr.querySelector('.amt').textContent = peso(amt); sub += Math.round(amt * 100);
        });
        var d = Math.round((parseFloat((document.getElementById('disc').value || '0').replace(/,/g, '')) || 0) * 100);
        d = Math.max(0, Math.min(d, sub));
        document.getElementById('tSub').textContent = peso(sub / 100);
        document.getElementById('tDisc').textContent = '- ' + peso(d / 100);
        document.getElementById('tTotal').textContent = peso((sub - d) / 100);
        emptyMsg.style.display = rows.children.length ? 'none' : '';
      }
      function addRow(pid, qty) {
        var tr = document.createElement('tr');
        var sel = '<select name="item_product[]" class="form-select"><option value="">- choose -</option>' +
          P.map(function (p) { return '<option value="' + p.id + '">' + p.name.replace(/</g, '&lt;') + '</option>'; }).join('') + '</select>';
        tr.innerHTML = '<td>' + sel + '</td><td><input type="number" name="item_qty[]" class="form-control" min="1" max="9999" value="' + (qty || 1) + '"></td>' +
          '<td class="text-end price"></td><td class="text-end amt"></td><td><button type="button" class="btn btn-sm btn-outline-danger" data-no-loading>&times;</button></td>';
        tr.querySelector('select').value = pid || '';
        tr.querySelector('select').addEventListener('change', recalc);
        tr.querySelector('input').addEventListener('input', recalc);
        tr.querySelector('button').addEventListener('click', function () { tr.remove(); recalc(); });
        rows.appendChild(tr); recalc();
      }
      document.getElementById('addRow').addEventListener('click', function () { addRow(); });
      document.getElementById('quick').addEventListener('click', function (ev) {
        var b = ev.target.closest('button'); if (!b) return;
        var id = b.getAttribute('data-id'), found = false;
        rows.querySelectorAll('tr').forEach(function (tr) {
          if (tr.querySelector('select').value === id) { var i = tr.querySelector('input'); i.value = (parseInt(i.value, 10) || 0) + 1; found = true; }
        });
        if (!found) addRow(id, 1); else recalc();
      });
      document.getElementById('disc').addEventListener('input', recalc);
      document.getElementById('orderForm').addEventListener('submit', function (ev) {
        if (!rows.children.length) { ev.preventDefault(); alert('Add at least one item.'); }
      });
      PRE.forEach(function (r) { addRow(r.pid, r.qty); });
      recalc();
    })();
    </script>
    <?php endif;
    page_footer();
    exit;
}

/* ---------------- List ---------------- */
$from = get('from');
$to = get('to');
$status = get('status');
$pay = get('payment');
$cust = get('customer');
$search = get('q');
$where = ['1=1'];
$params = [];
if ($from !== '' && valid_date($from)) { $where[] = 'o.order_date >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && valid_date($to)) { $where[] = 'o.order_date < ?'; $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'; }
if (in_array($status, ORDER_STATUSES, true)) { $where[] = 'o.status = ?'; $params[] = $status; }
if (in_array($pay, PAYMENT_METHODS, true)) { $where[] = 'o.payment_method = ?'; $params[] = $pay; }
if ($cust !== '') { $where[] = '(c.name LIKE ? OR c.customer_code LIKE ?)'; $params[] = "%$cust%"; $params[] = "%$cust%"; }
if ($search !== '') { $where[] = 'o.order_number LIKE ?'; $params[] = "%$search%"; }
$w = implode(' AND ', $where);
$base = "FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE $w";
$pg = pager((int)q("SELECT COUNT(*) $base", $params)->fetchColumn());
$rows = q("SELECT o.*, c.name cname $base ORDER BY o.order_date DESC, o.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();
$sum = q("SELECT COALESCE(SUM(CASE WHEN o.status='Completed' THEN o.total END),0) FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE $w", $params)->fetchColumn();

page_header('Orders', 'orders');
page_title('Orders', '<a class="btn btn-primary" href="' . e($self . '?action=new') . '"><i class="bi bi-plus-circle"></i> New Sale</a>');
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-6 col-md-2"><input type="date" name="from" class="form-control" value="<?= e($from) ?>" title="From date"></div>
  <div class="col-6 col-md-2"><input type="date" name="to" class="form-control" value="<?= e($to) ?>" title="To date"></div>
  <div class="col-6 col-md-2"><select name="status" class="form-select"><?= select_options(ORDER_STATUSES, $status, true, 'All statuses') ?></select></div>
  <div class="col-6 col-md-2"><select name="payment" class="form-select"><?= select_options(PAYMENT_METHODS, $pay, true, 'All payments') ?></select></div>
  <div class="col-6 col-md-2"><input name="customer" class="form-control" placeholder="Customer" value="<?= e($cust) ?>"></div>
  <div class="col-6 col-md-2"><input name="q" class="form-control" placeholder="Order #" value="<?= e($search) ?>"></div>
  <div class="col-12"><button class="btn btn-outline-primary">Filter</button> <a class="btn btn-link" href="<?= e($self) ?>">Reset</a>
    <span class="text-muted ms-3">Completed sales in this filter: <b><?= money($sum) ?></b></span></div>
</form>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state">No orders match. <a href="<?= e($self . '?action=new') ?>">Create a sale</a>.</div><?php else: ?>
<div class="table-responsive"><table class="table table-hover sortable-table">
  <thead><tr><th class="sortable">Order #</th><th class="sortable">Date</th><th class="sortable">Customer</th><th class="sortable text-end">Total</th><th class="sortable">Payment</th><th class="sortable">Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $o): ?>
    <tr class="<?= $o['status'] === 'Cancelled' ? 'text-decoration-line-through text-muted' : '' ?>">
      <td><a href="<?= e($self . '?action=view&id=' . $o['id']) ?>"><?= e($o['order_number']) ?></a></td>
      <td data-sort="<?= e($o['order_date']) ?>"><?= e(fdatetime($o['order_date'])) ?></td>
      <td><?= e($o['cname'] ?? 'Walk-in') ?></td>
      <td class="text-end" data-sort="<?= e($o['total']) ?>"><?= money($o['total']) ?></td>
      <td><?= e($o['payment_method']) ?></td><td><?= status_badge($o['status']) ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-outline-secondary btn-sm" href="<?= e($self . '?action=view&id=' . $o['id']) ?>">View</a>
        <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= e(url('modules/receipt.php?id=' . $o['id'])) ?>"><i class="bi bi-printer"></i></a>
        <?php if (order_is_open($o['status'])): ?><a class="btn btn-outline-primary btn-sm" href="<?= e($self . '?action=edit&id=' . $o['id']) ?>">Edit</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php render_pager($pg); endif; ?>
</div>
<?php page_footer();
