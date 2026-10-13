<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$action = get('action', 'list');
$self = url('modules/deliveries.php');
$riders = q('SELECT * FROM riders WHERE is_active = 1 ORDER BY name')->fetchAll();
$formData = null;

if (is_post()) {
    csrf_verify();
    $do = post('do');
    try {
        if ($do === 'save') {
            $id = (int)post('id');
            $orderId = (int)post('order_id');
            $address = post('address');
            $contact = post('contact_number');
            $sched = post('scheduled_at');
            $fee = parse_money(post('delivery_fee') ?: '0', true);
            $remarks = post('remarks');
            $riderId = (int)post('rider_id') ?: null;
            $riderName = post('rider_name');
            if ($address === '' || mb_strlen($address) > 255) throw new UserError('Delivery address is required (max 255 characters).');
            if ($contact !== '' && !preg_match('/^[0-9+\-\s()]{7,25}$/', $contact)) throw new UserError('Contact number looks invalid.');
            if (!valid_datetime($sched)) throw new UserError('Choose a valid delivery date and time.');
            if ($fee === null) throw new UserError('Delivery fee must be a valid amount.');
            if (mb_strlen($remarks) > 255 || mb_strlen($riderName) > 100) throw new UserError('Remarks or rider name is too long.');
            if ($riderId) {
                $r = q('SELECT name FROM riders WHERE id = ?', [$riderId])->fetch();
                if (!$r) throw new UserError('Selected rider not found.');
                $riderName = $r['name'];
            }
            $schedDb = str_replace('T', ' ', $sched) . ':00';
            if ($id) {
                $d = q('SELECT * FROM deliveries WHERE id = ?', [$id])->fetch();
                if (!$d) throw new UserError('Delivery not found.');
                if ($d['status'] === 'Delivered') throw new UserError('A delivered delivery can no longer be edited.');
                q('UPDATE deliveries SET address=?, contact_number=?, rider_id=?, rider_name=?, scheduled_at=?, delivery_fee=?, remarks=? WHERE id=?',
                    [$address, $contact ?: null, $riderId, $riderName ?: null, $schedDb, $fee, $remarks ?: null, $id]);
                audit('delivery_updated', 'delivery', $id, "order {$d['order_id']}");
                flash('success', 'Delivery updated.');
                redirect('modules/deliveries.php?action=view&id=' . $id);
            }
            $o = q('SELECT id, order_number, status FROM orders WHERE id = ?', [$orderId])->fetch();
            if (!$o) throw new UserError('Choose an order for this delivery.');
            if (!order_is_open($o['status'])) throw new UserError('Deliveries can only be scheduled for open orders (not completed or cancelled).');
            if (q('SELECT id FROM deliveries WHERE order_id = ?', [$orderId])->fetch()) throw new UserError('This order already has a delivery.');
            q('INSERT INTO deliveries (order_id,address,contact_number,rider_id,rider_name,scheduled_at,delivery_fee,remarks) VALUES (?,?,?,?,?,?,?,?)',
                [$orderId, $address, $contact ?: null, $riderId, $riderName ?: null, $schedDb, $fee, $remarks ?: null]);
            $newId = (int)db()->lastInsertId();
            audit('delivery_scheduled', 'delivery', $newId, $o['order_number']);
            flash('success', 'Delivery scheduled for ' . $o['order_number'] . '.');
            redirect('modules/deliveries.php?action=view&id=' . $newId);
        } elseif ($do === 'status') {
            $id = (int)post('id');
            $new = opt(DELIVERY_STATUSES, post('status'), '');
            if ($new === '' || $new === 'Delivered') throw new UserError('Invalid status.');
            change_delivery_status(db(), $id, $new, uid());
            flash('success', 'Delivery status changed to ' . $new . '.');
            redirect('modules/deliveries.php?action=view&id=' . $id);
        } elseif ($do === 'deliver') {
            $id = (int)post('id');
            $by = post('received_by');
            $notes = post('notes');
            if ($by === '' || mb_strlen($by) > 100) throw new UserError('Enter the name of the person who received the delivery.');
            if (mb_strlen($notes) > 255) throw new UserError('Notes are too long.');
            $photo = save_upload('photo', 'deliveries');
            try {
                change_delivery_status(db(), $id, 'Delivered', uid(), ['received_by' => $by, 'notes' => $notes ?: null, 'photo' => $photo]);
            } catch (Throwable $e) {
                delete_upload($photo);
                throw $e;
            }
            flash('success', 'Delivery confirmed and the order was marked Completed.');
            redirect('modules/deliveries.php?action=view&id=' . $id);
        } elseif ($do === 'rider_add') {
            $n = post('name');
            if ($n === '' || mb_strlen($n) > 100) throw new UserError('Rider name is required.');
            q('INSERT INTO riders (name, contact_number) VALUES (?,?)', [$n, post('contact_number') ?: null]);
            audit('rider_added', 'rider', db()->lastInsertId(), $n);
            flash('success', 'Rider added.');
            redirect('modules/deliveries.php?action=riders');
        } elseif ($do === 'rider_toggle') {
            q('UPDATE riders SET is_active = 1 - is_active WHERE id = ?', [(int)post('id')]);
            audit('rider_toggled', 'rider', (int)post('id'));
            redirect('modules/deliveries.php?action=riders');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        if ($do === 'save') {
            $action = post('id') ? 'edit' : 'new';
            $_GET['id'] = post('id');
            $formData = $_POST;
        } elseif ($do === 'rider_add' || $do === 'rider_toggle') {
            redirect('modules/deliveries.php?action=riders');
        } else {
            redirect('modules/deliveries.php?action=view&id=' . (int)post('id'));
        }
    }
}

if ($action === 'riders') {
    $all = q('SELECT * FROM riders ORDER BY is_active DESC, name')->fetchAll();
    page_header('Riders', 'deliveries');
    page_title('Riders', '<a class="btn btn-outline-secondary" href="' . e($self) . '">Back to deliveries</a>');
    ?>
    <div class="row g-3">
      <div class="col-lg-4"><div class="card p-3"><h2 class="h6">Add rider</h2>
        <form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="rider_add">
          <input name="name" class="form-control mb-2" placeholder="Rider name" required maxlength="100">
          <input name="contact_number" class="form-control mb-2" placeholder="Contact number (optional)" maxlength="30">
          <button class="btn btn-primary">Add rider</button></form></div></div>
      <div class="col-lg-8"><div class="card p-3">
        <?php if (!$all): ?><div class="empty-state">No riders yet. You can also just type a rider's name when scheduling a delivery.</div><?php else: ?>
        <table class="table"><thead><tr><th>Name</th><th>Contact</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($all as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['contact_number']) ?></td><td><?= status_badge($r['is_active'] ? 'active' : 'inactive') ?></td>
          <td class="text-end"><?= confirm_form($self, ['do' => 'rider_toggle', 'id' => $r['id']], $r['is_active'] ? 'Deactivate' : 'Activate', 'Change this rider\'s status?', 'btn-outline-secondary btn-sm') ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?></div></div>
    </div>
    <?php
    page_footer();
    exit;
}

if ($action === 'view') {
    $id = (int)get('id');
    $d = q('SELECT d.*, o.order_number, o.status ostatus, o.total, c.name cname FROM deliveries d JOIN orders o ON o.id = d.order_id LEFT JOIN customers c ON c.id = o.customer_id WHERE d.id = ?', [$id])->fetch();
    if (!$d) { flash('error', 'Delivery not found.'); redirect('modules/deliveries.php'); }
    page_header('Delivery', 'deliveries');
    page_title('Delivery for ' . $d['order_number'], $d['status'] !== 'Delivered' ? '<a class="btn btn-outline-primary btn-sm" href="' . e($self . '?action=edit&id=' . $id) . '">Edit</a>' : '');
    ?>
    <div class="row g-3">
      <div class="col-lg-6"><div class="card p-3"><dl class="mb-0">
        <dt>Status</dt><dd><?= status_badge($d['status']) ?> <span class="small text-muted">(order: <?= e($d['ostatus']) ?>)</span></dd>
        <dt>Order</dt><dd><a href="<?= e(url('modules/orders.php?action=view&id=' . $d['order_id'])) ?>"><?= e($d['order_number']) ?></a> - <?= e($d['cname'] ?? 'Walk-in') ?> - <?= money($d['total']) ?></dd>
        <dt>Address</dt><dd><?= e($d['address']) ?></dd>
        <dt>Contact</dt><dd><?= e($d['contact_number'] ?: '-') ?></dd>
        <dt>Rider</dt><dd><?= e($d['rider_name'] ?: '-') ?></dd>
        <dt>Scheduled</dt><dd><?= e(fdatetime($d['scheduled_at'])) ?></dd>
        <dt>Delivery fee</dt><dd><?= money($d['delivery_fee']) ?></dd>
        <dt>Remarks</dt><dd><?= e($d['remarks'] ?: '-') ?></dd>
      </dl></div></div>
      <div class="col-lg-6">
        <?php if ($d['status'] === 'Delivered'): ?>
          <div class="card p-3"><h2 class="h6">Delivery confirmation</h2><dl class="mb-0">
            <dt>Received by</dt><dd><?= e($d['received_by']) ?></dd><dt>Delivered at</dt><dd><?= e(fdatetime($d['delivered_at'])) ?></dd>
            <dt>Notes</dt><dd><?= e($d['confirmation_notes'] ?: '-') ?></dd>
            <?php if ($d['photo_path']): ?><dt>Photo</dt><dd><a href="<?= e(url('uploads/' . $d['photo_path'])) ?>" target="_blank"><img src="<?= e(url('uploads/' . $d['photo_path'])) ?>" class="img-thumbnail" style="max-width:220px" alt="Delivery photo"></a></dd><?php endif; ?>
          </dl></div>
        <?php else: ?>
          <div class="card p-3 mb-3"><h2 class="h6">Update status</h2>
            <?php foreach (delivery_transitions()[$d['status']] as $s): if ($s === 'Delivered') continue;
                echo confirm_form($self, ['do' => 'status', 'id' => $id, 'status' => $s], e($s), $s === 'Failed' ? 'Mark this delivery as Failed? The order goes back to Processing.' : 'Change delivery status to ' . $s . '?', $s === 'Failed' ? 'btn-outline-danger btn-sm' : 'btn-outline-primary btn-sm') . ' ';
            endforeach; ?>
            <div class="form-text mt-2">Order status follows the delivery: Preparing &rarr; Processing, Out for Delivery &rarr; Out for Delivery, Failed &rarr; Processing.</div>
          </div>
          <?php if (in_array('Delivered', delivery_transitions()[$d['status']], true)): ?>
          <div class="card p-3"><h2 class="h6">Confirm delivery</h2>
            <form method="post" action="<?= e($self) ?>" enctype="multipart/form-data" data-confirm="Confirm delivery? The order will be marked Completed and stock will be deducted.">
              <?= csrf_field() ?><input type="hidden" name="do" value="deliver"><input type="hidden" name="id" value="<?= $id ?>">
              <div class="mb-2"><label class="form-label">Received by *</label><input name="received_by" class="form-control" required maxlength="100"></div>
              <div class="mb-2"><label class="form-label">Notes</label><input name="notes" class="form-control" maxlength="255"></div>
              <div class="mb-3"><label class="form-label">Photo (optional, JPG/PNG/WEBP up to 3 MB)</label><input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp"></div>
              <button class="btn btn-success">Mark as Delivered</button>
            </form></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page_footer();
    exit;
}

if ($action === 'new' || $action === 'edit') {
    $d = ['id' => '', 'order_id' => (int)get('order_id'), 'address' => '', 'contact_number' => '', 'rider_id' => '', 'rider_name' => '', 'scheduled_at' => date('Y-m-d\TH:i', strtotime('+1 hour')), 'delivery_fee' => '0', 'remarks' => ''];
    $orders = [];
    if ($action === 'edit') {
        $row = q('SELECT * FROM deliveries WHERE id = ?', [(int)get('id')])->fetch();
        if (!$row || $row['status'] === 'Delivered') { flash('error', 'Delivery not found or already delivered.'); redirect('modules/deliveries.php'); }
        $d = array_merge($d, $row);
        $d['scheduled_at'] = date('Y-m-d\TH:i', strtotime($row['scheduled_at']));
        $oo = q('SELECT order_number FROM orders WHERE id = ?', [$row['order_id']])->fetch();
        $orders = [['id' => $row['order_id'], 'order_number' => $oo['order_number'], 'cname' => '']];
    } else {
        $orders = q("SELECT o.id, o.order_number, c.name cname, c.address, c.contact_number FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.status NOT IN ('Completed','Cancelled') AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.order_id = o.id) ORDER BY o.id DESC LIMIT 300")->fetchAll();
        foreach ($orders as $o) if ((int)$o['id'] === (int)$d['order_id'] && $o['address']) { $d['address'] = $o['address']; $d['contact_number'] = (string)$o['contact_number']; }
    }
    if ($formData) $d = array_merge($d, array_intersect_key($formData, $d));
    page_header('Schedule Delivery', 'deliveries');
    page_title($action === 'new' ? 'Schedule Delivery' : 'Edit Delivery');
    ?>
    <div class="card p-4" style="max-width:700px">
    <form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= e($d['id']) ?>">
      <div class="mb-3"><label class="form-label">Order *</label>
        <select name="order_id" class="form-select" required <?= $action === 'edit' ? 'disabled' : '' ?>>
          <option value="">- choose an open order -</option>
          <?php foreach ($orders as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$d['order_id'] === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['order_number']) ?> - <?= e($o['cname'] ?: 'Walk-in') ?></option><?php endforeach; ?>
        </select><?php if ($action === 'edit'): ?><input type="hidden" name="order_id" value="<?= (int)$d['order_id'] ?>"><?php endif; ?>
        <?php if ($action === 'new' && !$orders): ?><div class="form-text text-danger">No open orders without a delivery. Create an order first.</div><?php endif; ?></div>
      <div class="mb-3"><label class="form-label">Address *</label><input name="address" class="form-control" required maxlength="255" value="<?= e($d['address']) ?>"></div>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Contact number</label><input name="contact_number" class="form-control" maxlength="25" value="<?= e($d['contact_number']) ?>"></div>
        <div class="col-md-6 mb-3"><label class="form-label">Scheduled date &amp; time *</label><input type="datetime-local" name="scheduled_at" class="form-control" required value="<?= e($d['scheduled_at']) ?>"></div>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Rider (from list)</label><select name="rider_id" class="form-select"><option value="">- none / type a name -</option>
          <?php foreach ($riders as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$d['rider_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6 mb-3"><label class="form-label">...or rider name</label><input name="rider_name" class="form-control" maxlength="100" value="<?= e($d['rider_name']) ?>"></div>
      </div>
      <div class="row">
        <div class="col-md-4 mb-3"><label class="form-label">Delivery fee (₱)</label><input name="delivery_fee" class="form-control" inputmode="decimal" value="<?= e($d['delivery_fee']) ?>">
          <div class="form-text">Record only. To charge it, add the "Delivery fee" item to the order.</div></div>
        <div class="col-md-8 mb-3"><label class="form-label">Remarks</label><input name="remarks" class="form-control" maxlength="255" value="<?= e($d['remarks']) ?>"></div>
      </div>
      <button class="btn btn-primary">Save delivery</button> <a class="btn btn-link" href="<?= e($self) ?>">Cancel</a>
    </form></div>
    <?php
    page_footer();
    exit;
}

/* List / daily schedule */
if (!array_key_exists('from', $_GET) && !array_key_exists('to', $_GET)) { $_GET['from'] = $_GET['to'] = date('Y-m-d'); }
$from = get('from'); $to = get('to'); $status = get('status'); $rider = get('rider'); $cust = get('customer');
$where = ['1=1']; $params = [];
if ($from !== '' && valid_date($from)) { $where[] = 'd.scheduled_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && valid_date($to)) { $where[] = 'd.scheduled_at < ?'; $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'; }
if (in_array($status, DELIVERY_STATUSES, true)) { $where[] = 'd.status = ?'; $params[] = $status; }
if ($rider !== '') { $where[] = 'd.rider_name LIKE ?'; $params[] = "%$rider%"; }
if ($cust !== '') { $where[] = '(c.name LIKE ? OR c.customer_code LIKE ?)'; $params[] = "%$cust%"; $params[] = "%$cust%"; }
$w = implode(' AND ', $where);
$base = "FROM deliveries d JOIN orders o ON o.id = d.order_id LEFT JOIN customers c ON c.id = o.customer_id WHERE $w";
$pg = pager((int)q("SELECT COUNT(*) $base", $params)->fetchColumn());
$rows = q("SELECT d.*, o.order_number, c.name cname $base ORDER BY d.scheduled_at ASC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();

page_header('Deliveries', 'deliveries');
page_title('Deliveries', '<a class="btn btn-outline-secondary" href="' . e($self . '?action=riders') . '">Riders</a> <a class="btn btn-primary" href="' . e($self . '?action=new') . '"><i class="bi bi-truck"></i> Schedule delivery</a>');
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-6 col-md-2"><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
  <div class="col-6 col-md-2"><select name="status" class="form-select"><?= select_options(DELIVERY_STATUSES, $status, true, 'All statuses') ?></select></div>
  <div class="col-6 col-md-2"><input name="rider" class="form-control" placeholder="Rider" value="<?= e($rider) ?>"></div>
  <div class="col-6 col-md-2"><input name="customer" class="form-control" placeholder="Customer" value="<?= e($cust) ?>"></div>
  <div class="col-12"><button class="btn btn-outline-primary">Filter</button> <a class="btn btn-link" href="<?= e($self) ?>">Today</a> <a class="btn btn-link" href="<?= e($self . '?from=&to=') ?>">All dates</a></div>
</form>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state">No deliveries for this filter. <a href="<?= e($self . '?action=new') ?>">Schedule one</a>.</div><?php else: ?>
<div class="table-responsive"><table class="table table-hover sortable-table">
  <thead><tr><th class="sortable">When</th><th class="sortable">Order</th><th class="sortable">Customer</th><th>Address</th><th class="sortable">Rider</th><th class="sortable">Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $d): ?>
    <tr><td data-sort="<?= e($d['scheduled_at']) ?>"><?= e(fdatetime($d['scheduled_at'])) ?></td><td><a href="<?= e(url('modules/orders.php?action=view&id=' . $d['order_id'])) ?>"><?= e($d['order_number']) ?></a></td>
    <td><?= e($d['cname'] ?? 'Walk-in') ?></td><td><?= e($d['address']) ?></td><td><?= e($d['rider_name']) ?></td><td><?= status_badge($d['status']) ?></td>
    <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= e($self . '?action=view&id=' . $d['id']) ?>">Open</a></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php render_pager($pg); endif; ?>
</div>
<?php page_footer();
