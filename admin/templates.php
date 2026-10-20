<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_auth();
$canEdit = in_array($admin['role'], ['superadmin', 'admin', 'campaign_manager'], true);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) {
        http_response_code(403);
        exit('Keine Berechtigung.');
    }
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'category') {
            $name = trim((string) ($_POST['category_name'] ?? ''));
            if ($name === '' || text_length($name) > 100) {
                throw new RuntimeException('Kategoriename muss 1 bis 100 Zeichen lang sein.');
            }
            $pdo->prepare('INSERT INTO email_template_categories (name) VALUES (:name)')
                ->execute(['name' => $name]);
            log_activity($pdo, (int) $admin['id'], 'template.category_created');
            $message = 'Kategorie angelegt.';
        } elseif (in_array($action, ['save', 'duplicate'], true)) {
            $id = filter_var($_POST['template_id'] ?? null, FILTER_VALIDATE_INT);
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['name'] ?? ''));
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $html = (string) ($_POST['html_content'] ?? '');
            $text = (string) ($_POST['text_content'] ?? '');
            if ($action === 'duplicate') {
                if (!$id) {
                    throw new RuntimeException('Vorlage nicht gefunden.');
                }
                $duplicate = $pdo->prepare(
                    "INSERT INTO email_templates (category_id, created_by, name, subject, html_content, text_content)
                     SELECT category_id, :admin, CONCAT(LEFT(name,150), ' (Kopie)'), subject, html_content, text_content
                     FROM email_templates WHERE id=:id"
                );
                $duplicate->execute(['admin' => $admin['id'], 'id' => $id]);
                if ($duplicate->rowCount() !== 1) {
                    throw new RuntimeException('Vorlage nicht gefunden.');
                }
                $message = 'Vorlage dupliziert.';
            } else {
                if ($id === false || $name === '' || text_length($name) > 180 ||
                    $subject === '' || text_length($subject) > 255 ||
                    strlen($html) > 1_000_000 || strlen($text) > 1_000_000) {
                    throw new RuntimeException('Bitte prüfen Sie Pflichtfelder und Eingabelängen.');
                }
                $category = $categoryId ? $categoryId : null;
                if ($category !== null) {
                    $check = $pdo->prepare('SELECT id FROM email_template_categories WHERE id=:id');
                    $check->execute(['id' => $category]);
                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('Kategorie nicht gefunden.');
                    }
                }
                if ($id) {
                    $save = $pdo->prepare(
                        'UPDATE email_templates SET category_id=:category, name=:name, subject=:subject,
                         html_content=:html, text_content=:text WHERE id=:id'
                    );
                    $save->execute(['category' => $category, 'name' => $name, 'subject' => $subject, 'html' => $html, 'text' => $text, 'id' => $id]);
                    if ($save->rowCount() === 0) {
                        $check = $pdo->prepare('SELECT id FROM email_templates WHERE id=:id');
                        $check->execute(['id' => $id]);
                        if (!$check->fetchColumn()) {
                            throw new RuntimeException('Vorlage nicht gefunden.');
                        }
                    }
                    log_activity($pdo, (int) $admin['id'], 'template.updated', ['template_id' => $id]);
                    $message = 'Vorlage gespeichert.';
                } else {
                    $save = $pdo->prepare(
                        'INSERT INTO email_templates (category_id, created_by, name, subject, html_content, text_content)
                         VALUES (:category, :admin, :name, :subject, :html, :text)'
                    );
                    $save->execute(['category' => $category, 'admin' => $admin['id'], 'name' => $name, 'subject' => $subject, 'html' => $html, 'text' => $text]);
                    log_activity($pdo, (int) $admin['id'], 'template.created', ['template_id' => (int) $pdo->lastInsertId()]);
                    $message = 'Vorlage erstellt.';
                }
            }
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Template manager action failed: ' . $exception->getMessage());
        $error = 'Vorlagenaktion fehlgeschlagen.';
    }
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$template = null;
if ($editId) {
    $query = $pdo->prepare('SELECT * FROM email_templates WHERE id=:id');
    $query->execute(['id' => $editId]);
    $template = $query->fetch() ?: null;
}
$categories = $pdo->query('SELECT id, name FROM email_template_categories ORDER BY name')->fetchAll();
$templates = $pdo->query(
    'SELECT t.id, t.name, t.subject, t.updated_at, c.name AS category_name
     FROM email_templates t LEFT JOIN email_template_categories c ON c.id=t.category_id
     ORDER BY t.updated_at DESC'
)->fetchAll();
$pageTitle = 'Template Manager';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($message !== ''): ?><div class="alert"><?= escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<?php if ($canEdit): ?>
<section class="panel"><h2>Kategorie</h2><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="category"><label class="flex-grow">Neue Kategorie<input name="category_name" maxlength="100" required></label><button type="submit">Kategorie anlegen</button></form></section>
<section class="panel spaced-top"><h2><?= $template ? 'Vorlage bearbeiten' : 'Vorlage erstellen' ?></h2>
<form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="save"><?php if ($template): ?><input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>"><?php endif; ?>
<label>Name<input name="name" maxlength="180" required value="<?= escape($template['name'] ?? '') ?>"></label>
<label>Kategorie<select name="category_id"><option value="">Ohne Kategorie</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) ($template['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= escape($category['name']) ?></option><?php endforeach; ?></select></label>
<label>Betreff<input name="subject" maxlength="255" required value="<?= escape($template['subject'] ?? '') ?>"></label>
<label>HTML-Inhalt<textarea name="html_content" maxlength="1000000" required><?= escape($template['html_content'] ?? '') ?></textarea></label>
<label>Plain-Text-Inhalt<textarea name="text_content" maxlength="1000000" required><?= escape($template['text_content'] ?? '') ?></textarea></label>
<p class="muted">Unterstützte Platzhalter: {{email}}, {{firstname}}, {{lastname}}, {{unsubscribe_url}}. Fügen Sie den Abmeldeplatzhalter in HTML und Text ein.</p>
<button type="submit">Vorlage speichern</button></form></section>
<?php endif; ?>
<section class="panel spaced-top"><h2>Vorlagen</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Kategorie</th><th>Betreff</th><th>Geändert</th><th>Aktion</th></tr></thead><tbody>
<?php foreach ($templates as $item): ?><tr><td><?= escape($item['name']) ?></td><td><?= escape($item['category_name'] ?? '–') ?></td><td><?= escape($item['subject']) ?></td><td><?= escape($item['updated_at']) ?></td><td><div class="actions"><?php if ($canEdit): ?><a class="button button-secondary" href="/admin/templates.php?edit=<?= (int) $item['id'] ?>">Bearbeiten</a><form method="post"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="template_id" value="<?= (int) $item['id'] ?>"><button class="button-secondary" type="submit">Duplizieren</button></form><?php endif; ?><a class="button button-secondary" href="/api/template.php?id=<?= (int) $item['id'] ?>" target="_blank" rel="noopener">Vorschau-Daten</a></div></td></tr><?php endforeach; ?>
<?php if ($templates === []): ?><tr><td colspan="5" class="muted">Keine Vorlagen.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
