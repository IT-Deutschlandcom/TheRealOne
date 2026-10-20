<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_auth();

$stats = [
    'lists' => (int) $pdo->query('SELECT COUNT(*) FROM email_lists')->fetchColumn(),
    'contacts' => (int) $pdo->query("SELECT COUNT(*) FROM email_contacts WHERE status = 'active'")->fetchColumn(),
    'suppressed' => (int) $pdo->query('SELECT COUNT(*) FROM suppression_list')->fetchColumn(),
];
$pageTitle = 'Übersicht';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="grid">
    <article class="stat-card"><p class="muted">Aktive Kontakte</p><div class="stat-value"><?= number_format($stats['contacts']) ?></div></article>
    <article class="stat-card"><p class="muted">Empfängerlisten</p><div class="stat-value"><?= number_format($stats['lists']) ?></div></article>
    <article class="stat-card"><p class="muted">Gesperrte Adressen</p><div class="stat-value"><?= number_format($stats['suppressed']) ?></div></article>
</section>
<section class="panel stack" style="margin-top:18px">
    <h2>Systemstatus</h2>
    <p class="muted">Dieser Arbeitsstand enthält die sichere Basis und die zustimmungsgebundene Listenverwaltung. Kampagnenversand und SMTP-Funktionen sind noch nicht verfügbar.</p>
    <div class="actions"><a class="button" href="/admin/email-lists.php">Email Lists öffnen</a></div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
