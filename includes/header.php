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
