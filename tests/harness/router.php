<?php
/* Test harness for the WebGUI: php -S 127.0.0.1:PORT tests/harness/router.php
 * Mimics the parts of the Unraid layout the plugin relies on: tabs of an xmenu page, global csrf_token,
 * jQuery, and the /plugins/<name>/ path. The theme is only an approximation of Unraid's dark theme. */
$root = realpath(__DIR__ . '/../../src/usr/local/emhttp/plugins/appdata-incremental');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($uri, '/plugins/appdata-incremental/')) {
    $rel = substr($uri, strlen('/plugins/appdata-incremental/'));
    $f = realpath("$root/$rel");
    if (!$f || !str_starts_with($f, $root)) { http_response_code(404); return true; }
    if (str_ends_with($f, '.php')) { chdir(dirname($f)); require $f; return true; }
    $types = ['js' => 'application/javascript', 'css' => 'text/css', 'png' => 'image/png'];
    header('Content-Type: ' . ($types[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($f);
    return true;
}
if (str_starts_with($uri, '/fa/') && getenv('AIB_FA_DIR')) {
    $f = realpath(getenv('AIB_FA_DIR') . substr($uri, 3));
    if ($f && str_starts_with($f, realpath(getenv('AIB_FA_DIR')))) {
        header('Content-Type: ' . (str_ends_with($f, '.css') ? 'text/css' : 'font/' . pathinfo($f, PATHINFO_EXTENSION)));
        readfile($f); return true;
    }
}
if ($uri === '/jquery.js') { header('Content-Type: application/javascript'); readfile(getenv('AIB_JQUERY')); return true; }

// "/" → render all tab pages like Unraid does for Type="xmenu" + Tabs="true"
$pages = [];
foreach (glob("$root/*.page") as $p) {
    $raw = file_get_contents($p);
    [$head, $body] = array_pad(explode("\n---\n", $raw, 2), 2, '');
    preg_match_all('/^(\w+)="(.*)"$/m', $head, $m, PREG_SET_ORDER);
    $h = [];
    foreach ($m as $x) $h[$x[1]] = $x[2];
    if (!isset($h['Menu']) || !preg_match('/^AppdataIncremental:(\d+)$/', $h['Menu'], $mm)) continue;
    $pages[(int)$mm[1]] = ['title' => $h['Title'], 'tag' => $h['Tag'] ?? '', 'body' => str_replace('/usr/local/emhttp/plugins/appdata-incremental', $root, $body)];
}
ksort($pages);
$tab = (int)($_GET['tab'] ?? 1);
?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><title>Appdata Incremental – Test-Harness</title>
<link rel="stylesheet" href="<?= getenv('AIB_FA_DIR') ? '/fa/css/font-awesome.min.css' : 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css' ?>">
<script src="/jquery.js"></script>
<script>var csrf_token = "<?=htmlspecialchars(getenv('AIB_TEST_CSRF') ?: 'test-token')?>";
function swal(o, cb) { if (window.confirm(o.title + "\n\n" + o.text)) cb(true); }</script>
<style>
 body { background:#1c1b1b; color:#f2f2f2; font-family: clear-sans, Arial, sans-serif; font-size:13px; margin:0; }
 #header { background:#121212; padding:14px 24px; font-size:20px; border-bottom:3px solid #ff8c2f; } #header small { font-size:12px; color:#999; margin-left:12px }
 #content { padding: 10px 24px 40px; }
 .tabs { display:flex; gap:2px; border-bottom:1px solid #444; margin:12px 0 10px; } .tabs a { padding:8px 16px; color:#ccc; text-decoration:none; background:#262626; border-radius:4px 4px 0 0; }
 .tabs a.active { background:#ff8c2f; color:#fff; } .tabpage { display:none } .tabpage.active { display:block }
 input[type=button], button { background: linear-gradient(90deg,#e22828 0,#ff8c2f); border:0; color:#fff; padding:6px 14px; border-radius:4px; cursor:pointer; font-size:12px; text-transform:uppercase; letter-spacing:.5px; margin:2px 6px 2px 0 }
 input[type=button]:disabled { opacity:.4; cursor:default }
 input[type=text], input[type=number], input[type=password], select, textarea { background:#262626; color:#f2f2f2; border:1px solid #555; border-radius:3px; padding:4px 6px; }
 dl { display:flex; margin:6px 0; } dt { width:280px; text-align:right; padding-right:14px; flex-shrink:0; line-height:26px; } dd { margin:0; line-height:26px; flex:1 }
 div.title { border-bottom:1px solid #444; padding:6px 0; margin:18px 0 8px; font-size:15px; display:flex; justify-content:space-between } div.title i { margin-right:8px; color:#ff8c2f }
 table.tablesorter { border-collapse:collapse; margin:6px 0; } table.tablesorter th { text-align:left; background:#262626; padding:6px 8px; font-weight:normal; color:#bbb }
 table.tablesorter td { padding:6px 8px; border-bottom:1px solid #2e2e2e } a { color:#ff8c2f }
 blockquote.inline_help { display:none; } body.help blockquote.inline_help { display:block; background:#262626; border-left:3px solid #ff8c2f; margin:4px 0 8px 294px; padding:4px 12px; color:#ccc }
 code { color:#ffb36b }
</style></head>
<body class="<?= isset($_GET['help']) ? 'help' : '' ?>">
<div id="header">Appdata Incremental <small>Test-Harness – Unraid-Layout nachgebildet, Theme angenähert</small></div>
<div id="content">
<div class="tabs"><?php foreach ($pages as $n => $p): ?><a href="?tab=<?=$n?>" data-tab="<?=$n?>" class="<?= $n === $tab ? 'active' : '' ?>"><label><i class="fa fa-<?=htmlspecialchars($p['tag'])?>"></i> <?=htmlspecialchars($p['title'])?></label></a><?php endforeach; ?></div>
<?php foreach ($pages as $n => $p): ?><div class="tabpage <?= $n === $tab ? 'active' : '' ?>" id="tab<?=$n?>"><?php eval('?>' . $p['body']); ?></div><?php endforeach; ?>
</div>
<script>$('.tabs a').on('click', function (e) { e.preventDefault(); $('.tabs a').removeClass('active'); $(this).addClass('active'); $('.tabpage').removeClass('active'); $('#tab' + $(this).data('tab')).addClass('active'); });</script>
</body></html>
