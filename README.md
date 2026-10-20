# Mail Marketing CMS – aktueller Implementierungsstand

Die PHP-Anwendung enthält eine IIS-kompatible Grundlage für Administrator-Login, Empfängerlisten, dokumentierte Versandgrundlagen, Kampagnenentwürfe, SMTP-Konfiguration und einen datenbankgestützten Kampagnen-Worker. Die Dateien werden direkt ausgeführt; Node.js, Composer und ein Build-Schritt sind nicht erforderlich.

**Wichtige Einschränkung:** Das ist weiterhin kein vollständiges CMS aus dem ursprünglichen Projektauftrag. SMTP-Kampagnenversand ist jetzt implementiert, aber bislang fehlen unter anderem Proxy-Verwaltung/Checker, Bounce-Postfachverarbeitung, Domain-Diagnose, Templates, vollständige Nutzer-/Rollenverwaltung, Datenexport/-löschung, CSV-Upload, vollständige SMTP-Testdiagnose und eine umfassende Testsuite. Unklare SMTP-Annahmen werden zur Duplikatvermeidung nicht erneut gesendet. Vor echtem Versand mit einem kontrollierten Empfänger und SMTP-Server testen.

## Voraussetzungen

- Windows Server und IIS mit PHP FastCGI
- PHP 8.2+, `pdo_mysql` und `openssl`
- MySQL 8 oder kompatible MariaDB
- HTTPS-Website mit einer stabilen öffentlichen URL
- Autorisierter SMTP-Server, der vom Windows-Server erreichbar ist

Die PHPMailer-Kerndateien v7.1.1 sind inklusive LGPL-Lizenz unter `assets/vendor/phpmailer/` eingebettet. Composer ist auf dem Zielserver nicht erforderlich.

## Installation

1. Eine leere Datenbank und einen eingeschränkten Datenbankbenutzer erstellen.
2. Für eine Neuinstallation `/home/runner/work/TheRealOne/TheRealOne/database.sql` importieren. Die Datei legt `marketing_cms` an; bei abweichendem Datenbanknamen `USE marketing_cms` vor dem Import anpassen.
3. Für eine bestehende Installation aus der vorherigen CMS-Version statt des Neuimports einmalig `database-migration-delivery.sql` auf der CMS-Datenbank ausführen. Bei einem abweichenden Datenbanknamen die `USE`-Zeile anpassen. Vorher ein Datenbankbackup erstellen; das Migrationsskript ist nicht für wiederholte Ausführung vorgesehen.
4. `config.php` bearbeiten:
   - Datenbankzugang eintragen.
   - `app.url` auf die öffentliche HTTPS-Adresse der Website setzen, ohne abschließenden Slash.
   - `app.encryption_key` durch genau 64 zufällige Hex-Zeichen ersetzen. In Windows PowerShell kann ein Schlüssel so erzeugt werden:

     ```powershell
     $bytes = New-Object byte[] 32
     [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
     ($bytes | ForEach-Object { $_.ToString('x2') }) -join ''
     ```

   **Den Schlüssel sicher sichern und dauerhaft beibehalten.** SMTP-Passwörter und Abmeldesignaturen können bei Schlüsselverlust nicht mehr entschlüsselt bzw. geprüft werden.
5. Website-Dateien in das IIS-Webverzeichnis kopieren und `config.php` sowie `storage` mit geeigneten NTFS-Rechten schützen. `web.config` sperrt interne Ordner, `config.php`, SQL-Dateien und die Task-Skripte vor HTTP-Zugriff. HTTPS und IIS-FastCGI müssen serverseitig eingerichtet sein.
6. `install.php` aufrufen und den ersten Administrator anlegen. Danach den HTTP-Zugriff auf `install.php` zusätzlich in IIS sperren.
7. Als Administrator unter **SMTP Settings** den autorisierten Relay-Server konfigurieren, speichern und **Test SMTP Connection** ausführen. Dieser Test verbindet sich und authentifiziert sich, sendet aber keine Test-E-Mail.
8. Unter **Email Lists** nur Kontakte mit belegter gültiger Versandberechtigung importieren. Der Import dokumentiert die vom Administrator angegebene Grundlage; er verifiziert nicht automatisch deren rechtliche Gültigkeit.
9. Kampagne unter **Campaign Manager** erstellen, HTML-Inhalt mit `{{unsubscribe_url}}` sowie eine Plain-Text-Alternative mit `{{unsubscribe_url}}` versehen, Intervall einstellen und Start betätigen. Bei Start werden aktive Kontakte mit dokumentiertem Nachweis in eine einmalige Queue übernommen; Sperrlisten werden beim Queue-Aufbau und nochmals unmittelbar vor dem Versand geprüft.

## Automatischer Versand unter Windows

