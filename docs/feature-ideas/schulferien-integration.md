# Schulferien-Integration

## Problem / Motivation
Familien mit Kindern planen ihren Alltag maßgeblich um Schulferien herum (Urlaub, Betreuung, Freizeitaktivitäten). Aktuell muss jede Familie Ferientermine manuell eintragen oder von Hand recherchieren. Alle Bundesländer veröffentlichen ihre Ferientermine jedoch als frei verfügbare ICS-Feeds (bzw. lassen sich daraus ableiten) – diese Daten liegen praktisch "auf der Straße".

## Zielgruppe & Nutzen
Familien mit schulpflichtigen Kindern. Nutzen: Ferientermine erscheinen automatisch im Kalender, ohne manuelle Pflege, und werden bei Änderungen (seltene Korrekturen durch Kultusministerien) automatisch aktualisiert.

## Funktionsumfang (v1)
- Kuratierte Liste öffentlicher ICS-Feed-URLs für Schulferien je Bundesland (16 Einträge, serverseitig gepflegt, kein CMS).
- Im Formular zum Anlegen einer Kalenderquelle (`calendar_source_form`-Flow, siehe `public/calendar_sources.php`) eine Dropdown-Auswahl "Schulferien-Feed hinzufügen" mit Bundesland-Auswahl statt manueller URL-Eingabe – erzeugt im Hintergrund eine normale `stream_ics`-Kalenderquelle.
- Ferientermine erscheinen danach wie jede andere importierte Kalenderquelle im Kalenderraster (ganztägige Termine, eigene Farbe/Kategorie "Ferien").
- Synchronisierung läuft über den bereits vorhandenen Mechanismus (`CalendarSourceController::sync()`, `bin/sync-calendar-sources.php` als Cron).

## User Stories
- Als Elternteil möchte ich beim Anlegen einer Kalenderquelle einfach "Sachsen" auswählen können, statt eine ICS-URL zu suchen und einzufügen.
- Als Nutzer möchte ich, dass sich Ferientermine automatisch aktualisieren, wenn sich offizielle Termine ändern.
- Als Familie mit Kindern in zwei Bundesländern (z.B. nach Umzug) möchte ich zwei Ferien-Feeds gleichzeitig abonnieren können.

## Datenmodell
Keine neue Tabelle nötig – nutzt `calendar_sources` unverändert (`type='stream_ics'`). Optional ein neues Feld, um Ferien-Quellen im UI besonders zu kennzeichnen (z.B. für ein Icon oder zum Verhindern von Duplikaten):
```sql
ALTER TABLE calendar_sources
  ADD COLUMN preset_key VARCHAR(50) NULL AFTER type;
-- z.B. 'ferien_sachsen', 'ferien_bayern' - NULL bei frei eingegebenen Quellen.
-- Erlaubt eine Prüfung "ist für dieses Bundesland schon abonniert?" ohne
-- die source_url zu parsen.
```
Die eigentliche Liste der 16 Bundesland-Feed-URLs liegt als PHP-Konstante/Array im Code (analog zu statischen Konfigurationen wie `DEFAULT_API_BASE_URL`), nicht in der Datenbank – sie ändert sich praktisch nie und braucht keine Admin-UI.

## API-Endpunkte
- `GET /api/calendar-sources/presets` — liefert die 16 Bundesland-Einträge `{ key, label, source_url }` für das Dropdown (statisch, kein DB-Zugriff).
- `POST /api/calendar-sources` (bestehender Endpunkt) — Request um optionales Feld `preset_key` erweitert; Controller setzt `source_url` dann serverseitig aus der Preset-Liste (NICHT aus Client-Eingabe, verhindert beliebige URLs über denselben Pfad, der sonst Freitext-URLs akzeptiert – allerdings akzeptiert der bestehende Endpunkt ohnehin schon beliebige `source_url`, das ist kein neues Risiko).

## UI/UX
Erweiterung von `public/calendar_sources.php`: zusätzlicher Button/Abschnitt "Schulferien abonnieren" neben dem bestehenden "Kalenderquelle hinzufügen"-Formular, mit einem `<select>` der 16 Bundesländer statt eines URL-Freitextfelds. Nach Auswahl identischer Ablauf wie bei jeder anderen `stream_ics`-Quelle (erscheint in der Liste, "Jetzt synchronisieren"-Button vorhanden, siehe `calendar_source_sync.php`).

## Abhängigkeiten & Integration
- Vollständig auf dem bestehenden Kalenderquellen-Mechanismus aufgebaut (`CalendarSourceController`, `CalendarSync`, `IcsParser`, `bin/sync-calendar-sources.php`) – **keine neue Synchronisationslogik nötig**, nur eine kuratierte URL-Liste und ein bequemeres UI davor.
- Spielt gut mit `kalender-kategorien.md` zusammen (Ferientermine als eigene Kategorie/Farbe statt Personenfarbe).

## Offene Fragen / Risiken
- Verlässlichkeit/Stabilität der genutzten externen ICS-Quelle (Drittanbieter-Feeds, z.B. schulferien.org oder offizielle Kultusministerien-Feeds, falls vorhanden) – Format-Änderungen oder Downtime außerhalb der eigenen Kontrolle. Sollte vor Umsetzung konkret geprüft werden, welche Quelle pro Bundesland tatsächlich einen stabilen, kostenlosen ICS-Feed anbietet.
- Rechtliche Nutzungsbedingungen der jeweiligen Feed-Anbieter prüfen (die meisten offiziellen Ferientermine sind aber gemeinfreie Verwaltungsdaten).
- Pflegeaufwand, falls sich Feed-URLs einzelner Bundesländer ändern (statische Liste muss gelegentlich aktualisiert werden – über ein reguläres Code-Update/Self-Update ausrollbar).

## Out of Scope (v1)
- Eigene, selbst gehostete Ferientermin-Datenbank statt externer Feeds.
- Feiertage (eigenes, ähnlich gelagertes Feature, siehe ggf. separate Idee).
- Automatische Bundesland-Erkennung aus dem Nutzerprofil (Auswahl bleibt manuell).
