<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login(true);
$forced = (bool)$user['must_change_password'];

if (is_post()) {
    csrf_verify();
    $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
    $cur = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $err = null;
    if (!password_verify($cur, $row['password_hash'])) $err = 'Your current password is incorrect.';
    elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) $err = 'New password must be at least 8 characters and include letters and numbers.';
    elseif ($new !== ($_POST['confirm'] ?? '')) $err = 'New password and confirmation do not match.';
    elseif ($new === $cur) $err = 'New password must be different from the current one.';
    if ($err) flash('error', $err);
    else {
        q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        audit('password_changed', 'user', $user['id']);
        flash('success', 'Password changed successfully.');
        redirect('modules/dashboard.php');
    }
}
page_header('Change Password', '');
page_title('Change Password');
?>
<div class="card p-4" style="max-width:480px">
  <?php if ($forced): ?><div class="alert alert-warning">You are using the default password. Please set a new one to continue.</div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label">Current password</label><input type="password" name="current" class="form-control" required></div>
    <div class="mb-3"><label class="form-label">New password</label><input type="password" name="new" class="form-control" required minlength="8"><div class="form-text">At least 8 characters, with letters and numbers.</div></div>
    <div class="mb-3"><label class="form-label">Confirm new password</label><input type="password" name="confirm" class="form-control" required></div>
    <button class="btn btn-primary">Save password</button>
  </form>
</div>
<?php page_footer();
