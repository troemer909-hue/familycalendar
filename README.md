# Familienkalender – zusammengeführtes Projekt (Backend + Client, eine Subdomain)

Backend-API (`/api/...`) und der reine PHP-Client (`login.php`,
`calendar.php`, ...) laufen hier unter **einem** Document Root, auf
**einer** Subdomain. Funktioniert, weil:

- Die Client-Seiten (`login.php`, `calendar.php`, ...) echte Dateien sind
  → Apache liefert sie direkt aus, ohne Umweg.
- Nur `/api/...`-Aufrufe (z. B. `/api/events/5`, die als Datei nicht
  existieren) werden per `.htaccess` intern zu `api.php` umgeleitet – dem
  API-Frontcontroller (früher `index.php` im separaten Backend-Projekt).
- `index.php` ist jetzt die schlichte Startseite des Clients (leitet zu
  `login.php` oder `calendar.php` weiter).
- Der Client ruft die API intern per cURL über die **eigene** Domain auf
  (`DEFAULT_API_BASE_URL` = die eigene Subdomain) – kein CORS-Thema, da
  serverseitig.

Kein Namens-/Dateikonflikt: Backend-Klassen liegen im Namespace
`Familienkalender\...` (`src/Core`, `src/Controllers`, `src/Middleware`),
Client-Code ist global (`src/ApiClient.php`, `src/Helpers.php`,
`src/bootstrap.php`) – beides verträgt sich in einem gemeinsamen `src/`.

## Installation auf Shared Hosting

1. **Datenbank anlegen** und `database/schema.sql` per phpMyAdmin
   importieren (wie gehabt).
2. **Eine Subdomain** anlegen, z. B. `kalender.eastcon.eu`, Document Root
   auf `public/` dieses Projekts setzen.
3. `.env` aus `.env.example` erstellen, DB-Zugangsdaten + `APP_SECRET`
   eintragen.
4. In `src/bootstrap.php` die Konstante `DEFAULT_API_BASE_URL` auf die
   eigene Domain setzen, z. B.:
   ```php
   const DEFAULT_API_BASE_URL = 'https://kalender.eastcon.eu';
   ```
