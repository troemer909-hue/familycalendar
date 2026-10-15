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
der exportierende Kalender sie bereits aufgelöst liefert; eine eigene
RRULE-Auswertung ist (wie im Konzept vorgesehen) noch offen.
