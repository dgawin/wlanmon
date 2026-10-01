<?php
/** @var array $user */
/** @var string $token */
/** @var array{ok: bool, error: ?string} $mailResult */
/** @var string $inviteUrl */
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einladung – <?= e($user['username']) ?> – WLANMON</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="/static/style.css">
    <link rel="icon" href="/static/favicon.svg" type="image/svg+xml">
    <script src="/static/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>
<main>
<h1><i class="fa-solid fa-circle-check ok"></i> <?= te('Einladung verschickt') ?></h1>
<p><?= te('Benutzer:') ?> <code><?= e($user['username']) ?></code> (<?= e($user['email']) ?>)</p>

<?php if ($mailResult['ok']): ?>
    <p class="ok"><i class="fa-solid fa-envelope-circle-check"></i> <?= te('Einladungs-Mail an %s verschickt.', $user['email']) ?></p>
<?php else: ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= te('Mail-Versand fehlgeschlagen:') ?> <?= e($mailResult['error'] ?? __('unbekannter Fehler')) ?></p>
<?php endif; ?>

<p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= te('Dieser Link wird nur jetzt angezeigt - bei Bedarf jetzt kopieren oder manuell weitergeben. Gültig 7 Tage.') ?></p>
<pre><code><?= e($inviteUrl) ?></code></pre>

<p><a href="/users" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('Zur Benutzerliste') ?></a></p>
</main>
</body>
</html>
