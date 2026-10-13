<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
if (is_file(ROOT . '/vendor/autoload.php')) {
    require_once ROOT . '/vendor/autoload.php';
}
date_default_timezone_set('Asia/Manila');

/** Errors meant to be shown to the user (validation, business rules). */
class UserError extends RuntimeException {}

/* ---------- Environment ---------- */
function load_env(string $file): array
{
    $out = [];
    if (!is_file($file)) return $out;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim(trim($v), "\"'");
    }
    return $out;
}
$GLOBALS['ENV'] = load_env(ROOT . '/config/.env');
function env(string $k, $default = null)
{
    if (isset($GLOBALS['ENV'][$k])) return $GLOBALS['ENV'][$k];
    $v = getenv($k);               // hosting platforms (Render, Docker) provide real environment variables
    return $v !== false ? $v : $default;
}

set_exception_handler(function (Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
    $msg = env('APP_DEBUG', '0') === '1' ? $e->getMessage()
        : 'Something went wrong. Please try again or contact the administrator.';
    echo '<div style="font-family:sans-serif;max-width:600px;margin:3rem auto"><h3>Error</h3><p>'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div>';
});

/* ---------- Base URL ---------- */
$__base = (string)env('APP_URL', '');
if ($__base === '') {
    $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $root = realpath(ROOT) ?: ROOT;
    $__base = ($doc !== '' && strpos($root, $doc) === 0) ? str_replace('\\', '/', substr($root, strlen($doc))) : '';
}
define('BASE_URL', rtrim($__base, '/'));
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/* ---------- Session ---------- */
$__https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $__https, 'httponly' => true, 'samesite' => 'Lax']);
session_name('WSRMSSESS');
session_start();
if (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] > 7200) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_active'] = time();

/* ---------- Database ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!is_file(ROOT . '/config/.env') && !env('DB_NAME')) {
            throw new RuntimeException('config/.env not found. Copy config/.env.example to config/.env and set your database details.');
        }
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', 'localhost'), env('DB_PORT', '3306'), env('DB_NAME', ''));
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (env('DB_SSL', '0') === '1') {   // many cloud MySQL providers require TLS
            $opts[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
            $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
        $pdo = new PDO($dsn, (string)env('DB_USER', ''), (string)env('DB_PASS', ''), $opts);
        $pdo->exec("SET time_zone = '+08:00'");
    }
    return $pdo;
}
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/* ---------- Output / formatting ---------- */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function money($v): string
{
    return '₱' . number_format((float)$v, 2);
}
function fdate($v): string
{
    return $v ? date('F j, Y', strtotime((string)$v)) : '';
}
function fdatetime($v): string
{
    return $v ? date('F j, Y g:i A', strtotime((string)$v)) : '';
}
function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}
function post(string $k, string $default = ''): string
{
    return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : $default;
}
function get(string $k, string $default = ''): string
{
    return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $default;
}
function valid_date(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}
function valid_datetime(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d\TH:i', $d);
    return $x && $x->format('Y-m-d\TH:i') === $d;
}
/** Parse a positive/zero decimal with 2 places; null when invalid. */
function parse_money($v, bool $allowZero = false): ?float
{
    if (!is_string($v) && !is_numeric($v)) return null;
    $v = str_replace(',', '', trim((string)$v));
    if (!is_numeric($v)) return null;
    $f = round((float)$v, 2);
    if ($f < 0 || ($f == 0 && !$allowZero) || $f > 99999999) return null;
    return $f;
}
function opt(array $list, string $v, string $default): string
{
    return in_array($v, $list, true) ? $v : $default;
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_verify(): void
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('Your session expired or the form was invalid. Please go back, refresh the page, and try again.');
    }
}
function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/* ---------- Auth ---------- */
function current_user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = isset($_SESSION['uid']) ? (q('SELECT id, username, full_name, must_change_password FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null) : null;
    }
    return $u;
}
function require_login(bool $allowPasswordPage = false): array
{
    $u = current_user();
    if (!$u) {
        unset($_SESSION['uid']);
        redirect('login.php');
    }
    if ($u['must_change_password'] && !$allowPasswordPage) {
        flash('warning', 'For security, please change the default password first.');
        redirect('modules/password.php');
    }
    return $u;
}
function uid(): ?int
{
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
}

/* ---------- Audit / settings ---------- */
function audit(string $action, string $entity, $entityId = null, string $details = ''): void
{
    q('INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)',
        [uid(), $action, $entity, $entityId === null ? null : (string)$entityId, mb_substr($details, 0, 500), $_SERVER['REMOTE_ADDR'] ?? null]);
}
function setting(string $key, string $default = ''): string
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach (q('SELECT setting_key, setting_value FROM system_settings')->fetchAll() as $r) $all[$r['setting_key']] = $r['setting_value'];
        } catch (Throwable $e) {
        }
    }
    return $all[$key] ?? $default;
}

/* ---------- Pagination ---------- */
function pager(int $total, int $per = 15): array
{
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    return ['page' => $page, 'pages' => $pages, 'per' => $per, 'offset' => ($page - 1) * $per, 'total' => $total];
}
function render_pager(array $p): void
{
    echo '<div class="d-flex justify-content-between align-items-center mt-2"><small class="text-muted">'
        . number_format($p['total']) . ' record(s)</small>';
    if ($p['pages'] > 1) {
        $q = $_GET;
        echo '<nav><ul class="pagination pagination-sm mb-0">';
        for ($i = max(1, $p['page'] - 3); $i <= min($p['pages'], $p['page'] + 3); $i++) {
            $q['page'] = $i;
            echo '<li class="page-item' . ($i === $p['page'] ? ' active' : '') . '"><a class="page-link" href="?' . e(http_build_query($q)) . '">' . $i . '</a></li>';
        }
        echo '</ul></nav>';
    }
    echo '</div>';
}

/* ---------- Uploads (images only) ---------- */
function save_upload(string $field, string $sub): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new UserError('Photo upload failed. Please try again.');
    if ($f['size'] > 3 * 1024 * 1024) throw new UserError('Photo must be 3 MB or smaller.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) throw new UserError('Only JPG, PNG or WEBP photos are allowed.');
    $dir = ROOT . '/uploads/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new UserError('Could not save the uploaded photo.');
    return $sub . '/' . $name;
}
function delete_upload(?string $rel): void
{
    if ($rel && preg_match('#^(deliveries|expenses)/[a-f0-9]+\.(jpg|png|webp)$#', $rel)) @unlink(ROOT . '/uploads/' . $rel);
}

/* ---------- CSV / PDF output ---------- */
function output_csv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r));
    fclose($out);
    exit;
}
/**
 * Real PDF when Dompdf is installed (composer install). Otherwise opens the same
 * page in print mode so the browser's "Save as PDF" can be used.
 */
function output_pdf(string $html, string $filename): void
{
    if (class_exists('Dompdf\Dompdf')) {
        $d = new Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $d->loadHtml($html);
        $d->setPaper('A4');
        $d->render();
        $d->stream($filename, ['Attachment' => true]);
        exit;
    }
    echo $html . '<script>window.onload=function(){window.print();}</script>';
    exit;
}

require_once __DIR__ . '/logic.php';
require_once __DIR__ . '/layout.php';
