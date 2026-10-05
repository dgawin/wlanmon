<?php
/**
 * Schmale Fußzeile aller Seiten: Dashboard-Version und Link zur Website,
 * je nach Sprache wlanmon.de oder wlanmon.com. Eingebunden direkt vor
 * </body>; dass sie auch auf kurzen Seiten unten sitzt, regelt das
 * Flex-Layout von body/main in style.css.
 */
$__footerSite = effective_lang() === 'de' ? 'wlanmon.de' : 'wlanmon.com';
?>
<footer class="site-footer">
    WLANMON <?= e(dashboard_version()) ?>
    · <a href="https://<?= e($__footerSite) ?>" target="_blank" rel="noopener"><?= e($__footerSite) ?></a>
</footer>
