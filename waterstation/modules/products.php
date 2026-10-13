<?php
require __DIR__ . '/../includes/bootstrap.php';
require_login();
$action = get('action', 'list');
$self = url('modules/products.php');
$items = q('SELECT id, name FROM inventory_items WHERE is_active = 1 ORDER BY name')->fetchAll();

if (is_post()) {
    csrf_verify();
    $do = post('do');
    try {
        if ($do === 'save') {
            $id = (int)post('id');
            $name = post('name');
            $desc = post('description');
            $price = parse_money($_POST['unit_price'] ?? '', true);
            $track = post('track_stock') === '1' ? 1 : 0;
            $itemId = (int)post('inventory_item_id') ?: null;
            $status = opt(['active', 'inactive'], post('status'), 'active');
            if ($name === '' || mb_strlen($name) > 120) throw new UserError('Product name is required (max 120 characters).');
            if (mb_strlen($desc) > 255) throw new UserError('Description is too long (max 255).');
            if ($price === null) throw new UserError('Enter a valid unit price (0 or more).');
            if ($track && !$itemId) throw new UserError('Pick the inventory item that is deducted when this product is sold, or turn off stock tracking.');
            if (!$track) $itemId = null;
            if ($itemId && !q('SELECT id FROM inventory_items WHERE id = ?', [$itemId])->fetch()) throw new UserError('Selected inventory item does not exist.');
            if ($id) {
                $old = q('SELECT * FROM products WHERE id = ?', [$id])->fetch();
                if (!$old) throw new UserError('Product not found.');
                q('UPDATE products SET name=?, description=?, unit_price=?, track_stock=?, inventory_item_id=?, status=? WHERE id=?',
                    [$name, $desc ?: null, $price, $track, $itemId, $status, $id]);
                if (abs((float)$old['unit_price'] - $price) > 0.001) {
                    audit('price_changed', 'product', $id, "{$old['name']}: " . number_format((float)$old['unit_price'], 2) . ' -> ' . number_format($price, 2));
                }
                audit('product_updated', 'product', $id, $name);
                flash('success', 'Product updated. Past orders keep their original prices.');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $code = next_seq($pdo, 'products', 'product_code', 'PRD-', 4);
                    q('INSERT INTO products (product_code,name,description,unit_price,track_stock,inventory_item_id,status) VALUES (?,?,?,?,?,?,?)',
                        [$code, $name, $desc ?: null, $price, $track, $itemId, $status]);
                    audit('product_created', 'product', (int)$pdo->lastInsertId(), "$code $name @ " . number_format($price, 2));
                    $pdo->commit();
                } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
                flash('success', "Product $code added.");
            }
            redirect('modules/products.php');
        } elseif ($do === 'toggle') {
            $id = (int)post('id');
            $p = q('SELECT * FROM products WHERE id = ?', [$id])->fetch();
            if (!$p) throw new UserError('Product not found.');
            $new = $p['status'] === 'active' ? 'inactive' : 'active';
            q('UPDATE products SET status = ? WHERE id = ?', [$new, $id]);
            audit('product_' . ($new === 'active' ? 'activated' : 'deactivated'), 'product', $id, $p['name']);
            flash('success', $p['name'] . ' is now ' . $new . '.');
            redirect('modules/products.php');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        $action = post('id') ? 'edit' : 'new';
        $formData = $_POST;
    }
}

