<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_auth(['superadmin', 'admin']);
$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
    $selector = trim((string) ($_POST['dkim_selector'] ?? 'default'));
    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ||
        strlen($domain) > 253 ||
        !preg_match('/^[A-Za-z0-9_-]{1,63}$/', $selector)) {
        $error = 'Geben Sie eine gültige Domain und DKIM-Selector ein.';
    } else {
        $txt = dns_get_record($domain, DNS_TXT) ?: [];
        $dmarc = dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [];
        $dkim = dns_get_record($selector . '._domainkey.' . $domain, DNS_TXT) ?: [];
        $mx = dns_get_record($domain, DNS_MX) ?: [];
        $spfRecords = array_values(array_filter(array_map(
            static fn ($record) => (string) ($record['txt'] ?? ''),
            $txt
        ), static fn ($value) => stripos($value, 'v=spf1') === 0));
        $dmarcRecords = array_values(array_filter(array_map(
            static fn ($record) => (string) ($record['txt'] ?? ''),
            $dmarc
        ), static fn ($value) => stripos($value, 'v=DMARC1') === 0));
        $dkimRecords = array_values(array_filter(array_map(
            static fn ($record) => (string) ($record['txt'] ?? ''),
            $dkim
        ), static fn ($value) => stripos($value, 'v=DKIM1') === 0 || str_contains($value, 'p=')));
        $smtpHost = (string) ($pdo->query('SELECT host FROM smtp_profiles WHERE is_active=1 LIMIT 1')->fetchColumn() ?: '');
        $smtpIp = $smtpHost !== '' ? gethostbyname($smtpHost) : '';
        $reverse = filter_var($smtpIp, FILTER_VALIDATE_IP) ? gethostbyaddr($smtpIp) : false;
        $result = [
            'spf' => $spfRecords,
            'dmarc' => $dmarcRecords,
            'dkim' => $dkimRecords,
            'mx' => $mx,
            'smtp_host' => $smtpHost,
            'smtp_ip' => $smtpIp,
            'reverse' => $reverse,
        ];
        log_activity($pdo, (int) $admin['id'], 'deliverability.checked', ['domain' => $domain]);
    }
}
$pageTitle = 'Deliverability Center';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
<section class="panel"><h2>Domain-Prüfung</h2><p class="muted">Prüft öffentlich sichtbare DNS-Einträge. Ein DNS-Befund beweist weder die Versandautorisierung noch Zustellbarkeit im Posteingang. DKIM-Selector passend zur tatsächlichen SMTP-Signierung eintragen.</p>
<form method="post" class="grid"><input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><label>Absenderdomain<input name="domain" placeholder="example.com" required></label><label>DKIM-Selector<input name="dkim_selector" value="default" maxlength="63" required></label><button type="submit">DNS-Prüfung starten</button></form>
<ul><li>SPF: ein passender SPF-TXT-Eintrag, nicht mehrere SPF-Records.</li><li>DKIM: Selector-Record entspricht dem konfigurierten SMTP-Provider.</li><li>DMARC: Policy und Reporting-Adresse nach eigener Datenschutzprüfung.</li><li>PTR/rDNS: SMTP-Hostname und Reverse-DNS sollten konsistent sein.</li><li>Zusätzlich TLS, Absenderdomain und Provider-Limits prüfen.</li></ul></section>
<?php if ($result !== null): ?><section class="panel spaced-top"><h2>Prüfergebnis</h2><div class="stack">
<p><strong>SPF:</strong> <?= $result['spf'] === [] ? 'Nicht gefunden' : escape(implode(' | ', $result['spf'])) ?></p>
<p><strong>DKIM (Selector):</strong> <?= $result['dkim'] === [] ? 'Nicht gefunden' : escape(implode(' | ', $result['dkim'])) ?></p>
<p><strong>DMARC:</strong> <?= $result['dmarc'] === [] ? 'Nicht gefunden' : escape(implode(' | ', $result['dmarc'])) ?></p>
<p><strong>MX:</strong> <?= $result['mx'] === [] ? 'Nicht gefunden' : escape(implode(', ', array_map(static fn ($r) => (string) ($r['target'] ?? ''), $result['mx']))) ?></p>
<p><strong>SMTP-Host / IP:</strong> <?= escape($result['smtp_host'] ?: 'Kein aktives Profil') ?> / <?= escape($result['smtp_ip']) ?></p>
<p><strong>Reverse DNS:</strong> <?= escape(is_string($result['reverse']) ? $result['reverse'] : 'Nicht auflösbar') ?></p>
</div><p class="muted">Technische Prüfung ohne Zustellgarantie.</p></section><?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
