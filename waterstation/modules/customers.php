<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$action = get('action', 'list');
$self = url('modules/customers.php');

if (is_post()) {
    csrf_verify();
    $do = post('do');
    try {
        if ($do === 'save') {
            $id = (int)post('id');
            $name = post('name');
            $contact = post('contact_number');
            $address = post('address');
            $type = opt(CUSTOMER_TYPES, post('type'), '');
            $reg = post('registered_at', date('Y-m-d'));
            if ($name === '' || mb_strlen($name) > 120) throw new UserError('Customer name is required (max 120 characters).');
            if ($contact !== '' && !preg_match('/^[0-9+\-\s()]{7,25}$/', $contact)) throw new UserError('Contact number looks invalid. Use digits, spaces, + - ( ) only.');
            if (mb_strlen($address) > 255) throw new UserError('Address is too long (max 255).');
            if ($type === '') throw new UserError('Please choose a customer type.');
            if (!valid_date($reg)) throw new UserError('Registration date is invalid.');
            if ($id) {
                q('UPDATE customers SET name=?, contact_number=?, address=?, type=?, registered_at=? WHERE id=? AND is_deleted=0',
                    [$name, $contact ?: null, $address ?: null, $type, $reg, $id]);
                audit('customer_updated', 'customer', $id, $name);
                flash('success', 'Customer updated.');
                redirect('modules/customers.php?action=view&id=' . $id);
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $code = next_seq($pdo, 'customers', 'customer_code', 'CUST-', 4);
                q('INSERT INTO customers (customer_code,name,contact_number,address,type,registered_at) VALUES (?,?,?,?,?,?)',
                    [$code, $name, $contact ?: null, $address ?: null, $type, $reg]);
                $newId = (int)$pdo->lastInsertId();
                audit('customer_created', 'customer', $newId, "$code $name");
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            flash('success', "Customer $code registered.");
            redirect('modules/customers.php?action=view&id=' . $newId);
        } elseif ($do === 'delete') {
            $id = (int)post('id');
            $c = q('SELECT * FROM customers WHERE id = ? AND is_deleted = 0', [$id])->fetch();
            if (!$c) throw new UserError('Customer not found.');
            $orders = (int)q('SELECT COUNT(*) FROM orders WHERE customer_id = ?', [$id])->fetchColumn();
            if ($orders > 0) {
                q('UPDATE customers SET is_deleted = 1 WHERE id = ?', [$id]);
                audit('customer_archived', 'customer', $id, $c['name'] . " (has $orders orders; soft-deleted)");
                flash('success', 'Customer removed from the list. Their past orders were kept.');
            } else {
                q('DELETE FROM customers WHERE id = ?', [$id]);
                audit('customer_deleted', 'customer', $id, $c['name']);
                flash('success', 'Customer deleted.');
            }
            redirect('modules/customers.php');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        $action = post('id') ? 'edit' : 'new';
        $_GET['id'] = post('id');
        $formData = $_POST;
    }
}

if ($action === 'view') {
    $id = (int)get('id');
    $c = q('SELECT * FROM customers WHERE id = ? AND is_deleted = 0', [$id])->fetch();
    if (!$c) { flash('error', 'Customer not found.'); redirect('modules/customers.php'); }
    $orders = q('SELECT * FROM orders WHERE customer_id = ? ORDER BY order_date DESC LIMIT 200', [$id])->fetchAll();
    $totals = q("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM orders WHERE customer_id = ? AND status='Completed'", [$id])->fetch();
    page_header($c['name'], 'customers');
    page_title($c['customer_code'] . ' - ' . $c['name'],
        '<a class="btn btn-outline-primary btn-sm" href="' . e($self . '?action=edit&id=' . $id) . '">Edit</a> '
        . '<a class="btn btn-primary btn-sm" href="' . e(url('modules/orders.php?action=new&customer_id=' . $id)) . '">New order</a> '
        . confirm_form($self, ['do' => 'delete', 'id' => $id], 'Delete', 'Delete this customer? If they have orders, they are hidden but orders are kept.'));
    ?>
    <div class="row g-3">
      <div class="col-lg-4"><div class="card p-3">
        <dl class="mb-0">
          <dt>Type</dt><dd><?= e($c['type']) ?></dd>
          <dt>Contact</dt><dd><?= e($c['contact_number'] ?: '-') ?></dd>
          <dt>Address</dt><dd><?= e($c['address'] ?: '-') ?></dd>
          <dt>Registered</dt><dd><?= e(fdate($c['registered_at'])) ?></dd>
          <dt>Completed orders / total spent</dt><dd><?= (int)$totals['n'] ?> / <?= money($totals['t']) ?></dd>
        </dl></div></div>
      <div class="col-lg-8"><div class="card p-3"><h2 class="h5">Order history</h2>
        <?php if (!$orders): ?><div class="empty-state">No orders yet for this customer.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>Order #</th><th>Date</th><th class="text-end">Total</th><th>Payment</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($orders as $o): ?>
            <tr><td><a href="<?= e(url('modules/orders.php?action=view&id=' . $o['id'])) ?>"><?= e($o['order_number']) ?></a></td>
            <td><?= e(fdatetime($o['order_date'])) ?></td><td class="text-end"><?= money($o['total']) ?></td>
            <td><?= e($o['payment_method']) ?></td><td><?= status_badge($o['status']) ?></td></tr>
          <?php endforeach; ?></tbody></table></div><?php endif; ?>
      </div></div>
    </div>
    <?php
    page_footer();
    exit;
}

if ($action === 'new' || $action === 'edit') {
    $c = ['id' => '', 'name' => '', 'contact_number' => '', 'address' => '', 'type' => 'Residential', 'registered_at' => date('Y-m-d')];
    if ($action === 'edit') {
        $c = q('SELECT * FROM customers WHERE id = ? AND is_deleted = 0', [(int)get('id')])->fetch();
        if (!$c) { flash('error', 'Customer not found.'); redirect('modules/customers.php'); }
    }
    if (!empty($formData)) $c = array_merge($c, array_intersect_key($formData, $c));
    page_header($action === 'new' ? 'Add Customer' : 'Edit Customer', 'customers');
    page_title($action === 'new' ? 'Add Customer' : 'Edit Customer');
    ?>
    <div class="card p-4" style="max-width:640px">
      <form method="post" action="<?= e($self) ?>">
        <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <div class="mb-3"><label class="form-label">Full name *</label><input name="name" class="form-control" required maxlength="120" value="<?= e($c['name']) ?>"></div>
        <div class="row">
          <div class="col-md-6 mb-3"><label class="form-label">Contact number</label><input name="contact_number" class="form-control" maxlength="25" inputmode="tel" value="<?= e($c['contact_number']) ?>"></div>
          <div class="col-md-6 mb-3"><label class="form-label">Type *</label><select name="type" class="form-select"><?= select_options(CUSTOMER_TYPES, $c['type']) ?></select></div>
        </div>
        <div class="mb-3"><label class="form-label">Address</label><input name="address" class="form-control" maxlength="255" value="<?= e($c['address']) ?>"></div>
        <div class="mb-3"><label class="form-label">Registration date</label><input type="date" name="registered_at" class="form-control" value="<?= e($c['registered_at']) ?>"></div>
        <?php if (!$c['id']): ?><div class="form-text mb-3">A customer ID (e.g. CUST-0001) is generated automatically.</div><?php endif; ?>
        <button class="btn btn-primary">Save customer</button>
        <a class="btn btn-link" href="<?= e($self) ?>">Cancel</a>
      </form>
    </div>
    <?php
    page_footer();
    exit;
}

// List
$search = get('q');
$type = get('type');
$where = ['is_deleted = 0'];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE ? OR customer_code LIKE ? OR contact_number LIKE ? OR address LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($type, CUSTOMER_TYPES, true)) { $where[] = 'type = ?'; $params[] = $type; }
$w = implode(' AND ', $where);
$pg = pager((int)q("SELECT COUNT(*) FROM customers WHERE $w", $params)->fetchColumn());
$rows = q("SELECT * FROM customers WHERE $w ORDER BY id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();

page_header('Customers', 'customers');
page_title('Customers', '<a class="btn btn-primary" href="' . e($self . '?action=new') . '"><i class="bi bi-person-plus"></i> Add customer</a>');
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-md-5"><input name="q" class="form-control" placeholder="Search name, ID, contact, address" value="<?= e($search) ?>"></div>
  <div class="col-md-3"><select name="type" class="form-select"><?= select_options(CUSTOMER_TYPES, $type, true, 'All types') ?></select></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Search</button> <a class="btn btn-link" href="<?= e($self) ?>">Reset</a></div>
</form>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state">No customers found. <a href="<?= e($self . '?action=new') ?>">Add your first customer</a>.</div><?php else: ?>
  <div class="table-responsive"><table class="table table-hover sortable-table">
    <thead><tr><th class="sortable">ID</th><th class="sortable">Name</th><th>Contact</th><th class="sortable">Type</th><th class="sortable">Registered</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $c): ?>
      <tr><td><?= e($c['customer_code']) ?></td><td><a href="<?= e($self . '?action=view&id=' . $c['id']) ?>"><?= e($c['name']) ?></a></td>
      <td><?= e($c['contact_number']) ?></td><td><?= e($c['type']) ?></td><td data-sort="<?= e($c['registered_at']) ?>"><?= e(fdate($c['registered_at'])) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($self . '?action=view&id=' . $c['id']) ?>">View</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e($self . '?action=edit&id=' . $c['id']) ?>">Edit</a>
        <?= confirm_form($self, ['do' => 'delete', 'id' => $c['id']], 'Delete', 'Delete this customer? If they have orders, they are hidden but orders are kept.') ?>
      </td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php render_pager($pg); endif; ?>
</div>
<?php page_footer();