if ($action === 'new' || $action === 'edit') {
    $p = ['id' => '', 'name' => '', 'description' => '', 'unit_price' => '', 'track_stock' => 0, 'inventory_item_id' => '', 'status' => 'active'];
    if ($action === 'edit') {
        $p = q('SELECT * FROM products WHERE id = ?', [(int)get('id', post('id'))])->fetch();
        if (!$p) { flash('error', 'Product not found.'); redirect('modules/products.php'); }
    }
    if (!empty($formData)) $p = array_merge($p, array_intersect_key($formData, $p));
    page_header('Product', 'products');
    page_title($action === 'new' ? 'Add Product / Service' : 'Edit Product / Service');
    ?>
    <div class="card p-4" style="max-width:640px">
      <form method="post" action="<?= e($self) ?>">
        <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= e($p['id']) ?>">
        <div class="mb-3"><label class="form-label">Name *</label><input name="name" class="form-control" required maxlength="120" value="<?= e($p['name']) ?>"></div>
        <div class="mb-3"><label class="form-label">Description</label><input name="description" class="form-control" maxlength="255" value="<?= e($p['description']) ?>"></div>
        <div class="row">
          <div class="col-md-6 mb-3"><label class="form-label">Unit price (₱) *</label><input name="unit_price" class="form-control" required inputmode="decimal" value="<?= e($p['unit_price']) ?>">
            <?php if ($p['id']): ?><div class="form-text">Changing the price only affects new orders.</div><?php endif; ?></div>
          <div class="col-md-6 mb-3"><label class="form-label">Status</label><select name="status" class="form-select"><?= select_options(['active', 'inactive'], $p['status']) ?></select></div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3"><label class="form-label">Track stock?</label>
            <select name="track_stock" id="track" class="form-select"><option value="0" <?= !$p['track_stock'] ? 'selected' : '' ?>>No</option><option value="1" <?= $p['track_stock'] ? 'selected' : '' ?>>Yes</option></select></div>
          <div class="col-md-6 mb-3" id="itemWrap"><label class="form-label">Inventory item deducted per unit sold</label>
            <select name="inventory_item_id" class="form-select"><option value="">- choose -</option>
              <?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>" <?= (int)$p['inventory_item_id'] === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <button class="btn btn-primary">Save</button> <a class="btn btn-link" href="<?= e($self) ?>">Cancel</a>
      </form>
    </div>
    <script>
      var t = document.getElementById('track'), w = document.getElementById('itemWrap');
      function sync() { w.style.display = t.value === '1' ? '' : 'none'; } t.addEventListener('change', sync); sync();
    </script>
    <?php
    page_footer();
    exit;
}

$search = get('q');
$status = get('status');
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = '(p.name LIKE ? OR p.product_code LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if (in_array($status, ['active', 'inactive'], true)) { $where[] = 'p.status = ?'; $params[] = $status; }
$w = implode(' AND ', $where);
$pg = pager((int)q("SELECT COUNT(*) FROM products p WHERE $w", $params)->fetchColumn());
$rows = q("SELECT p.*, i.name inv_name FROM products p LEFT JOIN inventory_items i ON i.id = p.inventory_item_id WHERE $w ORDER BY p.status, p.name LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();

page_header('Products & Services', 'products');
page_title('Products & Services', '<a class="btn btn-primary" href="' . e($self . '?action=new') . '"><i class="bi bi-plus-lg"></i> Add product</a>');
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-md-5"><input name="q" class="form-control" placeholder="Search name or ID" value="<?= e($search) ?>"></div>
  <div class="col-md-3"><select name="status" class="form-select"><?= select_options(['active', 'inactive'], $status, true, 'All statuses') ?></select></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Search</button> <a class="btn btn-link" href="<?= e($self) ?>">Reset</a></div>
</form>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state">No products yet.</div><?php else: ?>
<div class="table-responsive"><table class="table table-hover sortable-table">
  <thead><tr><th class="sortable">ID</th><th class="sortable">Name</th><th class="sortable text-end">Price</th><th>Stock tracking</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $p): ?>
    <tr><td><?= e($p['product_code']) ?></td>
    <td><?= e($p['name']) ?><div class="small text-muted"><?= e($p['description']) ?></div></td>
    <td class="text-end" data-sort="<?= e($p['unit_price']) ?>"><?= money($p['unit_price']) ?></td>
    <td><?= $p['track_stock'] ? 'Yes <span class="small text-muted">(' . e($p['inv_name']) . ')</span>' : 'No' ?></td>
    <td><?= status_badge($p['status']) ?></td>
    <td class="text-end text-nowrap">
      <a class="btn btn-outline-primary btn-sm" href="<?= e($self . '?action=edit&id=' . $p['id']) ?>">Edit / price</a>
      <?= confirm_form($self, ['do' => 'toggle', 'id' => $p['id']], $p['status'] === 'active' ? 'Deactivate' : 'Activate',
          $p['status'] === 'active' ? 'Deactivate this product? It will no longer appear when creating orders.' : 'Activate this product?', 'btn-outline-secondary btn-sm') ?>
    </td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php render_pager($pg); endif; ?>
</div>
<?php page_footer();
