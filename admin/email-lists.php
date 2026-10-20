<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_auth(['superadmin', 'admin', 'campaign_manager']);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '' || text_length($name) > 150) {
                throw new RuntimeException('Der Listenname muss zwischen 1 und 150 Zeichen lang sein.');
            }
            $stmt = $pdo->prepare('INSERT INTO email_lists (name, created_by) VALUES (:name, :admin)');
            $stmt->execute(['name' => $name, 'admin' => $admin['id']]);
            log_activity($pdo, (int) $admin['id'], 'email_list.created', ['list_id' => (int) $pdo->lastInsertId()]);
            $message = 'Liste erstellt.';
        } elseif ($action === 'rename') {
            $id = filter_input(INPUT_POST, 'list_id', FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['name'] ?? ''));
            if (!$id || $name === '' || text_length($name) > 150) {
                throw new RuntimeException('Ungültiger Listenname.');
            }
            $stmt = $pdo->prepare('UPDATE email_lists SET name = :name WHERE id = :id');
            $stmt->execute(['name' => $name, 'id' => $id]);
            log_activity($pdo, (int) $admin['id'], 'email_list.renamed', ['list_id' => $id]);
            $message = 'Liste umbenannt.';
        } elseif ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'list_id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Ungültige Liste.');
            }
            $stmt = $pdo->prepare('DELETE FROM email_lists WHERE id = :id');
            $stmt->execute(['id' => $id]);
            log_activity($pdo, (int) $admin['id'], 'email_list.deleted', ['list_id' => $id]);
            $message = 'Liste gelöscht. Kontakte und Sperren bleiben erhalten.';
        } elseif ($action === 'import') {
            $listId = filter_input(INPUT_POST, 'list_id', FILTER_VALIDATE_INT);
            $raw = (string) ($_POST['addresses'] ?? '');
            $basis = (string) ($_POST['legal_basis'] ?? '');
            $evidence = trim((string) ($_POST['evidence_source'] ?? ''));
            $grantedAt = (string) ($_POST['granted_at'] ?? '');
            $confirmed = isset($_POST['permission_confirmed']);
            $validDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $grantedAt);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$listId || !in_array($basis, ['consent', 'existing_customer'], true) || $evidence === '' ||
                strlen($evidence) > 500 || !$validDate ||
                ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
                $validDate > new DateTimeImmutable('now') || !$confirmed) {
                throw new RuntimeException('Geben Sie eine Versandgrundlage, deren Herkunft und Zeitpunkt an und bestätigen Sie die Berechtigung.');
            }
            if (strlen($raw) > 2_000_000) {
                throw new RuntimeException('Der Import darf höchstens 2 MB groß sein.');
            }
            $exists = $pdo->prepare('SELECT id FROM email_lists WHERE id = :id');
            $exists->execute(['id' => $listId]);
            if (!$exists->fetchColumn()) {
                throw new RuntimeException('Die ausgewählte Liste wurde nicht gefunden.');
            }
            $lines = preg_split('/\R/u', $raw) ?: [];
            $pdo->beginTransaction();
            $import = $pdo->prepare(
                'INSERT INTO email_imports (list_id, admin_id, total_rows) VALUES (:list, :admin, :total)'
            );
            $import->execute(['list' => $listId, 'admin' => $admin['id'], 'total' => count($lines)]);
            $importId = (int) $pdo->lastInsertId();
            $counts = ['imported' => 0, 'duplicate' => 0, 'invalid' => 0, 'suppressed' => 0];
            $seen = [];
            $findContact = $pdo->prepare('SELECT id, status FROM email_contacts WHERE normalized_email = :email');
            $isSuppressed = $pdo->prepare('SELECT 1 FROM suppression_list WHERE normalized_email = :email');
            $createContact = $pdo->prepare(
                "INSERT INTO email_contacts (email, normalized_email, status) VALUES (:email, :normalized, 'active')"
            );
            $activate = $pdo->prepare(
                "UPDATE email_contacts SET status = 'active' WHERE id = :id AND status = 'pending'"
            );
            $consent = $pdo->prepare(
                'INSERT INTO contact_consents (contact_id, legal_basis, evidence_source, granted_at, recorded_by)
                 VALUES (:contact, :basis, :source, :granted, :admin)'
            );
            $link = $pdo->prepare(
                'INSERT IGNORE INTO email_list_contacts (list_id, contact_id) VALUES (:list, :contact)'
            );
            foreach ($lines as $line) {
                $email = trim($line);
                if ($email === '') {
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                    $counts['invalid']++;
                    continue;
                }
                $normalized = strtolower($email);
                if (isset($seen[$normalized])) {
                    $counts['duplicate']++;
                    continue;
                }
                $seen[$normalized] = true;
                $isSuppressed->execute(['email' => $normalized]);
                if ($isSuppressed->fetchColumn()) {
                    $counts['suppressed']++;
                    continue;
                }
                $findContact->execute(['email' => $normalized]);
                $contact = $findContact->fetch();
                if ($contact && in_array($contact['status'], ['unsubscribed', 'bounced'], true)) {
                    $counts['suppressed']++;
                    continue;
                }
                if (!$contact) {
                    $createContact->execute(['email' => $email, 'normalized' => $normalized]);
                    $contactId = (int) $pdo->lastInsertId();
                } else {
                    $contactId = (int) $contact['id'];
                    if ($contact['status'] === 'pending') {
                        $activate->execute(['id' => $contactId]);
                    }
                }
                $link->execute(['list' => $listId, 'contact' => $contactId]);
                if ($link->rowCount() === 0 && $contact !== false) {
                    $counts['duplicate']++;
                    continue;
                }
                $consent->execute([
                    'contact' => $contactId,
                    'basis' => $basis,
                    'source' => $evidence,
                    'granted' => $validDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'admin' => $admin['id'],
                ]);
                $counts['imported']++;
            }
            $updateImport = $pdo->prepare(
                'UPDATE email_imports SET imported_count=:imported, duplicate_count=:duplicate,
                 invalid_count=:invalid, suppressed_count=:suppressed WHERE id=:id'
            );
            $updateImport->execute($counts + ['id' => $importId]);
            log_activity($pdo, (int) $admin['id'], 'email_contacts.imported', ['list_id' => $listId, 'count' => $counts['imported']]);
            $pdo->commit();
            $message = sprintf(
                'Import abgeschlossen: %d aufgenommen, %d Duplikate, %d ungültig, %d gesperrt.',
                $counts['imported'], $counts['duplicate'], $counts['invalid'], $counts['suppressed']
            );
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            error_log('Email list operation failed: ' . $exception->getMessage());
            $error = 'Die Aktion konnte nicht abgeschlossen werden.';
        }
    }
}

