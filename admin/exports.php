<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_auth(['superadmin', 'admin']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $listId = filter_var($_POST['list_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$listId) {
        $error = 'Wählen Sie eine Empfängerliste.';
    } else {
        $listQuery = $pdo->prepare('SELECT name FROM email_lists WHERE id=:id');
        $listQuery->execute(['id' => $listId]);
        $listName = $listQuery->fetchColumn();
        if ($listName === false) {
            $error = 'Liste nicht gefunden.';
        } else {
            log_activity($pdo, (int) $admin['id'], 'contacts.exported', ['list_id' => $listId]);
            $filename = 'contacts-' . $listId . '-' . gmdate('Ymd-His') . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, private');
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['email', 'first_name', 'last_name', 'status', 'legal_basis', 'evidence_source', 'consent_granted_at'], ';');
            $statement = $pdo->prepare(
                'SELECT ec.email, ec.first_name, ec.last_name, ec.status,
                 cc.legal_basis, cc.evidence_source, cc.granted_at
                 FROM email_list_contacts lc
                 JOIN email_contacts ec ON ec.id=lc.contact_id
                 LEFT JOIN contact_consents cc ON cc.id=(
                     SELECT MAX(cc2.id) FROM contact_consents cc2 WHERE cc2.contact_id=ec.id
                 )
                 WHERE lc.list_id=:id ORDER BY ec.id'
            );
            $statement->execute(['id' => $listId]);
            while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                foreach ($row as &$cell) {
                    $cell = (string) ($cell ?? '');
                    if (preg_match('/^[\s]*[=+\-@]/u', $cell)) {
                        $cell = "'" . $cell;
                    }
                }
                unset($cell);
                fputcsv($output, $row, ';');
            }
            fclose($output);
            exit;
        }
    }
}
$lists = $pdo->query('SELECT id, name FROM email_lists ORDER BY name')->fetchAll();
$pageTitle = 'Contact Exports';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<section class="panel"><h2>Kontakte als CSV exportieren</h2><p class="muted">Export enthält personenbezogene Kontaktdaten und dokumentierte Versandgrundlagen. Nur Superadmins und Administratoren dürfen exportieren. Jeder Export wird im Audit-Log vermerkt. CSV-Zellen werden gegen Tabellenformel-Injektion entschärft.</p>
<form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><label>Liste<select name="list_id" required><option value="">Auswählen …</option><?php foreach ($lists as $list): ?><option value="<?= (int) $list['id'] ?>"><?= escape($list['name']) ?></option><?php endforeach; ?></select></label><button type="submit">CSV exportieren</button></form>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
