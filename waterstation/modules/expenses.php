<?php
require __DIR__ . '/../includes/bootstrap.php';
require_login();
$action = get('action', 'list');
$self = url('modules/expenses.php');
$formData = null;

if (is_post()) {
    csrf_verify();
    $do = post('do');
    try {
        if ($do === 'save') {
            $id = (int)post('id');
            $cat = opt(EXPENSE_CATEGORIES, post('category'), '');
            $amount = parse_money(post('amount'));
            $date = post('expense_date');
            $desc = post('description');
            if ($cat === '') throw new UserError('Choose a category.');
            if ($amount === null) throw new UserError('Enter a valid amount greater than zero.');
            if (!valid_date($date)) throw new UserError('Enter a valid date.');
            if (mb_strlen($desc) > 255) throw new UserError('Description is too long (max 255).');
            $photo = save_upload('receipt', 'expenses');
            if ($id) {
                $old = q('SELECT * FROM expenses WHERE id = ?', [$id])->fetch();
                if (!$old) { delete_upload($photo); throw new UserError('Expense not found.'); }
                q('UPDATE expenses SET category=?, amount=?, expense_date=?, description=?, receipt_path=COALESCE(?, receipt_path) WHERE id=?', [$cat, $amount, $date, $desc ?: null, $photo, $id]);
                if ($photo) delete_upload($old['receipt_path']);
                audit('expense_updated', 'expense', $id, "$cat " . number_format($amount, 2));
                flash('success', 'Expense updated.');
            } else {
                q('INSERT INTO expenses (category, amount, expense_date, description, receipt_path) VALUES (?,?,?,?,?)', [$cat, $amount, $date, $desc ?: null, $photo]);
                audit('expense_created', 'expense', db()->lastInsertId(), "$cat " . number_format($amount, 2));
                flash('success', 'Expense recorded.');
            }
            redirect('modules/expenses.php');
        } elseif ($do === 'delete') {
            $id = (int)post('id');
            $old = q('SELECT * FROM expenses WHERE id = ?', [$id])->fetch();
            if (!$old) throw new UserError('Expense not found.');
            q('DELETE FROM expenses WHERE id = ?', [$id]);
            delete_upload($old['receipt_path']);
            audit('expense_deleted', 'expense', $id, "{$old['category']} " . number_format((float)$old['amount'], 2) . ' ' . $old['expense_date']);
            flash('success', 'Expense deleted.');
            redirect('modules/expenses.php');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        if ($do === 'save') { $action = post('id') ? 'edit' : 'new'; $_GET['id'] = post('id'); $formData = $_POST; }
        else redirect('modules/expenses.php');
    }
}

if ($action === 'new' || $action === 'edit') {
    $x = ['id' => '', 'category' => 'Supplies', 'amount' => '', 'expense_date' => date('Y-m-d'), 'description' => '', 'receipt_path' => ''];
    if ($action === 'edit') {
        $x = q('SELECT * FROM expenses WHERE id = ?', [(int)get('id')])->fetch();
        if (!$x) { flash('error', 'Expense not found.'); redirect('modules/expenses.php'); }
    }
    if ($formData) $x = array_merge($x, array_intersect_key($formData, $x));
    page_header('Expense', 'expenses');
    page_title($action === 'new' ? 'Add Expense' : 'Edit Expense');
    ?>
    <div class="card p-4" style="max-width:600px"><form method="post" action="<?= e($self) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= e($x['id']) ?>">
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Category *</label><select name="category" class="form-select"><?= select_options(EXPENSE_CATEGORIES, $x['category']) ?></select></div>
        <div class="col-md-6 mb-3"><label class="form-label">Date *</label><input type="date" name="expense_date" class="form-control" required value="<?= e($x['expense_date']) ?>"></div>
      </div>
      <div class="mb-3"><label class="form-label">Amount (₱) *</label><input name="amount" class="form-control" required inputmode="decimal" value="<?= e($x['amount']) ?>"></div>
      <div class="mb-3"><label class="form-label">Description</label><input name="description" class="form-control" maxlength="255" value="<?= e($x['description']) ?>"></div>
      <div class="mb-3"><label class="form-label">Receipt photo (optional)</label><input type="file" name="receipt" class="form-control" accept="image/jpeg,image/png,image/webp">
        <?php if ($x['receipt_path']): ?><div class="form-text">Current: <a target="_blank" href="<?= e(url('uploads/' . $x['receipt_path'])) ?>">view</a>. Uploading a new one replaces it.</div><?php endif; ?></div>
      <button class="btn btn-primary">Save expense</button> <a class="btn btn-link" href="<?= e($self) ?>">Cancel</a>
    </form></div>
    <?php
    page_footer();
    exit;
}

$from = get('from', date('Y-m-01')); $to = get('to', date('Y-m-d')); $cat = get('category'); $search = get('q');
if (array_key_exists('from', $_GET) && $_GET['from'] === '') $from = '';
if (array_key_exists('to', $_GET) && $_GET['to'] === '') $to = '';
$where = ['1=1']; $params = [];
if ($from !== '' && valid_date($from)) { $where[] = 'expense_date >= ?'; $params[] = $from; }
if ($to !== '' && valid_date($to)) { $where[] = 'expense_date <= ?'; $params[] = $to; }
if (in_array($cat, EXPENSE_CATEGORIES, true)) { $where[] = 'category = ?'; $params[] = $cat; }
if ($search !== '') { $where[] = 'description LIKE ?'; $params[] = "%$search%"; }
$w = implode(' AND ', $where);
$pg = pager((int)q("SELECT COUNT(*) FROM expenses WHERE $w", $params)->fetchColumn());
$rows = q("SELECT * FROM expenses WHERE $w ORDER BY expense_date DESC, id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();
$total = (float)q("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE $w", $params)->fetchColumn();
$salesTotal = (float)q("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='Completed'" . ($from !== '' && valid_date($from) ? ' AND order_date >= ?' : '') . ($to !== '' && valid_date($to) ? ' AND order_date < ?' : ''),
    array_values(array_filter([($from !== '' && valid_date($from)) ? $from . ' 00:00:00' : null, ($to !== '' && valid_date($to)) ? date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00' : null], fn($v) => $v !== null)))->fetchColumn();

page_header('Expenses', 'expenses');
page_title('Expenses', '<a class="btn btn-primary" href="' . e($self . '?action=new') . '"><i class="bi bi-plus-lg"></i> Add expense</a>');
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-6 col-md-2"><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
  <div class="col-6 col-md-3"><select name="category" class="form-select"><?= select_options(EXPENSE_CATEGORIES, $cat, true, 'All categories') ?></select></div>
  <div class="col-6 col-md-3"><input name="q" class="form-control" placeholder="Search description" value="<?= e($search) ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Filter</button> <a class="btn btn-link" href="<?= e($self) ?>">This month</a></div>
</form>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card stat-card p-3"><div class="label">Total expenses (filtered)</div><div class="value"><?= money($total) ?></div></div></div>
  <div class="col-md-4"><div class="card stat-card p-3"><div class="label">Completed sales (same dates)</div><div class="value"><?= money($salesTotal) ?></div></div></div>
  <div class="col-md-4"><div class="card stat-card p-3"><div class="label">Estimated net income</div><div class="value <?= $salesTotal - $total < 0 ? 'text-danger' : '' ?>"><?= money($salesTotal - $total) ?></div></div></div>
</div>
<div class="card p-3">
<?php if (!$rows): ?><div class="empty-state">No expenses recorded for this filter.</div><?php else: ?>
<div class="table-responsive"><table class="table table-hover sortable-table"><thead><tr><th class="sortable">Date</th><th class="sortable">Category</th><th>Description</th><th class="sortable text-end">Amount</th><th>Receipt</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $x): ?><tr><td data-sort="<?= e($x['expense_date']) ?>"><?= e(fdate($x['expense_date'])) ?></td><td><?= e($x['category']) ?></td><td><?= e($x['description']) ?></td>
  <td class="text-end" data-sort="<?= e($x['amount']) ?>"><?= money($x['amount']) ?></td>
  <td><?= $x['receipt_path'] ? '<a target="_blank" href="' . e(url('uploads/' . $x['receipt_path'])) . '">View</a>' : '-' ?></td>
  <td class="text-end text-nowrap"><a class="btn btn-outline-primary btn-sm" href="<?= e($self . '?action=edit&id=' . $x['id']) ?>">Edit</a>
    <?= confirm_form($self, ['do' => 'delete', 'id' => $x['id']], 'Delete', 'Delete this expense permanently?') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php render_pager($pg); endif; ?>
</div>
<?php page_footer();
