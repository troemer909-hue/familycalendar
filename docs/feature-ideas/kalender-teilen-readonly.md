# Kalender teilen (read-only Web-Link)

## Problem / Motivation
Die App unterstützt bereits den Export des gesamten Familienkalenders als
ICS-Feed über ein Token (`families.ics_export_token`,
`IcsExportController`), gedacht für den Import in externe Kalender-Apps
(Google Kalender, Outlook, Apple Kalender). Das deckt aber nicht den Fall
ab, dass jemand (z.B. die Großmutter, eine Tagesmutter, ein getrennt
lebender Elternteil ohne eigenen Account) einfach schnell im Browser
nachsehen möchte, was anliegt - ohne eigenen Kalender-Client einrichten zu
müssen, und ohne einen vollen Familien-Account mit Login zu benötigen.

## Zielgruppe & Nutzen
Familien, die Termine mit Personen außerhalb des eigenen Accounts teilen
möchten, die aber keinen eigenen Login bekommen sollen (Babysitter,
Großeltern, externe Betreuungspersonen). Nutzen: ein einfacher Link, der
im Browser eine schlanke, read-only Ansicht des Kalenders zeigt - kein
App-Download, kein ICS-Import, kein Account.

## Funktionsumfang (v1)
- Erzeugung eines eigenen, vom ICS-Token UNABHÄNGIGEN Freigabe-Tokens pro
  Familie (oder optional pro Mitglied, siehe Offene Fragen), das eine
  schlanke HTML-Seite mit der aktuellen Monats-/Wochenansicht liefert.
- Die Ansicht ist rein lesend: keine Login-Maske, kein Zugriff auf
  Mitglieder-/Einstellungsseiten, keine Möglichkeit, Termine anzulegen
  oder zu bearbeiten.
- Token kann jederzeit widerrufen/neu generiert werden (analog zum
  bestehenden `IcsExportController::regenerate()`-Verhalten).
- Optionale Einschränkung auf bestimmte Mitglieder beim Erzeugen des Links
  (z.B. nur die Termine der Kinder, nicht die der Eltern).

## User Stories
- Als Elternteil möchte ich einen Link erzeugen, den ich der Babysitterin
  schicken kann, damit sie sieht, wann die Kinder welche Termine haben -
  ohne ihr einen vollen Account einzurichten.
- Als Nutzer möchte ich den Freigabe-Link jederzeit widerrufen können,
  falls die Betreuungsperson wechselt.
- Als Nutzer möchte ich beim Teilen auswählen können, welche
  Familienmitglieder in der geteilten Ansicht sichtbar sind.

## Datenmodell
Analog zu `ics_export_token`, aber als eigene Spalte/Tabelle, damit beide
Freigabearten unabhängig voneinander widerrufen werden können:

```sql
CREATE TABLE IF NOT EXISTS calendar_share_links (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  token VARCHAR(64) NOT NULL UNIQUE,
  member_scope VARCHAR(255) NULL,   -- z.B. "2,5" oder NULL = alle Mitglieder
  label VARCHAR(100) NULL,          -- z.B. "Babysitter-Link", frei vom Nutzer vergeben
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Eine eigene Tabelle (statt einer einzelnen Spalte wie bei `families.
ics_export_token`) wird gewählt, weil hier - anders als beim ICS-Export -
mehrere parallele, unterschiedlich scoped Links pro Familie sinnvoll sind
(z.B. ein Link für die Großeltern mit allen Terminen, ein zweiter nur für
die Babysitterin mit den Kinderterminen).

## API-Endpunkte
- `POST /api/share-links`, `GET /api/share-links`,
  `DELETE /api/share-links/{id}` - Verwaltung der Freigabe-Links durch den
  eingeloggten Nutzer, analog zu `IcsExportController`.
- `GET /api/public/calendar/{token}` - NEU, bewusst außerhalb der
  bestehenden Auth-Middleware (kein Bearer-Token, keine Session), liefert
  die Termine im Zeitraum für die im Token hinterlegte `member_scope`.
  Muss besonders sorgfältig implementiert werden, da dieser Endpunkt per
  Definition ohne Login erreichbar ist (siehe Abhängigkeiten).

## UI/UX
- `settings.php` (oder eine neue Unterseite `share_links.php`): Liste
  bestehender Freigabe-Links mit Label, Erstellungsdatum,
  Mitglieder-Scope, "Widerrufen"-Button, sowie ein Formular zum Erzeugen
  eines neuen Links.
- Neue, eigenständige öffentliche Seite `shared_calendar.php?token=...`
  (kein `app-layout`, keine Sidebar, kein `requireLogin()`), die die
  Monatsansicht rein lesend rendert - visuell stark an die bestehende
  `calendar.php`-Monatsansicht angelehnt, aber ohne jegliche
  Bearbeitungslinks ("+"-Icons, Termin-Links führen nicht zu
  `event_form.php`).

## Abhängigkeiten & Integration
- `IcsExportController` dient als Vorlage für Token-Erzeugung/-Widerruf,
  ist aber kein direkter Code-Reuse, da die Ausgabeformate (ICS-Text vs.
  HTML-Seite) grundverschieden sind.
- Der neue öffentliche Endpunkt `GET /api/public/calendar/{token}` muss
  Rate-Limiting bekommen (analog zum bestehenden `login_attempts`-Schutz
  gegen Brute-Force), da ein erratenes/durchprobiertes Token sonst Zugriff
  auf private Familiendaten ermöglichen würde.
- `TenantContext` (bzw. die äquivalente Zugriffsprüfung) muss für diesen
  Endpunkt eine eigene, token-basierte statt JWT-basierte
  Autorisierungslogik bekommen.

## Aufwandsschätzung
**M** - Grundsätzlich ähnlich zum bestehenden ICS-Export (Token-CRUD),
aber mit zusätzlichem Aufwand für die neue öffentliche HTML-Ansicht, den
Mitglieder-Scope-Filter und das nötige Rate-Limiting für einen
unauthentifizierten Endpunkt.

## Offene Fragen / Risiken
- Soll der Link ein Ablaufdatum bekommen (z.B. automatisch nach 90 Tagen
  ungültig werden), um das Risiko dauerhaft kursierender Links zu
  begrenzen?
- Wie granular soll der Mitglieder-Scope sein - nur ganze Mitglieder, oder
  auch nach Kategorie (siehe `kalender-kategorien.md`)?
- Soll die geteilte Ansicht private Notizen/Details (Ort, Beschreibung)
  genauso zeigen wie die interne Ansicht, oder nur Titel/Uhrzeit?
- Security-Review nötig: ein öffentlich erreichbarer, nur durch ein Token
  geschützter Endpunkt ist ein höheres Risiko als die bestehenden,
  Session-/JWT-geschützten Endpunkte.

## Out of Scope (v1)
- Interaktion durch die externe Person (Kommentare, Zusagen/Absagen) -
  die Ansicht ist in v1 rein lesend.
- Passwortschutz zusätzlich zum Token.
- Kombination mit dem ICS-Export (ein Nutzer müsste beide Freigabearten
  separat verwalten).
