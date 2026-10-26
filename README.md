# Mail Marketing CMS – aktueller Implementierungsstand

Die PHP-Anwendung enthält eine IIS-kompatible Grundlage für Administrator-Login, Empfängerlisten, dokumentierte Versandgrundlagen, Kampagnenentwürfe, SMTP-Konfiguration und einen datenbankgestützten Kampagnen-Worker. Die Dateien werden direkt ausgeführt; Node.js, Composer und ein Build-Schritt sind nicht erforderlich.

**Wichtige Einschränkung:** Das ist weiterhin kein vollständiges CMS aus dem ursprünglichen Projektauftrag. SMTP-Kampagnenversand ist implementiert, wahlweise über einen fest zugewiesenen SOCKS5-, HTTP- oder HTTPS-Proxy. Bounce-Verarbeitung benötigt die optionale PHP-IMAP-Erweiterung und standardisierte DSN-Bounce-Nachrichten. Separate Proxy-/Exit-IP-Prüfungen und Proxy-Rotation sind nicht implementiert. Unklare SMTP-Annahmen werden zur Duplikatvermeidung nicht erneut gesendet. Vor echtem Versand mit einem kontrollierten Empfänger und SMTP-Server testen.

## Voraussetzungen

- Windows Server und IIS mit PHP FastCGI
- PHP 8.2+, `pdo_mysql` und `openssl`
- MySQL 8 oder kompatible MariaDB
- HTTP- oder HTTPS-URL; für öffentlichen Produktivbetrieb wird HTTPS dringend empfohlen
- Autorisierter SMTP-Server, der vom Windows-Server erreichbar ist
- Optional die unter Windows installierbare PHP-IMAP-Erweiterung für Bounce-Abholung
- Für HTTPS-Proxys zusätzlich verfügbares `proc_open`, `php.exe` (unter Linux `php`) in `PHP_BINDIR` und die Berechtigung für lokale Loopback-Verbindungen. CLI-PHP benötigt ebenfalls OpenSSL und einen aktuellen CA-Zertifikatsspeicher.

Die PHPMailer-Kerndateien v7.1.1 sind inklusive LGPL-Lizenz unter `assets/vendor/phpmailer/` eingebettet. Composer ist auf dem Zielserver nicht erforderlich.

## Installation

1. Eine leere Datenbank und einen eingeschränkten Datenbankbenutzer erstellen.
2. Für eine Neuinstallation `database.sql` importieren. Die Datei legt `marketing_cms` an; bei abweichendem Datenbanknamen `USE marketing_cms` vor dem Import anpassen.
3. Für eine bestehende Installation aus der vorherigen CMS-Version statt des Neuimports einmalig `database-migration-delivery.sql` auf der CMS-Datenbank ausführen, sofern die SMTP-/Queue-Tabellen noch fehlen. Anschließend bei bestehenden Installationen einmalig `database-migration-smtp-proxy.sql` ausführen, sofern `smtp_profiles.proxy_id` noch fehlt, und `database-migration-proxy-protocol.sql`, sofern `proxies.protocol` noch fehlt. Bestehende Proxys bleiben dabei SOCKS5. Bei einer Neuinstallation mit der aktuellen `database.sql` sind alle drei Migrationen bereits enthalten und dürfen nicht zusätzlich ausgeführt werden. Bei einem abweichenden Datenbanknamen die `USE`-Zeile anpassen. Vorher ein Datenbankbackup erstellen und den Worker während der Migration anhalten; die Migrationsskripte sind nicht für wiederholte Ausführung vorgesehen.
4. `config.php` bearbeiten:
   - Datenbankzugang eintragen.
   - `app.url` auf die für Empfänger erreichbare Website-Adresse setzen, ohne abschließenden Slash, z. B. `http://panel.example.test`. Standard ist `http://localhost`; für öffentlich erreichbare Installationen wird HTTPS dringend empfohlen.
   - `app.encryption_key` durch genau 64 zufällige Hex-Zeichen ersetzen. In Windows PowerShell kann ein Schlüssel so erzeugt werden:

     ```powershell
     $bytes = New-Object byte[] 32
     $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
     $rng.GetBytes($bytes)
     ($bytes | ForEach-Object { $_.ToString('x2') }) -join ''
     $rng.Dispose()
     ```

   **Den Schlüssel sicher sichern und dauerhaft beibehalten.** SMTP-/IMAP-/Proxy-Passwörter und Abmeldesignaturen können bei Schlüsselverlust nicht mehr entschlüsselt bzw. geprüft werden.
