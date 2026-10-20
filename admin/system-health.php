<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_auth(['superadmin', 'admin']);

$dbVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
$dbName = (string) $config['database']['name'];
$sizeQuery = $pdo->prepare(
    'SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema=:db'
);
$sizeQuery->execute(['db' => $dbName]);
$databaseBytes = (int) $sizeQuery->fetchColumn();
$queued = (int) $pdo->query("SELECT COUNT(*) FROM campaign_queue WHERE status='queued'")->fetchColumn();
$working = (int) $pdo->query("SELECT COUNT(*) FROM campaign_queue WHERE status='processing'")->fetchColumn();
$heartbeat = $pdo->query("SELECT last_seen_at FROM worker_heartbeats WHERE worker_name='campaign-worker'")->fetchColumn();
$smtp = $pdo->query('SELECT is_active, last_test_at, last_test_success FROM smtp_profiles ORDER BY id LIMIT 1')->fetch() ?: null;
$extensions = ['pdo_mysql', 'openssl', 'imap', 'curl', 'mbstring'];
$pageTitle = 'System Health';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="grid">
<article class="stat-card"><p class="muted">PHP</p><div class="stat-value"><?= escape(PHP_VERSION) ?></div><p><?= PHP_VERSION_ID >= 80200 ? 'Version erfüllt Minimum' : 'Upgrade empfohlen' ?></p></article>
<article class="stat-card"><p class="muted">Datenbank</p><div class="stat-value"><?= escape((string) $dbVersion) ?></div><p><?= number_format($databaseBytes / 1048576, 2) ?> MiB</p></article>
<article class="stat-card"><p class="muted">Warteschlange</p><div class="stat-value"><?= number_format($queued) ?></div><p><?= number_format($working) ?> in Bearbeitung</p></article>
<article class="stat-card"><p class="muted">Worker</p><div class="stat-value"><?= $heartbeat && strtotime((string) $heartbeat . ' UTC') > time() - 180 ? 'Aktiv' : 'Keine aktuelle Meldung' ?></div><p><?= escape($heartbeat ?: 'Noch nie gestartet') ?></p></article>
</section>
<section class="panel spaced-top"><h2>Systemdiagnose</h2><div class="table-wrap"><table><thead><tr><th>Prüfung</th><th>Status</th><th>Details</th></tr></thead><tbody>
<tr><td>Serverzeit</td><td>Info</td><td><?= escape(date(DATE_ATOM)) ?></td></tr>
<?php foreach ($extensions as $extension): ?><tr><td>PHP-Erweiterung <?= escape($extension) ?></td><td><?= extension_loaded($extension) ? 'Verfügbar' : 'Fehlt' ?></td><td><?= $extension === 'imap' ? 'Für Bounce-Postfach erforderlich' : '' ?></td></tr><?php endforeach; ?>
<tr><td>SMTP-Profil</td><td><?= $smtp && (int) $smtp['is_active'] === 1 ? ((int) $smtp['last_test_success'] === 1 ? 'Aktiv / getestet' : 'Aktiv / Test fehlt oder fehlgeschlagen') : 'Nicht aktiv' ?></td><td><?= escape($smtp['last_test_at'] ?? 'Noch nicht getestet') ?></td></tr>
<tr><td>App-URL</td><td><?= parse_url((string) $config['app']['url'], PHP_URL_SCHEME) === 'https' ? 'HTTPS' : 'Unsicheres HTTP' ?></td><td><?= escape($config['app']['url']) ?></td></tr>
</tbody></table></div><p class="muted">Worker-Alter größer als drei Minuten bedeutet: geplante Aufgabe, PHP-CLI, Datenbankzugriff und Worker-Protokoll prüfen.</p></section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