5. HTTPS aktivieren (Let's Encrypt) – Pflicht, da Session-Cookies
   `secure` gesetzt sind.
6. `https://kalender.eastcon.eu/api/health` aufrufen → sollte
   `{"status":"ok",...}` liefern. Danach `https://kalender.eastcon.eu/`
   aufrufen → sollte zur Anmeldung weiterleiten.
7. **Optional, nur für Push-Benachrichtigungen:** `composer install`
   ausführen und VAPID-Schlüssel erzeugen (siehe Abschnitt
   „Push-Benachrichtigungen“ unten). Ohne diesen Schritt läuft alles
   andere unverändert weiter, Erinnerungen gehen dann nur per Mail.

## Struktur

```
public/
  api.php              ← API-Frontcontroller (JSON, /api/...)
  index.php            ← Startseite, leitet zu login.php/calendar.php
  login.php, calendar.php, event_form.php, members.php, ...
  .htaccess            ← leitet nur /api/... zu api.php um
src/
  Core/, Controllers/, Middleware/   ← Backend (namespaced)
  ApiClient.php, Helpers.php, bootstrap.php   ← Client (global)
database/schema.sql
.env.example
```

## Warum kein direkter (In-Process-)Aufruf statt Self-HTTP?

Der Client ruft die eigene API aktuell ganz normal per HTTPS auf – wie
ein externer Client auch. Das ist bewusst so belassen: beide Codeteile
bleiben unverändert und unabhängig testbar (die API funktioniert
weiterhin eigenständig für eine spätere PWA/App). Der Umweg über eine
echte HTTP-Anfrage an die eigene Domain kostet etwas Overhead (i. d. R.
einige Millisekunden), ist für eine Familienkalender-Anwendung aber
irrelevant. Bei Bedarf lässt sich das später zu direkten PHP-Aufrufen
(ohne HTTP-Overhead) umbauen – dann würde der Client die Controller aus
`src/Controllers` direkt ansprechen statt über `ApiClient`.

## Rest der Bedienung

Identisch zum bisherigen Client (siehe vorherige Beschreibung): Login,
Monatskalender, Termine anlegen/bearbeiten/löschen, Familienmitglieder
verwalten (Profil ohne Login, Upgrade/Downgrade) – alles ohne
JavaScript, Session-basiert pro Browser.

## Kalender-Import & -Abos (neu)

Über „Kalenderquellen verwalten“ (Link im Kalender-Sidebar,
`calendar_sources.php`) lassen sich externe Termine einbinden:

- **ICS-Import (einmalig)** – `.ics`-Datei hochladen (z. B. Export aus
  Outlook/Google Kalender). Wird per eigenem, Composer-freiem ICS-Parser
  (`src/Core/IcsParser.php`) gelesen und als Termine mit `source=import`
  angelegt.
- **CSV-Import (einmalig)** – Tabelle mit Spalten Titel/Beginn/Ende
  (optional Beschreibung, Ort, Ganztägig; deutsche und englische
  Spaltennamen werden erkannt, siehe `src/Core/CsvImporter.php`).
- **ICS-Abo (laufend)** – externe ICS-Adresse (z. B. Vereins- oder
  freigegebener Google-Kalender) wird beim Einrichten sofort und danach
  automatisch alle *X* Minuten neu geladen. Für die wiederkehrende
  Aktualisierung auf Shared Hosting per Cronjob einrichten:

  ```
  php /pfad/zum/projekt/bin/sync-calendar-sources.php
  ```

  (z. B. alle 15 Minuten aufrufen lassen – welche Quelle tatsächlich
  neu geladen wird, steuert das pro Quelle eingestellte Sync-Intervall.)

Jede Quelle kann optional einer einzelnen Person zugeordnet werden
(„nur für diese Person“, sonst gilt sie für die ganze Familie). Ein
erneuter Sync/Import aktualisiert bestehende Termine anhand ihrer
externen UID statt sie zu duplizieren (Unique Key
`calendar_source_id` + `external_uid`, siehe `database/schema.sql`
bzw. `database/migrations/002_calendar_source_sync.sql` für bereits
bestehende Installationen). Löschen einer Quelle entfernt nur die
Quelle selbst – bereits importierte Termine bleiben erhalten.

Zeitzonen werden beim ICS-Import bewusst nicht umgerechnet (lokale
Zeit wird 1:1 übernommen) – für eine Familie in einer Zeitzone
ausreichend. Wiederkehrende Termine (RRULE) aus importierten
Kalendern werden als Einzeltermine pro Vorkommen übernommen, sofern
der exportierende Kalender sie bereits aufgelöst liefert.

## Wiederkehrende Termine (neu)

Beim Neuanlegen eines Termins (`event_form.php`) lässt sich eine
Wiederholung wählen: Täglich/Wöchentlich/Monatlich/Jährlich, mit
„Anzahl Wiederholungen“ oder „Enddatum“. Daraus wird eine RRULE
(`FREQ=...;COUNT=...` oder `FREQ=...;UNTIL=...`) gebaut und von
`src/Core/RRule.php` (eigener, Composer-freier Expander - siehe
composer.json "suggest": rlanvin/php-rrule für komplexere Regeln wie
BYDAY) aufgelöst.

**Wichtig: wiederkehrende Termine werden MATERIALISIERT**, nicht
virtuell berechnet - jedes Vorkommen landet als eigene Zeile in
`events`. Der erste Termin ist der „Master“ (trägt die
`recurrence_rule`), jedes weitere Vorkommen ist eine eigenständige
Instanz mit `recurrence_parent_id` = Master-ID (neue Spalte, siehe
`database/migrations/004_recurring_events.sql` für bestehende
Installationen). Das hat zwei Konsequenzen:

- Eine einzelne Instanz lässt sich wie jeder normale Termin
  bearbeiten/verschieben/löschen, ohne die restliche Serie zu
  beeinflussen ("nur diesen Termin löschen").
- Den Master zu löschen löscht per `ON DELETE CASCADE` automatisch
  die GESAMTE restliche Serie mit ("ganze Serie löschen",
  `DELETE /api/events/{id}/series`, nur auf dem Master möglich).

Maximal 366 Vorkommen pro Serie (Sicherheitsgrenze gegen versehentlich
endlose Regeln - RRULE ohne COUNT/UNTIL wird abgelehnt). Eine bestehende
Serie lässt sich aktuell **nicht** nachträglich in der Wiederholung
ändern (z. B. von wöchentlich auf monatlich) - dafür müsste man sie
löschen und neu anlegen. BYDAY (z. B. "jeden Mo+Mi+Fr") wird ebenfalls
noch nicht unterstützt, nur FREQ+INTERVAL+COUNT/UNTIL.

## Erinnerungen (neu)

Jeder Login-Nutzer kann sich im Terminformular (`event_form.php`, Bereich
„Meine Erinnerungen“, nur bei bestehenden Terminen sichtbar) eigene
Erinnerungen zu einem Termin einrichten – unabhängig davon, wer den
Termin angelegt hat (`ReminderController`, Tabelle `reminders`).
Vorlaufzeiten: 15/30/60/120 Minuten oder 1-2 Tage vorher.

Der tatsächliche Versand läuft per E-Mail (PHP `mail()`, keine
Composer-Abhängigkeit) über einen Cron-Job, der auf Shared Hosting
alle paar Minuten laufen sollte:

```
php /pfad/zum/projekt/bin/send-reminders.php
```

Eine Erinnerung gilt als fällig, wenn (Terminbeginn − Vorlaufzeit) ≤
jetzt und der Termin noch nicht begonnen hat; danach wird sie als
`sent` markiert und nicht erneut verschickt. Absenderadresse/-name
werden über `MAIL_FROM` / `MAIL_FROM_NAME` in `.env` konfiguriert.
Für bereits bestehende Installationen: Migration
`database/migrations/003_reminders_unique.sql` einmalig ausführen
(verhindert doppelte Erinnerungen bei gleichem Termin/Nutzer/Vorlauf).

Zentrale Ein/Aus-Schalter für diese Erinnerungen (welche Terminbereiche,
welche Kanäle) stehen in den „Benachrichtigungseinstellungen“ – siehe
Abschnitt weiter unten.

## Push-Benachrichtigungen (neu)

**Einzige Stelle im Projekt mit JavaScript.** Web Push funktioniert
technisch nur über einen Service Worker (`public/sw.js`) + die
PushManager-API im Browser – dafür gibt es keinen reinen PHP-Weg. Der
Rest der App bleibt unverändert JS-frei.

**Einzige Stelle mit einer echten Composer-Abhängigkeit.** Die
VAPID-Verschlüsselung (ECDH + HKDF + AES-128-GCM) ist echte
Sicherheits-Kryptografie – die wird bewusst NICHT selbst nachgebaut,
sondern über die geprüfte Library `minishlink/web-push` bezogen
(plus `php-http/curl-client` + `nyholm/psr7` als minimaler HTTP-Client,
den die Library benötigt). Dafür einmalig:

```
composer install
php bin/generate-vapid-keys.php
```

Die ausgegebenen `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` in `.env`
eintragen (`VAPID_SUBJECT` ist eine `mailto:`-Adresse, über die
Push-Anbieter den Betreiber im Problemfall kontaktieren können).

**Alles andere bleibt ohne composer voll funktionsfähig.** Fehlt
`vendor/` (kein `composer install` ausgeführt) oder sind die
VAPID-Keys nicht gesetzt, meldet `PushSender::isConfigured()` das
zuverlässig zurück – `push_subscribe.php` zeigt dann nur einen
Hinweistext statt des Aktivieren-Buttons, und Erinnerungen laufen
unverändert nur per E-Mail. Push ist rein additiv.

Jeder Login-Nutzer aktiviert Push pro Browser/Gerät über
„Push-Benachrichtigungen“ im Kalender-Sidebar
(`push_subscribe.php`). `bin/send-reminders.php` schickt fällige
Erinnerungen jetzt an BEIDE konfigurierten Kanäle (Mail und, falls
vorhanden, alle Push-Abos des Nutzers); eine Erinnerung gilt als
erledigt, sobald mindestens ein Kanal tatsächlich versucht wurde.
Abgelaufene/ungültige Push-Abos (HTTP 404/410 vom Push-Anbieter)
werden dabei automatisch aus `push_subscriptions` entfernt.

Für bereits bestehende Installationen: Migration
`database/migrations/005_push_subscriptions_unique.sql` einmalig
ausführen (ergänzt den Hash-Spalten-Unique-Key, der doppelte Abos
desselben Geräts verhindert).

## Benachrichtigungseinstellungen (neu)

Zentrale Seite „Benachrichtigungseinstellungen“ im Kalender-Sidebar
(`public/notification_settings.php`, `NotificationPreferenceController`,
Tabelle `notification_preferences`). Jeder Login-Nutzer hat dort vier
Schalter plus eine Standard-Vorlaufzeit:

- **Terminbereich**: Erinnerungen zu persönlichen Terminen (`own_events`)
  bzw. zu Familienterminen (`family_events`) an/aus.
- **Kanal**: per E-Mail (`channel_mail`) bzw. per Push
  (`channel_push`) an/aus.
- **`reminder_default_minutes`**: Vorschlagswert für die
  Vorlaufzeit-Auswahl beim Anlegen neuer Erinnerungen in
  `event_form.php`.

Wichtig: Diese Schalter wirken **zusätzlich** zu den einzelnen
Erinnerungen, die ein Nutzer pro Termin anlegt (`ReminderController`),
nicht statt ihnen. Ist z. B. „Familientermine“ hier deaktiviert, stellt
`bin/send-reminders.php` fällige Erinnerungen zu `events.scope = 'family'`
nicht zu – die Erinnerung bleibt dabei unverändert bestehen (`sent = 0`)
und wird zugestellt, sobald der Schalter wieder aktiviert wird. Sind
dagegen beide Kanäle deaktiviert, gibt es nichts zuzustellen; die
Erinnerung wird dann direkt als erledigt markiert (kein sinnloses
erneutes Prüfen bei jedem Cron-Lauf).

Für bereits bestehende Installationen: Migration
`database/migrations/006_notification_preferences_channels.sql` einmalig
ausführen (ergänzt `channel_mail`/`channel_push`, die `own_events`,
`family_events` und `reminder_default_minutes` sind bereits seit der
Registrierung/dem Upgrade auf einen Login vorhanden).

## CSRF-Schutz (neu)

Alle POST-Formulare des (session-basierten) PHP-Clients tragen jetzt ein
verstecktes `csrf_token`-Feld, das gegen ein in der PHP-Session
hinterlegtes Token geprüft wird (`csrfToken()`/`csrfField()` in
`src/Helpers.php`, `requireCsrf()`/`requireCsrfJson()` in
`src/bootstrap.php`). Jeder POST-Handler (`event_save.php`,
`member_add.php`, `login_submit.php`, … – alle 16 Stück) ruft
`requireCsrf()` als ERSTES auf, bevor irgendeine Aktion gegen die API
läuft; bei fehlendem/falschem Token gibt es sofort `HTTP 400` statt
jeder weiteren Verarbeitung.

Betrifft nur den Client (`public/*.php`), nicht die JSON-API selbst
(`/api/...`): die ist bewusst zustandslos (Bearer-Token statt Cookie,
siehe Abschnitt „Warum kein direkter Aufruf“) und damit nicht
CSRF-anfällig – ein Angreifer kennt den Bearer-Token eines fremden
Nutzers nicht und kann ihn nicht automatisch mitschicken lassen, anders
als bei Session-Cookies.

Die zwei fetch()-basierten JSON-Endpunkte für Push
(`push_subscribe_save.php`/`push_unsubscribe_save.php`) bekommen das
Token stattdessen im JSON-Body mit (`requireCsrfJson()`), eingebettet
als JS-Variable in `push_subscribe.php`.

Ergänzt die bereits bestehende `SameSite=Lax`-Cookie-Einstellung
(`src/bootstrap.php`), die die meisten klassischen Cross-Site-POSTs
schon vorher blockiert hat – die expliziten Tokens sind eine zweite,
unabhängige Verteidigungsschicht (u. a. falls ein Browser SameSite
nicht unterstützt oder ein Angriff von einer Subdomain käme).

Keine Datenbankänderung nötig, reiner Session-Mechanismus.

## Passwort-Reset (neu)

„Passwort vergessen?“ auf der Login-Seite führt zu
`public/forgot_password.php` (E-Mail eingeben) →
`AuthController::requestPasswordReset()` erzeugt einen zufälligen Code
(32 Byte, `random_bytes`), speichert davon NUR den SHA-256-Hash in der
neuen Tabelle `password_resets` (60 Minuten gültig) und verschickt den
rohen Code per Mail (`Mailer::send()`, PHP `mail()`, keine neue
Abhängigkeit). `public/reset_password.php` nimmt den Code (aus dem
Link oder manuell eingetippt) plus neues Passwort entgegen →
`AuthController::confirmPasswordReset()` prüft Hash + Ablauf, setzt das
Passwort und invalidiert alle offenen Codes des Nutzers.

Zwei bewusste Sicherheitsentscheidungen:

- **Kein User-Enumeration-Leak**: `password-reset/request` antwortet
  IMMER mit derselben generischen Erfolgsmeldung, egal ob die
  E-Mail-Adresse existiert – nur bei existierendem Konto wird tatsächlich
  eine Mail verschickt (analog zur bereits bestehenden `login()`-Logik).
- **Kein Host-Header-Link**: Der Link in der Mail wird ausschließlich aus
  der server-seitig konfigurierten `APP_PUBLIC_URL` (`.env`) gebaut,
  NICHT aus dem Host-Header des Requests (klassische "Password Reset
  Poisoning"-Lücke). Ist `APP_PUBLIC_URL` nicht gesetzt, bekommt der
  Nutzer statt eines Links nur den rohen Code zum manuellen Eintragen auf
  `reset_password.php` – funktioniert immer, auch ohne diese Konfiguration.

Für bereits bestehende Installationen: Migration
`database/migrations/007_password_resets.sql` einmalig ausführen.

## Rate-Limiting beim Login (neu)

`AuthController::login()` (`src/Core/RateLimiter.php`) begrenzt
fehlgeschlagene Login-Versuche über zwei unabhängige Grenzen, jeweils in
einem 15-Minuten-Fenster:

- **Max. 5 Fehlversuche pro E-Mail-Adresse** – verhindert Brute-Force
  gegen EIN Konto, egal von wie vielen IPs aus.
- **Max. 20 Fehlversuche pro IP-Adresse** – verhindert schnelles
  Durchprobieren vieler E-Mail-Adressen von einer Quelle aus
  (Enumeration/Credential-Spraying).

Wird eine Grenze erreicht, antwortet `/api/auth/login` mit `HTTP 429`
(derselbe generische Fehlertext wie bei falschem Passwort, kein
zusätzlicher Enumeration-Leak). Ein erfolgreicher Login löscht die
eigenen Fehlversuche sofort wieder. Bewusst datenbankbasiert statt z.B.
Redis/APCu, damit kein zusätzlicher Dienst nötig ist; die IP wird aus
`REMOTE_ADDR` gelesen, NICHT aus `X-Forwarded-For` (das sich ohne
vertrauenswürdigen Reverse-Proxy beliebig fälschen lässt und die
IP-Grenze sonst wirkungslos machen würde) – läuft die App hinter einem
Load Balancer/Reverse-Proxy, müsste das entsprechend angepasst werden.

Die Tabelle `login_attempts` räumt sich selbst auf (jeder Fehlversuch
löscht beiläufig alles älter als 1 Tag), kein eigener Cron-Job nötig.

Für bereits bestehende Installationen: Migration
`database/migrations/008_login_attempts.sql` einmalig ausführen.

## Plattform-Verwaltung: Pläne & Billing (neu)

Zweite, von der familieninternen Admin-Rolle (`family_members.role`)
bewusst getrennte Berechtigungsebene: **Plattform-Admins**
(`users.is_platform_admin`) können über „Plattform-Verwaltung“ im
Kalender-Sidebar ALLE Familien verwalten, nicht nur ihre eigene –
analog zu einem separaten Platform-Operator-Modus statt einer
normalen Tenant-Rolle (keine Mischung mit Familienadmin-Rechten).

- **`admin_plans.php`** (`AdminPlanController`): SaaS-Pakete (Tabelle
  `plans`) anlegen/bearbeiten/löschen – Code, Name, Preis/Monat, max.
  Mitglieder, max. Kalender-Abos. Löschen schlägt bewusst fehl
  (SQLSTATE 23000 → `409`), solange noch Familien diesen Plan nutzen.
- **`admin_families.php`** (`AdminFamilyController`): jede Familie
  einem Plan zuordnen und ihren Abrechnungsstatus setzen
  (`trial`/`active`/`suspended`/`cancelled`), mit Status-Filter. Reine
  Status-Pflege – **keine Zahlungsabwicklung**, die bleibt bewusst
  außerhalb des MVP (siehe `schema.sql`-Kommentar „Familien starten
  manuell im Trial“).

Durchsetzung passiert ausschließlich serverseitig
(`TenantContext::requirePlatformAdmin()`, frisch bei jedem Request aus
der DB gelesen in `AuthMiddleware` – keine zwischengespeicherte Rolle im
Token). Die Admin-Seiten sind für alle eingeloggten Nutzer verlinkt;
ohne Berechtigung landet man nach einem Klick einfach mit einer
verständlichen Meldung zurück auf dem Kalender (`handleForbidden()`).

Es gibt bewusst KEINE Selbstbedienungs-Funktion, um sich selbst zum
Plattform-Admin zu machen. Das allererste Plattform-Admin-Konto wird
einmalig manuell per SQL gesetzt:

```sql
UPDATE users SET is_platform_admin = 1 WHERE email = 'du@example.com';
```

Keine Migration nötig – `users.is_platform_admin` existiert bereits seit
dem ursprünglichen Schema, war bisher nur ungenutzt.

## Admin-Rollen-Übertragung (neu)

Es gibt pro Familie bewusst GENAU EINEN Familienadmin
(`family_members.role = 'admin'`, nicht zu verwechseln mit den
Plattform-Admins oben). Auf „Familienmitglieder“ (`members.php`) kann
der aktuelle Admin die Rolle an ein anderes Vollmitglied (muss bereits
ein eigenes Login haben – ein reines Profil ohne Login kann nicht Admin
werden) übergeben:

1. „Admin-Rolle übergeben“ führt zu einer Bestätigungsseite
   (`member_transfer_admin_confirm.php`), die erklärt, was passiert – es
   gibt bewusst KEINEN JavaScript-`confirm()`-Dialog, passend zur
   JS-freien Architektur des restlichen Clients.
2. Nach Bestätigung (`MemberController::transferAdmin()`,
   `POST /api/members/{id}/transfer-admin`) wird das Ziel-Mitglied zum
   Admin, der bisherige Admin zu einem normalen Mitglied – in einer
   Transaktion.

Kein Token-Refresh/Logout nötig: die Rolle wird bei jedem Request frisch
aus der DB gelesen (`AuthMiddleware`), die Änderung wirkt also sofort auf
den nächsten Request des bisherigen Admins. Keine Migration nötig.

## Outbound-ICS-Export (neu)

Gegenstück zum Import/Abo aus „Kalenderquellen“: über „Kalender-Abo
(ICS-Export)“ im Sidebar erzeugt ein Admin einen geheimen Link
(`public/ics_export.php`, `IcsExportController`,
`families.ics_export_token`), den man in Google Kalender/Outlook/Apple
Kalender als Abo eintragen kann - read-only, wird dort automatisch
regelmäßig neu geladen.

- **`GET /api/ics/{token}.ics`** ist bewusst NICHT per Bearer-Token
  geschützt (Kalender-Apps können keine Auth-Header senden) - die
  Sicherheit kommt stattdessen vom geheimen, 128-Bit-zufälligen Token
  selbst in der URL. Jeder Familienadmin kann den Link jederzeit
  erneuern (alter Link wird sofort ungültig) oder deaktivieren.
- **`src/Core/IcsExporter.php`** ist das Gegenstück zu `IcsParser.php`
  (RFC 5545 in die andere Richtung: Text-Escaping, 75-Octet-
  Zeilenfaltung, inklusives DB-Enddatum ↔ exklusives ICS-`DTEND` bei
  ganztägigen Terminen). Beide Klassen wurden gegeneinander getestet
  (Export → Re-Import muss wieder denselben Termin ergeben).
- Enthält ein rollierendes Zeitfenster (6 Monate zurück bis 2 Jahre
  voraus) statt aller Termine aller Zeiten - wiederkehrende Termine
  sind bereits materialisiert (siehe RRULE), ein unbegrenzter Export
  würde sonst stetig wachsen.

Für bereits bestehende Installationen: Migration
`database/migrations/009_ics_export_token.sql` einmalig ausführen.

## Tests (neu)

PHPUnit ist eine reine Dev-Abhängigkeit (`require-dev` in `composer.json`)
– ändert nichts an der Produktions-Installation, die weiterhin ganz ohne
`composer install` lauffähig bleibt (siehe `public/api.php`-Kommentar zum
Autoloader). Zwei getrennte Suiten:

```
composer install          # einmalig, Dev-Abhängigkeiten
composer test              # Unit-Tests - keine Datenbank nötig, läuft überall sofort
composer test:integration  # Integration-Tests - braucht eine echte MySQL-Testdatenbank
```

- **`tests/Unit/`**: komplett DB-frei, testet die selbst geschriebene
  Business-Logik direkt (`IcsParser`, `CsvImporter`, `RRule` – inkl.
  Regressionstest für den ursprünglichen Monats-Clamping-Bug –, `Token`,
  `Helpers`-Funktionen). Läuft ohne jede Konfiguration, auch lokal ohne
  MySQL.
- **`tests/Integration/`**: startet `public/api.php` echt über den
  PHP-Built-in-Webserver (`tests/Support/ApiServer.php` – Controller
  rufen `exit()` über `Response::json()` auf, lassen sich also nicht
  direkt im selben Prozess testen) und fährt echte HTTP-Requests dagegen
  (Registrierung/Login/Rate-Limiting, Termine anlegen/ändern/löschen,
  Mandantentrennung zwischen zwei Familien). Braucht eine MySQL-Datenbank,
  deren Name auf `_test` endet, plus `DB_HOST`/`DB_USER`/`DB_PASSWORD` und
  `ALLOW_TEST_DB_WIPE=1` als Umgebungsvariablen – Sicherheitsgurt gegen
  das versehentliche Leerräumen einer echten Datenbank
  (`tests/Support/IntegrationDb.php`).
- Ohne konfigurierte Testdatenbank werden Integration-Tests lokal
  einfach übersprungen; ist dagegen `CI=1` gesetzt, schlagen sie bewusst
  **fehl** statt leise zu überspringen – eine kaputte CI-DB-Konfiguration
  soll nie unbemerkt grün durchlaufen.

GitHub-Actions-Workflow unter `.github/workflows/tests.yml`: startet
einen MySQL-Service-Container, installiert die Dev-Abhängigkeiten,
prüft `php -l` über das gesamte Repo und führt beide Suiten aus.

Für End-to-End-Tests, die den Inhalt einer tatsächlich "versendeten"
Mail brauchen (Passwort-Reset-/Einladungs-Codes), läuft der
API-Testserver mit `sendmail_path` auf `tests/Support/fake-sendmail.sh`
umgebogen – schreibt jede Mail in eine Datei statt sie wirklich zu
verschicken, ausgelesen über `tests/Support/MailCapture.php`. Kein
echter Mailserver in CI nötig.

## Mitglieder-Einladung (neu)

Ersetzt den bisherigen Ablauf, bei dem der Admin selbst ein Passwort
für eine andere Person festlegen und ihr dann außerhalb der App
mitteilen musste. Jetzt gilt für das Hochstufen eines Profils (ohne
Login) zu einem eigenen Vollmitglied-Login dasselbe Muster wie beim
Passwort-Reset:

1. Admin gibt auf „Familienmitglieder“ nur die E-Mail-Adresse der
   Person ein (`member_invite_form.php` → `MemberController::invite()`).
2. Die eingeladene Person bekommt einen Link (`public/
   accept_invite.php`, 7 Tage gültig) und legt dort ihr **eigenes**
   Passwort fest (`AuthController::acceptInvite()`) – kein
   Passwort-Teilen außerhalb der App mehr nötig. Anders als beim
   Passwort-Reset wird sie danach direkt eingeloggt, ohne sich separat
   anmelden zu müssen.

Technisch identisch zum Passwort-Reset-Flow: eigene Tabelle
`member_invites`, gespeichert wird nur der SHA-256-Hash des Tokens, der
Link wird aus `APP_PUBLIC_URL` gebaut (ohne diese Konfiguration gibt es
stattdessen den rohen Code zum manuellen Eintragen), eine erneute
Einladung invalidiert die vorherige.

Für bereits bestehende Installationen: Migration
`database/migrations/010_member_invites.sql` einmalig ausführen.

## Selbst-Update (neu)

„Knopfdruck“-Update für Installationen, die als echter Git-Checkout auf
dem Server laufen (nicht per ZIP/FTP hochgeladen): zieht den aktuellen
Stand des Branches nach und wendet dabei automatisch alle neuen
`database/migrations/*.sql` an - ohne manuellen SSH-/phpMyAdmin-Schritt.

**Voraussetzungen (bewusst standardmäßig AUS):**

- Dieser Ordner muss ein echtes `git clone` sein (ein `.git`-Ordner muss
  existieren) - sonst meldet die Seite „Selbst-Update“ im Sidebar klar,
  dass es hier nicht möglich ist.
- In der `.env`: `ENABLE_SELF_UPDATE=1` setzen. Ein Plattform-Admin kann
  damit ein Shell-Kommando (`git fetch`/`git merge --ff-only`) auf dem
  Server auslösen - erst nach bewusster Entscheidung aktivieren.
- `SELF_UPDATE_BRANCH` (Standard `main`) legt fest, welcher Branch
  gezogen wird. Kommt ausschließlich aus der `.env`, niemals aus der
  Weboberfläche oder dem Request - es gibt keinen Weg, darüber einen
  eigenen Branch-Namen oder Befehl einzuschleusen.

**Ablauf** (`SelfUpdateController`, Seite „Selbst-Update“ im Sidebar, nur
für `TenantContext::isPlatformAdmin()`):

1. `GET /api/self-update/status` zeigt den aktuellen Commit und (falls
   erreichbar) ob es auf dem konfigurierten Branch einen neueren Stand
   gibt - verändert nichts.
2. „Jetzt aktualisieren“ → `POST /api/self-update/run`:
   - `git fetch origin <branch>` + `git merge --ff-only origin/<branch>`
     (`src/Core/GitUpdater.php`) - **bewusst `--ff-only` statt `git
     pull`/`reset --hard`**: gibt es auf dem Server abweichende lokale
     Commits, scheitert das Update kontrolliert ab, statt sie
     stillschweigend zu verwerfen.
   - Anschließend wendet `src/Core/MigrationRunner.php` jede Datei aus
     `database/migrations/`, die noch nicht in der Tabelle
     `schema_migrations` vermerkt ist, einmalig an.
   - Jeder Lauf (Erfolg wie Fehler) wird in `self_update_runs`
     protokolliert (wer, wann, von welchem auf welchen Commit, welche
     Migrationen).

Für bereits bestehende Installationen (von VOR diesem Feature):
Migration `database/migrations/011_self_update.sql` einmalig ausführen -
sie legt `schema_migrations`/`self_update_runs` an UND markiert alle
bis dahin vorhandenen Migrationen (002-011) als bereits angewendet,
damit der Runner sie nicht fälschlich ein zweites Mal ausführt.
