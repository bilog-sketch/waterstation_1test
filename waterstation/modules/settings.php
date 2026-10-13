<?php
require __DIR__ . '/../includes/bootstrap.php';
require_login();

if (is_post()) {
    csrf_verify();
    $vals = ['station_name' => post('station_name'), 'station_address' => post('station_address'), 'station_contact' => post('station_contact'), 'receipt_footer' => post('receipt_footer')];
    if ($vals['station_name'] === '' || mb_strlen($vals['station_name']) > 100) {
        flash('error', 'Station name is required (max 100 characters).');
    } elseif (mb_strlen($vals['station_address']) > 255 || mb_strlen($vals['station_contact']) > 100 || mb_strlen($vals['receipt_footer']) > 255) {
        flash('error', 'One of the fields is too long.');
    } else {
        foreach ($vals as $k => $v) q('INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]);
        audit('settings_updated', 'settings', null, 'station details');
        flash('success', 'Settings saved.');
        redirect('modules/settings.php');
    }
}
$logs = q('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 50')->fetchAll();
page_header('Settings', 'settings');
page_title('Settings');
?>
<div class="row g-3">
  <div class="col-lg-5"><div class="card p-4"><h2 class="h6">Station details (shown on receipts)</h2>
    <form method="post"><?= csrf_field() ?>
      <div class="mb-3"><label class="form-label">Station name *</label><input name="station_name" class="form-control" required maxlength="100" value="<?= e($_POST['station_name'] ?? setting('station_name')) ?>"></div>
      <div class="mb-3"><label class="form-label">Address</label><input name="station_address" class="form-control" maxlength="255" value="<?= e($_POST['station_address'] ?? setting('station_address')) ?>"></div>
      <div class="mb-3"><label class="form-label">Contact</label><input name="station_contact" class="form-control" maxlength="100" value="<?= e($_POST['station_contact'] ?? setting('station_contact')) ?>"></div>
      <div class="mb-3"><label class="form-label">Receipt footer</label><input name="receipt_footer" class="form-control" maxlength="255" value="<?= e($_POST['receipt_footer'] ?? setting('receipt_footer')) ?>"></div>
      <button class="btn btn-primary">Save settings</button></form></div></div>
  <div class="col-lg-7"><div class="card p-3"><h2 class="h6">Audit log (latest 50)</h2>
    <div class="table-responsive" style="max-height:480px"><table class="table table-sm"><thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody>
    <?php foreach ($logs as $l): ?><tr><td class="text-nowrap"><?= e(fdatetime($l['created_at'])) ?></td><td><?= e($l['username']) ?></td><td><?= e($l['action']) ?></td><td class="small"><?= e($l['details']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>
<?php page_footer();
