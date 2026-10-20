# Mail Marketing CMS – Implementierungsstand

Dieses Repository enthält derzeit die IIS-kompatible PHP-Grundlage, Administrator-Anmeldung, Empfängerlistenverwaltung mit dokumentierter Versandgrundlage und einen Kampagnenentwurfsmanager. **Es ist noch kein vollständiges oder produktionsreifes E-Mail-Marketing-CMS.** Versand, SMTP-Verwaltung, Abmeldung, Proxy-Diagnose, Berichte und weitere Module sind nicht implementiert. Es gibt daher keine Versandfunktion und das System darf noch nicht für Kampagnenversand verwendet werden.

## Voraussetzungen

- Windows Server mit IIS und für PHP konfiguriertem FastCGI-Handler
- PHP 8.2 oder neuer mit PDO-MySQL-Unterstützung
- MySQL 8 oder kompatible MariaDB-Version
- Eine unter IIS konfigurierte HTTPS-Site für den späteren Produktivbetrieb

Es werden weder Composer noch Node.js, npm oder ein Build-Prozess verwendet. Die derzeitige Oberfläche nutzt lokale CSS-Dateien und enthält Bootstrap/Chart.js noch nicht.

## Installation des aktuellen Entwicklungsstands

1. Eine leere MySQL-Datenbank und einen möglichst eingeschränkten Datenbankbenutzer anlegen.
2. `database.sql` mit einem MySQL-Client importieren. Die Datei legt die Datenbank `marketing_cms` an; bei abweichendem Namen Datenbank und Konfiguration anpassen.
3. Zugangsdaten in `config.php` eintragen. Diese Datei muss außerhalb der Versionsverwaltung vertraulich behandelt und auf dem Webserver vor direktem HTTP-Zugriff geschützt bleiben.
4. PHP für IIS/FastCGI einrichten und die Website ausschließlich über HTTPS erreichbar machen.
5. `install.php` aufrufen und einen Administrator mit einem langen, einzigartigen Passwort anlegen.
6. Anmelden und die Funktionen „Übersicht“, „Email Lists“ sowie „Campaign Manager“ prüfen.
7. Nach erfolgreicher Erstinstallation den Zugriff auf `install.php` auf IIS zusätzlich serverseitig sperren. Die Seite blockiert eine erneute Installation, sobald ein Administrator in der Datenbank existiert.

`web.config` enthält Header, deaktiviert Directory Browsing und sperrt interne Pfade sowie SQL-Dateien. IIS-Konfiguration und PHP-FastCGI müssen auf dem Zielserver getestet werden; Zugangsdaten dürfen nicht in Logs oder öffentlich erreichbaren Verzeichnissen abgelegt werden.

## Derzeit implementiert

- PDO-Verbindung mit nativen Prepared Statements und UTF-8.
- Ersteinrichtung des ersten Administrators; Passwort-Hashing mit `password_hash()`.
- Login/Logout, sichere Cookie-Optionen, Sitzungsrotation und Timeout, CSRF-Prüfung, rollenbasierte Prüfung auf geschützten Seiten und IP-begrenzte Login-Versuche.
- Aktivitätsprotokoll für grundlegende Anmelde- und Listenaktionen.
- Empfängerlisten erstellen, umbenennen und löschen.
- Textimport, Syntaxprüfung, Duplikatprüfung, zentrale Suppression-Prüfung und Erfassung der vom Administrator dokumentierten Versandgrundlage.
- Abgemeldete und gebouncte Kontakte werden bei einem erneuten Import nicht reaktiviert.
- Kampagnenentwürfe anlegen, bearbeiten, duplizieren und archivieren; die gespeicherten Entwürfe versenden keine Nachrichten.

Die Importbestätigung ist eine administrative Dokumentation, kein automatischer Nachweis, dass die eingegebene Rechtsgrundlage tatsächlich gültig ist. Importieren Sie nur Kontakte, für die eine rechtlich geprüfte Versandberechtigung belegt ist. In diesem Entwicklungsstand besteht keine Möglichkeit, Marketing-E-Mails zu versenden.

## Noch offen

Die vollständige Aufgabenstellung umfasst wesentlich mehr als die hier implementierte Basis. Insbesondere fehlen aktuell SMTP-Profile und -Tests, Rich-Text-Editor und Vorschau, Versandwarteschlange, Windows-Worker, Live-Monitoring, Abmeldeseiten und RFC-8058-Unterstützung, Bounce-Verarbeitung, Proxy-Verwaltung, Zustellbarkeitsdiagnose, Templates, Scheduler, Exporte/Löschfunktionen, System Health, CSV-/Dateiimport, vollständige Rollenverwaltung, Tests und ein umfassendes produktives Dashboard. Diese Funktionen sind nicht durch Platzhalterbuttons als verfügbar dargestellt.

## Datenbankhinweis

`database.sql` erstellt derzeit Tabellen für Rollen, Administratoren, Audit- und Login-Logs, Einstellungen, Listen, Kontakte, Einwilligungsnachweise, Suppression, Importe und Kampagnenentwürfe. Es ist **nicht** das vollständige Schema aus dem Projektauftrag.
