<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$campaignId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$campaignId) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Kampagne.']);
    exit;
}
$query = $pdo->prepare(
    "SELECT id, name, status, send_interval_seconds, created_at FROM campaigns WHERE id=:id"
);
$query->execute(['id' => $campaignId]);
$campaign = $query->fetch();
if (!$campaign) {
    http_response_code(404);
    echo json_encode(['error' => 'Kampagne nicht gefunden.']);
    exit;
}
$counts = $pdo->prepare(
    "SELECT COUNT(*) AS total,
     SUM(status='accepted') AS accepted,
     SUM(status='queued') AS pending,
     SUM(status='failed') AS failed,
     SUM(status='unknown') AS unknown_count,
     SUM(status='cancelled') AS cancelled,
     SUM(status='processing') AS processing
     FROM campaign_queue WHERE campaign_id=:id"
);
$counts->execute(['id' => $campaignId]);
$stats = $counts->fetch();
$logs = $pdo->prepare(
    "SELECT q.status, q.smtp_code, q.completed_at, ec.email
     FROM campaign_queue q JOIN email_contacts ec ON ec.id=q.contact_id
     WHERE q.campaign_id=:id AND q.status<>'queued'
     ORDER BY q.id DESC LIMIT 100"
);
$logs->execute(['id' => $campaignId]);
$events = array_map(static function (array $event): array {
    $email = (string) $event['email'];
    $at = strpos($email, '@');
    $masked = $at === false ? '***' : substr($email, 0, 1) . '***' . substr($email, $at);
    return [
        'recipient' => $masked,
        'status' => $event['status'],
        'smtp_code' => $event['smtp_code'],
        'time' => $event['completed_at'],
    ];
}, $logs->fetchAll());

$total = (int) ($stats['total'] ?? 0);
$processed = (int) ($stats['accepted'] ?? 0) + (int) ($stats['failed'] ?? 0) +
    (int) ($stats['unknown_count'] ?? 0) + (int) ($stats['cancelled'] ?? 0);
echo json_encode([
    'campaign' => $campaign,
    'stats' => [
        'total' => $total,
        'processed' => $processed,
        'accepted' => (int) ($stats['accepted'] ?? 0),
        'pending' => (int) ($stats['pending'] ?? 0),
        'failed' => (int) ($stats['failed'] ?? 0),
        'unknown' => (int) ($stats['unknown_count'] ?? 0),
        'processing' => (int) ($stats['processing'] ?? 0),
        'progress' => $total > 0 ? (int) floor($processed * 100 / $total) : 0,
    ],
    'events' => $events,
], JSON_THROW_ON_ERROR);