5. Website-Dateien in das IIS-Webverzeichnis kopieren und `config.php` sowie `storage` mit geeigneten NTFS-Rechten schützen. `web.config` sperrt interne Ordner, `config.php`, SQL-Dateien und die Task-Skripte vor HTTP-Zugriff. IIS-FastCGI muss serverseitig eingerichtet sein.
6. `install.php` aufrufen und den ersten Administrator anlegen. Danach den HTTP-Zugriff auf `install.php` zusätzlich in IIS sperren.
7. Als Administrator unter **SMTP Settings** den autorisierten Relay-Server konfigurieren, speichern und **Test SMTP Connection** ausführen. Dieser Test verbindet sich und authentifiziert sich, sendet aber keine Test-E-Mail.
8. Unter **Email Lists** nur Kontakte mit belegter gültiger Versandberechtigung importieren. Der Import dokumentiert die vom Administrator angegebene Grundlage; er verifiziert nicht automatisch deren rechtliche Gültigkeit.
9. Im Campaign Manager zuerst die gewünschte Liste und danach die **Absenderadresse** (muss der freigegebenen SMTP-Absenderadresse entsprechen) sowie Inhalte/Intervall konfigurieren. Erst beim expliziten Klick auf „Start“ werden Adressen aus dieser Liste in die Versandwarteschlange aufgenommen. HTML-Inhalt und Plain-Text-Alternative müssen beide `{{unsubscribe_url}}` enthalten. Sperrlisten werden beim Queue-Aufbau und nochmals unmittelbar vor dem Versand geprüft.

### SOCKS5-/HTTP-/HTTPS-Massenimport und feste SMTP-Zuordnung

Unter **Proxy Manager** optional eine Gruppe erstellen und unter **Proxy-Massenimport** das Protokoll (SOCKS5, HTTP oder HTTPS) für die gesamte Liste auswählen. Je Zeile einen autorisierten Endpunkt im gleichen Format ohne Protokollpräfix eingeben; den tatsächlichen Port des Anbieters verwenden:

```text
user:password@192.0.2.1:1080
user:password@[2001:db8::1]:1080
```

Der Import akzeptiert bis zu 2 MB, meldet aufgenommene Einträge, Duplikate und ungültige Zeilen und speichert Passwörter mit AES-256-GCM. Gleiche Protokoll/IP/Port-Kombinationen werden übersprungen, ohne vorhandene Zugangsdaten oder Gruppen zu überschreiben. Dieselbe Adresse kann für verschiedene Protokolle hinterlegt werden. Benutzername und Passwort dürfen maximal 254 bzw. 255 Bytes enthalten, keine Steuerzeichen und müssen beide angegeben sein. Doppelpunkte und `@` im Passwort sind zulässig. HTTP-/HTTPS-Benutzernamen dürfen keinen Doppelpunkt enthalten. Endpunkte ohne Anmeldung und Hostnamen lassen sich über das Einzelproxy-Formular mit Protokollauswahl anlegen.

Unter **SMTP Settings → Fester Proxy (SOCKS5 / HTTP / HTTPS)** genau einen Endpunkt auswählen, speichern und den SMTP-Verbindungstest erneut ausführen. Das Protokoll wird neben der Adresse angezeigt und automatisch für den Versand verwendet. Die Gruppenbezeichnung hilft beim Auswählen; eine Gruppe ist kein Rotationspool. Das derzeit einzige SMTP-Profil verwendet diesen Proxy für alle seine Kampagnen und Verbindungstests. Solange eine Kampagne läuft oder pausiert ist, kann die Zuordnung nicht geändert werden (auch nicht von/zu direkter Verbindung). SMTP-Passwörter und andere SMTP-Einstellungen lassen sich zur Fehlerbehebung weiterhin korrigieren. Zugewiesene Proxys können nicht gelöscht werden.

Der Proxy baut die Verbindung zum SMTP-Server auf und löst dessen Hostnamen auf. HTTP/HTTPS verwenden **CONNECT**, mit Basic-Authentifizierung oder ohne Anmeldung. Der Anbieter muss Tunnel zum gewählten SMTP-Port erlauben; reine Web-Proxys oder Anbieter, die SMTP-Ports sperren, funktionieren nicht. Status 407 bedeutet eine abgelehnte Proxy-Anmeldung, andere Fehlercodes werden im Verbindungstest angezeigt. Digest-/NTLM-Anmeldung ist nicht implementiert.

