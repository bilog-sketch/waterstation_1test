<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$action = get('action', 'list');
$self = url('modules/inventory.php');
$formData = null;

if (is_post()) {
    csrf_verify();
    $do = post('do');
    try {
        if ($do === 'item_save') {
            $id = (int)post('id');
            $name = post('name');
            $cat = opt(INVENTORY_CATEGORIES, post('category'), '');
            $unit = post('unit', 'pcs');
            $min = parse_money(post('min_level') ?: '0', true);
            $qty = parse_money(post('quantity') ?: '0', true);
            if ($name === '' || mb_strlen($name) > 100) throw new UserError('Item name is required (max 100 characters).');
            if ($cat === '') throw new UserError('Choose a category.');
            if ($unit === '' || mb_strlen($unit) > 20) throw new UserError('Unit is required (e.g. pcs).');
            if ($min === null) throw new UserError('Minimum level must be a valid number.');
            if (!$id && $qty === null) throw new UserError('Opening quantity must be a valid number.');
            $dup = q('SELECT id FROM inventory_items WHERE name = ? AND id <> ?', [$name, $id])->fetch();
            if ($dup) throw new UserError('An inventory item with that name already exists.');
            if ($id) {
                q('UPDATE inventory_items SET name=?, category=?, unit=?, min_level=? WHERE id=?', [$name, $cat, $unit, $min, $id]);
                audit('inventory_item_updated', 'inventory_item', $id, "$name min=$min");
                flash('success', 'Item updated.');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    q('INSERT INTO inventory_items (name, category, unit, quantity, min_level) VALUES (?,?,?,?,?)', [$name, $cat, $unit, $qty, $min]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($qty > 0) q('INSERT INTO inventory_transactions (item_id,type,quantity,balance_after,txn_date,remarks,user_id) VALUES (?,\'in\',?,?,CURDATE(),\'Opening balance\',?)', [$newId, $qty, $qty, uid()]);
                    audit('inventory_item_created', 'inventory_item', $newId, "$name opening=$qty");
                    $pdo->commit();
                } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
                flash('success', 'Item added.');
            }
            redirect('modules/inventory.php');
        } elseif ($do === 'toggle') {
            q('UPDATE inventory_items SET is_active = 1 - is_active WHERE id = ?', [(int)post('id')]);
            audit('inventory_item_toggled', 'inventory_item', (int)post('id'));
            redirect('modules/inventory.php');
        } elseif ($do === 'move') {
            $itemId = (int)post('item_id');
            $type = opt(['in', 'out'], post('type'), '');
            $qty = parse_money(post('quantity'));
            $date = post('txn_date', date('Y-m-d'));
            $remarks = post('remarks');
            if ($type === '') throw new UserError('Choose Stock in or Stock out.');
            if ($qty === null) throw new UserError('Quantity must be a number greater than zero.');
            if (!valid_date($date) || $date > date('Y-m-d')) throw new UserError('Date is invalid or in the future.');
            if (mb_strlen($remarks) > 255) throw new UserError('Remarks are too long.');
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $it = q('SELECT * FROM inventory_items WHERE id = ? FOR UPDATE', [$itemId])->fetch();
                if (!$it || !$it['is_active']) throw new UserError('Choose an active inventory item.');
                $bal = round((float)$it['quantity'] + ($type === 'in' ? $qty : -$qty), 2);
                if ($bal < 0) throw new UserError("Not enough stock. {$it['name']} has only " . (float)$it['quantity'] . " {$it['unit']}.");
                q('UPDATE inventory_items SET quantity = ? WHERE id = ?', [$bal, $itemId]);
                q('INSERT INTO inventory_transactions (item_id,type,quantity,balance_after,txn_date,remarks,user_id) VALUES (?,?,?,?,?,?,?)',
                    [$itemId, $type, $qty, $bal, $date, $remarks ?: null, uid()]);
                audit('stock_' . $type, 'inventory_item', $itemId, "{$it['name']} " . ($type === 'in' ? '+' : '-') . "$qty => $bal");
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            flash('success', 'Stock ' . ($type === 'in' ? 'received' : 'used') . ' recorded. New balance: ' . $bal);
            redirect('modules/inventory.php');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        if ($do === 'item_save') { $action = post('id') ? 'edit' : 'new'; $_GET['id'] = post('id'); $formData = $_POST; }
        elseif ($do === 'move') { $action = 'move'; $formData = $_POST; }
        else redirect('modules/inventory.php');
    }
}

if ($action === 'new' || $action === 'edit') {
    $i = ['id' => '', 'name' => '', 'category' => 'Full gallons', 'unit' => 'pcs', 'quantity' => '0', 'min_level' => '0'];
    if ($action === 'edit') {
        $i = q('SELECT * FROM inventory_items WHERE id = ?', [(int)get('id')])->fetch();
        if (!$i) { flash('error', 'Item not found.'); redirect('modules/inventory.php'); }
    }
    if ($formData) $i = array_merge($i, array_intersect_key($formData, $i));
    page_header('Inventory item', 'inventory');
    page_title($action === 'new' ? 'Add Inventory Item' : 'Edit Inventory Item');
    ?>
    <div class="card p-4" style="max-width:600px"><form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="item_save"><input type="hidden" name="id" value="<?= e($i['id']) ?>">
      <div class="mb-3"><label class="form-label">Name *</label><input name="name" class="form-control" required maxlength="100" value="<?= e($i['name']) ?>"></div>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Category *</label><select name="category" class="form-select"><?= select_options(INVENTORY_CATEGORIES, $i['category']) ?></select></div>
        <div class="col-md-6 mb-3"><label class="form-label">Unit *</label><input name="unit" class="form-control" maxlength="20" value="<?= e($i['unit']) ?>"></div>
      </div>
      <div class="row">
        <?php if (!$i['id']): ?><div class="col-md-6 mb-3"><label class="form-label">Opening quantity</label><input name="quantity" class="form-control" inputmode="decimal" value="<?= e($i['quantity']) ?>"></div><?php endif; ?>
        <div class="col-md-6 mb-3"><label class="form-label">Minimum level (low-stock alert)</label><input name="min_level" class="form-control" inputmode="decimal" value="<?= e($i['min_level']) ?>"></div>
      </div>
      <?php if ($i['id']): ?><div class="form-text mb-3">To change the quantity, use Stock in / Stock out so it is recorded in the history.</div><?php endif; ?>
      <button class="btn btn-primary">Save</button> <a class="btn btn-link" href="<?= e($self) ?>">Cancel</a>
    </form></div>
    <?php
    page_footer();
    exit;
}

if ($action === 'move') {
    $items = q('SELECT id, name, unit, quantity FROM inventory_items WHERE is_active = 1 ORDER BY name')->fetchAll();
    $m = ['item_id' => get('item_id'), 'type' => get('type', 'in'), 'quantity' => '', 'txn_date' => date('Y-m-d'), 'remarks' => ''];
    if ($formData) $m = array_merge($m, array_intersect_key($formData, $m));
    page_header('Stock movement', 'inventory');
    page_title('Stock In / Out');
    ?>
    <div class="card p-4" style="max-width:600px"><form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="move">
      <div class="mb-3"><label class="form-label">Item *</label><select name="item_id" class="form-select" required><option value="">- choose -</option>
        <?php foreach ($items as $it): ?><option value="<?= (int)$it['id'] ?>" <?= (int)$m['item_id'] === (int)$it['id'] ? 'selected' : '' ?>><?= e($it['name']) ?> (<?= e((float)$it['quantity']) ?> <?= e($it['unit']) ?>)</option><?php endforeach; ?></select></div>
      <div class="row">
        <div class="col-md-4 mb-3"><label class="form-label">Type *</label><select name="type" class="form-select"><option value="in" <?= $m['type'] === 'in' ? 'selected' : '' ?>>Stock in (received)</option><option value="out" <?= $m['type'] === 'out' ? 'selected' : '' ?>>Stock out (used)</option></select></div>
        <div class="col-md-4 mb-3"><label class="form-label">Quantity *</label><input name="quantity" class="form-control" required inputmode="decimal" value="<?= e($m['quantity']) ?>"></div>
        <div class="col-md-4 mb-3"><label class="form-label">Date *</label><input type="date" name="txn_date" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e($m['txn_date']) ?>"></div>
      </div>
      <div class="mb-3"><label class="form-label">Remarks</label><input name="remarks" class="form-control" maxlength="255" value="<?= e($m['remarks']) ?>"></div>
      <button class="btn btn-primary">Save movement</button> <a class="btn btn-link" href="<?= e($self) ?>">Cancel</a>
    </form></div>
    <?php
    page_footer();
    exit;
}

if ($action === 'history') {
    $itemId = (int)get('item_id'); $type = get('type'); $from = get('from'); $to = get('to');
    $where = ['1=1']; $params = [];
    if ($itemId) { $where[] = 't.item_id = ?'; $params[] = $itemId; }
    if (in_array($type, ['in', 'out', 'order'], true)) { $where[] = 't.type = ?'; $params[] = $type; }
    if ($from !== '' && valid_date($from)) { $where[] = 't.txn_date >= ?'; $params[] = $from; }
    if ($to !== '' && valid_date($to)) { $where[] = 't.txn_date <= ?'; $params[] = $to; }
    $w = implode(' AND ', $where);
    $pg = pager((int)q("SELECT COUNT(*) FROM inventory_transactions t WHERE $w", $params)->fetchColumn(), 20);
    $rows = q("SELECT t.*, i.name, i.unit, u.full_name FROM inventory_transactions t JOIN inventory_items i ON i.id = t.item_id LEFT JOIN users u ON u.id = t.user_id WHERE $w ORDER BY t.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();
    $all = q('SELECT id, name FROM inventory_items ORDER BY name')->fetchAll();
    page_header('Inventory history', 'inventory');
    page_title('Inventory Movement History', '<a class="btn btn-outline-secondary" href="' . e($self) . '">Back to inventory</a>');
    ?>
    <form class="row g-2 mb-3" method="get"><input type="hidden" name="action" value="history">
      <div class="col-md-3"><select name="item_id" class="form-select"><option value="">All items</option><?php foreach ($all as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $itemId === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><select name="type" class="form-select"><option value="">All types</option><option value="in" <?= $type === 'in' ? 'selected' : '' ?>>Stock in</option><option value="out" <?= $type === 'out' ? 'selected' : '' ?>>Stock out</option><option value="order" <?= $type === 'order' ? 'selected' : '' ?>>Order deduction</option></select></div>
      <div class="col-md-2"><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
      <div class="col-md-2"><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-primary">Filter</button></div>
    </form>
    <div class="card p-3">
    <?php if (!$rows): ?><div class="empty-state">No movements recorded for this filter.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Balance</th><th>Remarks</th><th>By</th></tr></thead><tbody>
    <?php foreach ($rows as $t): ?><tr><td><?= e(fdate($t['txn_date'])) ?></td><td><?= e($t['name']) ?></td>
      <td><?= ['in' => '<span class="badge text-bg-success">Stock in</span>', 'out' => '<span class="badge text-bg-warning">Stock out</span>', 'order' => '<span class="badge text-bg-info">Order</span>', 'adjustment' => 'Adjustment'][$t['type']] ?></td>
      <td class="text-end"><?= $t['type'] === 'in' ? '+' : '-' ?><?= e((float)$t['quantity']) ?> <?= e($t['unit']) ?></td><td class="text-end"><?= e((float)$t['balance_after']) ?></td><td><?= e($t['remarks']) ?></td><td><?= e($t['full_name']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php render_pager($pg); endif; ?></div>
    <?php
    page_footer();
    exit;
}

$search = get('q'); $cat = get('category'); $low = get('low') === '1';
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = 'name LIKE ?'; $params[] = "%$search%"; }
if (in_array($cat, INVENTORY_CATEGORIES, true)) { $where[] = 'category = ?'; $params[] = $cat; }
if ($low) $where[] = 'is_active = 1 AND quantity <= min_level';
$w = implode(' AND ', $where);
$pg = pager((int)q("SELECT COUNT(*) FROM inventory_items WHERE $w", $params)->fetchColumn());
$rows = q("SELECT * FROM inventory_items WHERE $w ORDER BY is_active DESC, name LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();
$lowCount = (int)q('SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity <= min_level')->fetchColumn();

page_header('Inventory', 'inventory');
page_title('Inventory', '<a class="btn btn-outline-secondary" href="' . e($self . '?action=history') . '">History</a> <a class="btn btn-outline-primary" href="' . e($self . '?action=move') . '">Stock in / out</a> <a class="btn btn-primary" href="' . e($self . '?action=new') . '"><i class="bi bi-plus-lg"></i> Add item</a>');
if ($lowCount): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <?= $lowCount ?> item(s) at or below minimum level. <a href="<?= e($self . '?low=1') ?>">Show them</a></div><?php endif; ?>
<form class="row g-2 mb-3" method="get">
  <div class="col-md-4"><input name="q" class="form-control" placeholder="Search item" value="<?= e($search) ?>"></div>
  <div class="col-md-3"><select name="category" class="form-select"><?= select_options(INVENTORY_CATEGORIES, $cat, true, 'All categories') ?></select></div>
  <div class="col-auto form-check pt-2 ms-2"><input type="checkbox" class="form-check-input" name="low" value="1" id="low" <?= $low ? 'checked' : '' ?>><label for="low" class="form-check-label">Low stock only</label></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Filter</button> <a class="btn btn-link" href="<?= e($self) ?>">Reset</a></div>
</form>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state">No inventory items found.</div><?php else: ?>
<div class="table-responsive"><table class="table table-hover sortable-table"><thead><tr><th class="sortable">Item</th><th class="sortable">Category</th><th class="sortable text-end">In stock</th><th class="sortable text-end">Minimum</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $i): $isLow = $i['is_active'] && (float)$i['quantity'] <= (float)$i['min_level']; ?>
  <tr class="<?= $i['is_active'] ? '' : 'text-muted' ?>"><td><?= e($i['name']) ?></td><td><?= e($i['category']) ?></td>
  <td class="text-end <?= $isLow ? 'text-danger fw-bold' : '' ?>" data-sort="<?= e($i['quantity']) ?>"><?= e((float)$i['quantity']) ?> <?= e($i['unit']) ?></td>
  <td class="text-end"><?= e((float)$i['min_level']) ?></td>
  <td><?= !$i['is_active'] ? status_badge('inactive') : ($isLow ? '<span class="badge text-bg-danger">Low stock</span>' : '<span class="badge text-bg-success">OK</span>') ?></td>
  <td class="text-end text-nowrap"><a class="btn btn-outline-success btn-sm" href="<?= e($self . '?action=move&type=in&item_id=' . $i['id']) ?>">+ In</a>
    <a class="btn btn-outline-warning btn-sm" href="<?= e($self . '?action=move&type=out&item_id=' . $i['id']) ?>">- Out</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e($self . '?action=history&item_id=' . $i['id']) ?>">History</a>
    <a class="btn btn-outline-primary btn-sm" href="<?= e($self . '?action=edit&id=' . $i['id']) ?>">Edit</a>
    <?= confirm_form($self, ['do' => 'toggle', 'id' => $i['id']], $i['is_active'] ? 'Deactivate' : 'Activate', 'Change this item\'s active status?', 'btn-outline-secondary btn-sm') ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php render_pager($pg); endif; ?>
</div>
<?php page_footer();
