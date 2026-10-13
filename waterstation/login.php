<?php
require __DIR__ . '/includes/bootstrap.php';

if (uid() && current_user()) redirect('modules/dashboard.php');

// First run: create the default administrator (password must be changed on first login).
if ((int)q('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    q('INSERT INTO users (username, password_hash, full_name, must_change_password) VALUES (?,?,?,1)',
        ['admin', password_hash('ChangeMe@123', PASSWORD_DEFAULT), 'Administrator']);
}

$error = '';
if (is_post()) {
    csrf_verify();
    $now = time();
    if (($_SESSION['lock_until'] ?? 0) > $now) {
        $error = 'Too many failed attempts. Please wait a minute and try again.';
    } else {
        $u = q('SELECT * FROM users WHERE username = ?', [post('username')])->fetch();
        if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['fails'] = 0;
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $u['id']]);
            }
            q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$u['id']]);
            audit('login', 'user', $u['id']);
            redirect($u['must_change_password'] ? 'modules/password.php' : 'modules/dashboard.php');
        }
        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= 5) { $_SESSION['lock_until'] = $now + 60; $_SESSION['fails'] = 0; }
        usleep(400000);
        $error = 'Incorrect username or password.';
    }
}
page_header('Login', '', false);
?>
<div class="container">
  <div class="row justify-content-center align-items-center" style="min-height:100vh">
    <div class="col-11 col-sm-8 col-md-5 col-lg-4">
      <div class="card p-4">
        <div class="text-center mb-3">
          <i class="bi bi-droplet-half text-primary" style="font-size:2.5rem"></i>
          <h1 class="h5 mt-2"><?= e(setting('station_name', 'Water Station')) ?></h1>
          <div class="text-muted small">Record Management System</div>
        </div>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" required autofocus maxlength="50"></div>
          <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
          <button class="btn btn-primary w-100">Log in</button>
        </form>
        <?php if ((int)q('SELECT must_change_password FROM users WHERE username = \'admin\'')->fetchColumn() === 1): ?>
          <div class="small text-muted mt-3">First-time login: user <code>admin</code>, password <code>ChangeMe@123</code>. You will be asked to change it.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php page_footer(false);