STARTTLS und implizites TLS prüfen weiterhin das Zertifikat des SMTP-Servers. SOCKS5 und HTTP verschlüsseln weder Proxy-Anmeldedaten noch die Verbindung zum Proxy; nur vertrauenswürdige, gegebenenfalls zusätzlich abgesicherte Endpunkte verwenden. HTTPS verschlüsselt zusätzlich die Verbindung zum Proxy und prüft dessen Zertifikat gegen dessen Hostnamen bzw. IP-Adresse. Falls das Zertifikat nur für einen Hostnamen gilt, den Proxy über das Einzelproxy-Formular mit diesem Hostnamen anlegen, nicht über den IP-Massenimport.

Für HTTPS startet die Anwendung pro SMTP-Verbindung ein kurzlebiges PHP-CLI-Relay auf einer zufälligen Loopback-Adresse mit einmaligem Authentifizierungstoken. So bleibt SMTP-TLS auch innerhalb des TLS-verschlüsselten Proxy-Tunnels Ende-zu-Ende erhalten. Der Prozess wird beim Schließen der SMTP-Verbindung beendet; es ist kein zusätzlicher dauerhaft laufender Dienst nötig. Unter IIS müssen Prozessstart und lokale TCP-Verbindungen für die Anwendung erlaubt sein.

Ein fehlender, inaktiver oder nicht erreichbarer Proxy führt zum Fehler, niemals zum direkten Versand oder Wechsel auf einen anderen Proxy. SMTP-Verbindungsfehler behalten die bestehenden Regeln zum Pausieren und zur manuellen Prüfung bei. Eine feste Proxy-Verbindung garantiert keine Posteingangszustellung und dient nicht zur Umgehung von Provider-Limits.

### HTTP-Betrieb und SMTP-Verschlüsselung

Das Panel und der Kampagnenversand funktionieren mit HTTP und HTTPS ohne zusätzliche Freigabe. `app.url` muss auf die für Empfänger erreichbare Panel-Adresse zeigen, damit Abmeldelinks funktionieren; eine bisherige Einstellung `app.allow_insecure_http` wird nicht mehr ausgewertet. Die Navigation zeigt bei HTTP einen Warnhinweis, blockiert aber keine Funktionen. SMTP-STARTTLS bzw. SSL/TLS bleibt unabhängig davon aktiv und prüft weiterhin das Mailserver-Zertifikat, auch beim Versand über den fest zugewiesenen SOCKS5-, HTTP- oder HTTPS-Proxy. Ein eigenes HTTPS-Zertifikat für das Panel ist dafür nicht erforderlich.

SMTP-TLS/SSL schützt **nicht** die Browser-Verbindung zum Panel oder HTTP-Abmeldelinks. Bei HTTP können Passwörter, Sitzungen, SMTP-Zugangsdaten und Abmeldetoken von Dritten mitgelesen oder verändert werden. HTTP deshalb nur lokal oder in einem abgeschotteten Netz verwenden. Für öffentliche Nutzung wird dringend empfohlen, `app.url` auf HTTPS zu ändern und HTTP in IIS auf HTTPS umzuleiten bzw. zu deaktivieren.

## Automatischer Versand unter Windows

