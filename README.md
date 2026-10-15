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

Noch offen: eine zentrale Einstellungsseite für
`notification_preferences` (z. B. „nur eigene Termine“ vs. „alle
Familientermine“).

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
