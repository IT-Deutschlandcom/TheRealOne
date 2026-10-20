<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_auth();
$campaignId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$campaignId) {
    http_response_code(400);
    exit('Ungültige Kampagne.');
}
$query = $pdo->prepare('SELECT id, name FROM campaigns WHERE id=:id');
$query->execute(['id' => $campaignId]);
$campaign = $query->fetch();
if (!$campaign) {
    http_response_code(404);
    exit('Kampagne nicht gefunden.');
}
$pageTitle = 'Live Campaign Monitor';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="panel stack" id="campaign-monitor" data-campaign-id="<?= (int) $campaign['id'] ?>">
    <h2><?= escape($campaign['name']) ?></h2>
    <p class="muted" id="monitor-status">Lade Live-Status …</p>
    <div class="progress-track" role="progressbar" aria-label="Kampagnenfortschritt" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-fill" id="monitor-progress" style="width:0%"></div>
    </div>
    <div class="grid monitor-stats">
        <article class="stat-card"><p class="muted">Gesamt</p><div class="stat-value" data-stat="total">0</div></article>
        <article class="stat-card"><p class="muted">Bearbeitet</p><div class="stat-value" data-stat="processed">0</div></article>
        <article class="stat-card"><p class="muted">SMTP angenommen</p><div class="stat-value" data-stat="accepted">0</div></article>
        <article class="stat-card"><p class="muted">Ausstehend</p><div class="stat-value" data-stat="pending">0</div></article>
        <article class="stat-card"><p class="muted">Status unklar</p><div class="stat-value" data-stat="unknown">0</div></article>
        <article class="stat-card"><p class="muted">In Bearbeitung</p><div class="stat-value" data-stat="processing">0</div></article>
    </div>
    <p class="muted">„SMTP angenommen“ bedeutet nur, dass der SMTP-Server die Nachricht akzeptiert hat; es bestätigt keine Zustellung im Posteingang. Unklare SMTP-Resultate werden nicht automatisch erneut gesendet.</p>
</section>
<section class="panel" style="margin-top:18px"><h2>Versandprotokoll</h2><div class="table-wrap"><table><thead><tr><th>Empfänger (maskiert)</th><th>Status</th><th>SMTP-Code</th><th>Zeitpunkt</th></tr></thead><tbody id="monitor-events"><tr><td colspan="4">Lade …</td></tr></tbody></table></div></section>
<script src="/assets/js/campaign-monitor.js" defer></script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