Der Browser muss nicht geöffnet bleiben. Der Worker verarbeitet pro Aufruf höchstens eine Nachricht. Die Windows-Aufgabe startet ihn jede Minute; das Kampagnenintervall ist die Mindestzeit zwischen Nachrichten derselben Kampagne. Standard sind **180 Sekunden**, im Entwurf einstellbar von **60 bis 86400 Sekunden** und während des Versands im Panel veränderbar. Durch das minütliche Polling kann eine Nachricht nach Fälligkeit bis zu etwa eine weitere Minute warten. Kleine Intervallwerte umgehen weder Provider-Limits noch diese Polling-Grenze.

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
- Verbindungsabbrüche ohne klaren SMTP-Antwortcode erhalten den Status `unknown`, pausieren die Kampagne und werden **nicht automatisch erneut gesendet**, weil der Server die Nachricht möglicherweise bereits angenommen hatte. Nach einem Worker-Absturz wird eine solche laufende Nachricht ebenfalls nicht blind wiederholt.
- Kritische Antworten (z. B. nicht autorisierte SMTP-Anmeldung) pausieren die Kampagne, damit nicht wiederholt an weitere Empfänger mit einer fehlerhaften Konfiguration gesendet wird. Nach Korrektur der Konfiguration muss ein Administrator die Kampagne bewusst fortsetzen.
- Jede Nachricht enthält signierte individuelle Abmeldelinks sowie `List-Unsubscribe`- und `List-Unsubscribe-Post`-Header. Eine Abmeldung setzt den Kontakt auf `unsubscribed`, fügt ihn der zentralen Suppression-Liste hinzu und entfernt ausstehende Empfängerjobs. HTTP- und HTTPS-Abmeldelinks werden unterstützt; für öffentliche Nutzung wird HTTPS dringend empfohlen. RFC-8058-One-Click-Abmeldung bei Mailprovidern setzt HTTPS voraus.
- Das CMS stellt keine DKIM-Signierung bereit. Für Posteingangszustellung sind eigene SPF-, DKIM- und DMARC-Konfiguration und die SMTP-Provider-Vorgaben erforderlich; der technische SMTP-Test garantiert keine Zustellung.
- Versand darf nur an Kontakte mit gültiger, nachweisbarer Rechtsgrundlage erfolgen. Keine gekauften oder gescrapten Verteiler importieren. Keine Proxy-Rotation oder Provider-Limit-Umgehung.

## Funktionen und offene Module

Implementiert: Login/Ersteinrichtung, rollenbasierter Zugriff, CSRF, Audit-Logs, Listenverwaltung, Textimport mit Duplikat- und Suppression-Prüfung, Kampagnenentwürfe, ein SMTP-Profil mit verschlüsseltem Passwort und optionalem festem SOCKS5-/HTTP-/HTTPS-Proxy, SMTP-Verbindungstest, Queue, Worker, konfigurierbares Intervall, Pause/Fortsetzen/Abbrechen, Live-Polling und signierte Web-/RFC-8058-Abmeldung, optionale IMAP-Bounce-Verarbeitung, DNS-Deliverability-Check, Vorlagen, CSV-Export mit Auditlog, System Health, Proxy-Inventarverwaltung und Massenimport für alle drei Proxy-Protokolle.

Noch offen: mehrere SMTP-Profile und Versandprofil-Auswahl, vollständige SMTP-Diagnoseberichte, vollständiger Zustellbericht-/Fehlerexport, separater Proxy-Netzwerkchecker/Exit-IP-Prüfung, umfassender Rich-Text-Editor/Template-Preview, Scheduler mit Zeitzonen, CSV-/TXT-Dateiimport, benutzerdefinierte Segmente/Felder, Nutzerverwaltung/komplette Rollenmatrix und Datenschutzlöschung. DNS-Deliverability und System Health sind Diagnosehinweise, keine Zustellgarantie. Diese Grenzen werden nicht als fertige Funktionen dargestellt.

## Prüfungen

PHP-Syntax kann lokal mit `php -l` für die PHP-Dateien geprüft werden. Der isolierte Smoke-Test für SMTP-Passwortverschlüsselung, signierte Abmeldetokens und sichere Personalisierung läuft mit:

```powershell
& 'C:\PHP\php.exe' 'C:\inetpub\wwwroot\TheRealOne\tests\mailer-security-smoke.php'
& 'C:\PHP\php.exe' 'C:\inetpub\wwwroot\TheRealOne\tests\proxy-smtp-smoke.php'
```

Der Proxy-Smoke-Test benötigt zusätzlich `pdo_sqlite`, ein verfügbares `proc_open` und PHP-CLI in `PHP_BINDIR`. Er verwendet ausschließlich lokal gestartete SOCKS5-/HTTP-/HTTPS-/SMTP-Fixtures, prüft IPv4/IPv6, Anmeldung, CONNECT-Ablehnung/Timeout, unvollständige und übergroße Header, STARTTLS und implizites TLS einschließlich separater Zertifikatsprüfung für Proxy und SMTP-Server und versendet keine externen E-Mails. Fehlermeldungen für absichtlich abgelehnte Verbindungen sind dabei erwartbar.

Echte SMTP-Verbindung, IIS-Rechte, geplante Aufgabe und Datenbankmigration müssen zusätzlich in einer kontrollierten Testumgebung mit einem eigens autorisierten SMTP-Server geprüft werden. Im produktiven Versand gibt es keinen Simulationsmodus.
