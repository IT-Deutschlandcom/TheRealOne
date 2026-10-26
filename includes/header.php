<?php
$pageTitle = $pageTitle ?? 'Dashboard';
$admin = current_admin();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($pageTitle) ?> · <?= escape($config['app']['name']) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="/admin/dashboard.php"><?= escape($config['app']['name']) ?></a>
        <nav aria-label="Hauptnavigation">
            <a href="/admin/dashboard.php">Übersicht</a>
            <a href="/admin/email-lists.php">Email Lists</a>
            <a href="/admin/campaigns.php">Campaign Manager</a>
            <a href="/admin/templates.php">Templates</a>
            <a href="/admin/deliverability.php">Deliverability</a>
            <a href="/admin/system-health.php">System Health</a>
            <a href="/admin/proxy-manager.php">Proxy Manager</a>
            <?php if (in_array($admin['role'] ?? '', ['superadmin', 'admin'], true)): ?><a href="/admin/smtp-settings.php">SMTP Settings</a><?php endif; ?>
            <?php if (in_array($admin['role'] ?? '', ['superadmin', 'admin'], true)): ?><a href="/admin/bounce-settings.php">Bounces</a><?php endif; ?>
            <?php if (in_array($admin['role'] ?? '', ['superadmin', 'admin'], true)): ?><a href="/admin/exports.php">Exports</a><?php endif; ?>
        </nav>
        <div class="sidebar-user">
            <span><?= escape($admin['email'] ?? '') ?></span>
            <form method="post" action="/logout.php">
                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                <button class="button-secondary" type="submit">Abmelden</button>
            </form>
        </div>
    </aside>
    <main class="main-content">
        <header class="page-heading">
            <p class="eyebrow">Administration</p>
            <h1><?= escape($pageTitle) ?></h1>
        </header>
        <?php if (parse_url((string) ($config['app']['url'] ?? ''), PHP_URL_SCHEME) === 'http'): ?><div class="alert alert-error">HTTP-Betrieb: Alle Panel-Funktionen sind verfügbar, aber Login, Passwörter und Abmeldetoken werden unverschlüsselt übertragen. SMTP-TLS/SSL schützt nur die Verbindung zum Mailserver. Für öffentliche Nutzung wird HTTPS dringend empfohlen.</div><?php endif; ?>
