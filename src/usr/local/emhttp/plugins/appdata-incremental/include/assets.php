<?php
/* CSS/JS once per page load (all tabs are rendered into the same page) */
if (!defined('AIB_ASSETS')):
    define('AIB_ASSETS', 1);
    $aibv = (int)@filemtime(__DIR__ . '/../js/aib.js') . '-' . (int)@filemtime(__DIR__ . '/../css/aib.css');
?>
<link type="text/css" rel="stylesheet" href="/plugins/appdata-incremental/css/aib.css?v=<?=$aibv?>">
<script src="/plugins/appdata-incremental/js/aib.js?v=<?=$aibv?>"></script>
<?php endif; ?>
