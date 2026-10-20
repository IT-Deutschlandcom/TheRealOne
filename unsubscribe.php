<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Mailer.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
try {
    $contactId = \MailMarketing\verify_unsubscribe_token($token, $config);
} catch (Throwable $exception) {
    $contactId = null;
    error_log('Unsubscribe token verification unavailable: ' . $exception->getMessage());
}
if ($contactId === null) {
    http_response_code(400);
    exit('Ungültiger Abmeldelink.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oneClick = (string) ($_POST['List-Unsubscribe'] ?? '') === 'One-Click';
    $csrfValid = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) &&
        hash_equals(csrf_token(), $_POST['csrf_token']);
    if (!$oneClick && !$csrfValid) {
        http_response_code(400);
        exit('Ungültige Anfrage.');
    }
    $pdo->beginTransaction();
    $contactQuery = $pdo->prepare('SELECT id, normalized_email FROM email_contacts WHERE id=:id FOR UPDATE');
    $contactQuery->execute(['id' => $contactId]);
    $contact = $contactQuery->fetch();
    if ($contact) {
        $pdo->prepare("UPDATE email_contacts SET status='unsubscribed' WHERE id=:id")
            ->execute(['id' => $contactId]);
        $pdo->prepare(
            "INSERT INTO suppression_list (normalized_email, reason) VALUES (:email, 'unsubscribe')
             ON DUPLICATE KEY UPDATE reason='unsubscribe'"
        )->execute(['email' => $contact['normalized_email']]);
        $pdo->prepare(
            'INSERT INTO unsubscribe_events (contact_id, source) VALUES (:id, :source)'
        )->execute(['id' => $contactId, 'source' => $oneClick ? 'rfc8058' : 'web']);
        $pdo->prepare(
            "UPDATE campaign_queue SET status='cancelled', completed_at=UTC_TIMESTAMP()
             WHERE contact_id=:id AND status='queued'"
        )->execute(['id' => $contactId]);
    }
    $pdo->commit();
    $complete = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $contactQuery = $pdo->prepare('SELECT status FROM email_contacts WHERE id=:id');
    $contactQuery->execute(['id' => $contactId]);
    $status = $contactQuery->fetchColumn();
    if ($status === false) {
        http_response_code(404);
        exit('Kontakt nicht gefunden.');
    }
    $complete = $status === 'unsubscribed';
} else {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Methode nicht erlaubt.');
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Abmeldung</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body class="auth-page"><main class="panel auth-card"><h1><?= $complete ? 'Abmeldung bestätigt' : 'E-Mail-Abmeldung' ?></h1>
<?php if ($complete): ?><p>Diese Adresse wird für weitere Marketing-E-Mails gesperrt.</p><?php else: ?><p>Bestätigen Sie, dass Sie keine Marketing-E-Mails mehr erhalten möchten.</p><form method="post" class="form-grid"><input type="hidden" name="t" value="<?= escape($token) ?>"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><button class="button-danger" type="submit">Jetzt abmelden</button></form><?php endif; ?></main></body></html>