Der Browser muss nicht geöffnet bleiben. Der Worker verarbeitet pro Aufruf höchstens eine Nachricht. Die Windows-Aufgabe startet ihn jede Minute; das Kampagnenintervall ist die Mindestzeit zwischen Nachrichten derselben Kampagne. Standard sind **180 Sekunden**, im Entwurf einstellbar von **60 bis 86400 Sekunden**. Durch das minütliche Polling kann eine Nachricht nach Fälligkeit bis zu etwa eine weitere Minute warten. Kleine Intervallwerte umgehen weder Provider-Limits noch diese Polling-Grenze.

PowerShell als Administrator öffnen und die Pfade anpassen:

```powershell
& 'C:\inetpub\wwwroot\TheRealOne\scripts\install-worker.ps1' `
  -PhpPath 'C:\PHP\php.exe' `
  -ProjectPath 'C:\inetpub\wwwroot\TheRealOne'
```

Die Aufgabe läuft als `SYSTEM`, startet nur eine Instanz gleichzeitig, protokolliert Ausgaben nach `storage\logs` und ruft ausschließlich `worker.php` per PHP-CLI auf. Der Task-Manager verhindert parallele Aufgabeninstanzen; zusätzlich schützt ein MySQL-Lock den Worker. Prüfen Sie, dass `SYSTEM` die Konfiguration lesen und in `storage\logs` schreiben darf, sowie Datenbank und SMTP erreichbar sind. Der Task-Status und das Worker-Protokoll sind nach der Installation zu prüfen. Ein manuelles einmaliges Ausführen ist möglich:

```powershell
& 'C:\PHP\php.exe' 'C:\inetpub\wwwroot\TheRealOne\worker.php'
```

`worker.php` verweigert Webaufrufe und akzeptiert nur PHP-CLI.

## Versandstatus, Wiederholungen und Abmeldung

- `accepted` bedeutet Annahme durch den SMTP-Server, nicht bestätigte Zustellung.
- Explizite SMTP-4xx-Ablehnungen werden mit begrenzter Wartezeit und bis zu `mailer.max_attempts` wiederholt. Explizite SMTP-Fehlercodes werden als fehlgeschlagen protokolliert.
- Verbindungsabbrüche ohne klaren SMTP-Antwortcode erhalten den Status `unknown` und werden **nicht automatisch erneut gesendet**, weil der Server die Nachricht möglicherweise bereits angenommen hatte. Nach einem Worker-Absturz wird eine solche laufende Nachricht ebenfalls nicht blind wiederholt.
- Jede Nachricht enthält signierte individuelle Abmeldelinks sowie `List-Unsubscribe`- und `List-Unsubscribe-Post`-Header. Eine Abmeldung setzt den Kontakt auf `unsubscribed`, fügt ihn der zentralen Suppression-Liste hinzu und entfernt ausstehende Empfängerjobs. Die passende öffentliche HTTPS-URL muss in `app.url` stimmen.
- Das CMS stellt keine DKIM-Signierung bereit. Für Posteingangszustellung sind eigene SPF-, DKIM- und DMARC-Konfiguration und die SMTP-Provider-Vorgaben erforderlich; der technische SMTP-Test garantiert keine Zustellung.
- Versand darf nur an Kontakte mit gültiger, nachweisbarer Rechtsgrundlage erfolgen. Keine gekauften oder gescrapten Verteiler importieren. Keine Proxy-Rotation oder Provider-Limit-Umgehung.

## Funktionen und offene Module

Implementiert: Login/Ersteinrichtung, rollenbasierter Zugriff, CSRF, Audit-Logs, Listenverwaltung, Textimport mit Duplikat- und Suppression-Prüfung, Kampagnenentwürfe, ein SMTP-Profil mit verschlüsseltem Passwort, SMTP-Verbindungstest, Queue, Worker, konfigurierbares Intervall, Pause/Fortsetzen/Abbrechen, Live-Polling und signierte Web-/RFC-8058-Abmeldung.

Noch offen: mehrere SMTP-Profile und Versandprofil-Auswahl, vollständige SMTP-Diagnoseberichte, UI für Zustellberichte/Fehlerexporte, Bounce-Postfachverarbeitung, Soft-Bounce-Regeln, Proxy-Diagnose, Deliverability Center, Scheduler mit Zeitzonen, Template-Manager/Rich-Text-Editor, CSV- und TXT-Dateiupload, benutzerdefinierte Segmente/Felder, Nutzerverwaltung/komplette Rollenmatrix, Datenschutzexport/-löschung, System-Health-Modul und automatisierte Ende-zu-Ende-Tests. Diese Funktionen sind nicht als verfügbar dargestellt.

## Prüfungen

PHP-Syntax kann lokal mit `php -l` für die PHP-Dateien geprüft werden. Der isolierte Smoke-Test für SMTP-Passwortverschlüsselung, signierte Abmeldetokens und sichere Personalisierung läuft mit:

```powershell
& 'C:\PHP\php.exe' 'C:\inetpub\wwwroot\TheRealOne\tests\mailer-security-smoke.php'
```

SMTP-Verbindung, IIS-Rechte, geplante Aufgabe und Datenbankmigration müssen in einer kontrollierten Testumgebung mit einem eigens autorisierten SMTP-Server geprüft werden. Das Projekt enthält keinen simulierten Versand.
