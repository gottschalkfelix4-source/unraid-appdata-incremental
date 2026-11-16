<?php
/* Mimics Unraid's /usr/local/emhttp/webGui/include/local_prepend.php for POST requests:
 * validate csrf_token against var.ini, abort on failure, then remove it from $_POST. */
function csrf_terminate($reason) { http_response_code(403); echo "csrf $reason"; exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $var = @parse_ini_file(getenv('AIB_VAR_INI') ?: '/var/local/emhttp/var.ini') ?: [];
    if (!isset($var['csrf_token'])) csrf_terminate('uninitialized');
    if (!isset($_POST['csrf_token'])) csrf_terminate('missing');
    if ($var['csrf_token'] != $_POST['csrf_token']) csrf_terminate('wrong');
    unset($_POST['csrf_token']);
}
