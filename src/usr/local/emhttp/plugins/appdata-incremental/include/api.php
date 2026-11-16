<?php
/* WebGUI endpoint of Appdata Incremental. Accepts only POST (except the read-only container icon).
 * CSRF: on Unraid, local_prepend.php (auto_prepend_file) validates csrf_token for every POST, aborts on
 * failure and then REMOVES the token from $_POST. So we only check ourselves when that prepend did not run. */
declare(strict_types=1);

namespace Aib;

foreach (['Util', 'Config', 'Docker', 'Storage', 'Restic', 'Backup', 'Restore', 'Admin', 'Api'] as $f) require_once __DIR__ . "/$f.php";

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'icon' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $name = (string)($_GET['name'] ?? '');
    $f = preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $name) ? Api::cacheIcon($name) : '';
    if ($f === '' || !is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: image/png');
    header('Cache-Control: max-age=3600');
    readfile($f);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function aib_reply(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') aib_reply(['ok' => false, 'error' => 'Nur POST erlaubt'], 405);
$unraidChecked = function_exists('csrf_terminate') || str_contains((string)ini_get('auto_prepend_file'), 'local_prepend');
if (!$unraidChecked) {
    $var = @parse_ini_file(getenv('AIB_VAR_INI') ?: '/var/local/emhttp/var.ini') ?: [];
    if (empty($var['csrf_token']) || !hash_equals((string)$var['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        aib_reply(['ok' => false, 'error' => 'Ungültiges CSRF-Token – Seite neu laden'], 403);
    }
}

try {
    $params = $_POST;
    unset($params['action'], $params['csrf_token']);
    aib_reply(['ok' => true, 'data' => (new Api())->handle($action, $params)]);
} catch (\InvalidArgumentException $e) {
    aib_reply(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    aib_reply(['ok' => false, 'error' => $e->getMessage()], 500);
}