$lists = $pdo->query(
    'SELECT l.id, l.name, l.created_at, COUNT(lc.contact_id) AS contact_count
     FROM email_lists l LEFT JOIN email_list_contacts lc ON lc.list_id = l.id
     GROUP BY l.id ORDER BY l.name'
)->fetchAll();
$pageTitle = 'Email Lists';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($message !== ''): ?><div class="alert"><?= escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<div class="stack">
    <section class="panel">
        <h2>Liste erstellen</h2>
        <form method="post" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <label class="flex-grow">Listenname<input name="name" maxlength="150" required></label>
            <button type="submit">Erstellen</button>
        </form>
    </section>
    <section class="panel">
        <h2>Listen</h2>
        <div class="table-wrap"><table><thead><tr><th>Name</th><th>Kontakte</th><th>Erstellt</th><th>Aktionen</th></tr></thead><tbody>
        <?php foreach ($lists as $list): ?><tr>
            <td><?= escape($list['name']) ?></td><td><?= number_format((int) $list['contact_count']) ?></td><td><?= escape($list['created_at']) ?></td>
            <td><div class="actions">
                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="rename"><input type="hidden" name="list_id" value="<?= (int) $list['id'] ?>"><input aria-label="Neuer Listenname" name="name" maxlength="150" required><button class="button-secondary" type="submit">Umbenennen</button></form>
                <form method="post" data-confirm="Liste wirklich löschen? Kontakte und Sperren bleiben erhalten."><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="list_id" value="<?= (int) $list['id'] ?>"><button class="button-danger" type="submit">Löschen</button></form>
            </div></td>
        </tr><?php endforeach; ?>
        <?php if ($lists === []): ?><tr><td colspan="4" class="muted">Noch keine Listen vorhanden.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="panel">
        <h2>Kontakte importieren</h2>
        <p class="muted">Nur Adressen mit dokumentierter, gültiger Versandberechtigung importieren. Abgemeldete, gebouncte oder zentral gesperrte Kontakte werden nicht reaktiviert.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
            <input type="hidden" name="action" value="import">
            <label>Empfängerliste<select name="list_id" required><option value="">Auswählen …</option><?php foreach ($lists as $list): ?><option value="<?= (int) $list['id'] ?>"><?= escape($list['name']) ?></option><?php endforeach; ?></select></label>
            <label>E-Mail-Adressen (eine pro Zeile)<textarea name="addresses" maxlength="2000000" required></textarea></label>
            <div class="grid">
                <label>Dokumentierte Versandgrundlage<select name="legal_basis" required><option value="">Auswählen …</option><option value="consent">Einwilligung</option><option value="existing_customer">Bestandskunden-Ausnahme (rechtlich geprüft)</option></select></label>
                <label>Datum und Uhrzeit der Grundlage<input type="datetime-local" name="granted_at" required></label>
            </div>
            <label>Nachweis / Herkunft der Grundlage<input name="evidence_source" maxlength="500" placeholder="z. B. Formular/Quelle und Referenz" required></label>
            <label><span><input class="inline-checkbox" type="checkbox" name="permission_confirmed" required> Ich bestätige, dass die dokumentierte Versandberechtigung für alle importierten Kontakte vorliegt.</span></label>
            <button type="submit">Import prüfen und speichern</button>
        </form>
    </section>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
