<?php
declare(strict_types=1);

function nav_items(): array
{
    return [
        ['dashboard', 'Dashboard', 'modules/dashboard.php', 'bi-speedometer2'],
        ['sale', 'New Sale', 'modules/orders.php?action=new', 'bi-plus-circle-fill'],
        ['orders', 'Orders', 'modules/orders.php', 'bi-receipt'],
        ['deliveries', 'Deliveries', 'modules/deliveries.php', 'bi-truck'],
        ['customers', 'Customers', 'modules/customers.php', 'bi-people'],
        ['products', 'Products & Services', 'modules/products.php', 'bi-droplet'],
        ['inventory', 'Inventory', 'modules/inventory.php', 'bi-box-seam'],
        ['expenses', 'Expenses', 'modules/expenses.php', 'bi-cash-coin'],
        ['reports', 'Reports', 'modules/reports.php', 'bi-bar-chart-line'],
        ['settings', 'Settings', 'modules/settings.php', 'bi-gear'],
    ];
}

function page_header(string $title, string $active = '', bool $nav = true): void
{
    $station = setting('station_name', 'Water Station');
    $user = $nav ? current_user() : null;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e($station) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="<?= $nav ? '' : 'bg-login' ?>">
<?php if ($nav): ?>
<nav class="navbar navbar-dark bg-primary d-lg-none px-3 sticky-top">
  <span class="navbar-brand mb-0"><i class="bi bi-droplet-half"></i> <?= e($station) ?></span>
  <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
</nav>
<div class="d-lg-flex">
  <div class="offcanvas-lg offcanvas-start sidebar bg-primary text-white" tabindex="-1" id="sidebar">
    <div class="offcanvas-header d-lg-none">
      <h6 class="offcanvas-title"><?= e($station) ?></h6>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button>
    </div>
    <div class="p-3 d-none d-lg-block brand"><i class="bi bi-droplet-half fs-3"></i><div class="fw-bold"><?= e($station) ?></div></div>
    <ul class="nav flex-column px-2 pb-3">
      <?php foreach (nav_items() as [$key, $label, $href, $icon]): ?>
        <li class="nav-item"><a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><i class="bi <?= $icon ?>"></i> <?= e($label) ?></a></li>
      <?php endforeach; ?>
      <li class="nav-item mt-3 small px-3 text-white-50"><?= e($user['full_name'] ?? '') ?></li>
      <li class="nav-item"><a class="nav-link" href="<?= e(url('modules/password.php')) ?>"><i class="bi bi-key"></i> Change password</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= e(url('logout.php')) ?>"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
    </ul>
  </div>
  <main class="flex-grow-1 p-3 p-lg-4 main-area">
<?php endif; ?>
<?php
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as [$type, $msg]) {
            $cls = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'][$type] ?? 'info';
            echo '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">' . e($msg)
                . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
        unset($_SESSION['flash']);
    }
}

function page_footer(bool $nav = true): void
{
    if ($nav) echo "  </main>\n</div>\n";
    ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php
}

/** Small helper for the "title + action button" row at top of pages. */
function page_title(string $title, string $actionsHtml = ''): void
{
    echo '<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><h1 class="h3 mb-0">' . e($title) . '</h1><div>' . $actionsHtml . '</div></div>';
}

function status_badge(string $s): string
{
    $map = ['Pending' => 'secondary', 'Processing' => 'info', 'Ready for Pickup' => 'primary', 'Out for Delivery' => 'warning', 'Completed' => 'success',
        'Cancelled' => 'danger', 'Scheduled' => 'secondary', 'Preparing' => 'info', 'Delivered' => 'success', 'Failed' => 'danger',
        'active' => 'success', 'inactive' => 'secondary'];
    return '<span class="badge text-bg-' . ($map[$s] ?? 'secondary') . '">' . e($s) . '</span>';
}

function select_options(array $options, ?string $selected, bool $blank = false, string $blankLabel = 'All'): string
{
    $h = $blank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($options as $o) $h .= '<option value="' . e($o) . '"' . ($o === $selected ? ' selected' : '') . '>' . e($o) . '</option>';
    return $h;
}

/** POST form with confirmation dialog (delete / cancel). */
function confirm_form(string $action, array $hidden, string $label, string $message, string $class = 'btn-outline-danger btn-sm'): string
{
    $h = '<form method="post" action="' . e($action) . '" class="d-inline" data-confirm="' . e($message) . '">' . csrf_field();
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h . '<button class="btn ' . $class . '">' . $label . '</button></form>';
}
