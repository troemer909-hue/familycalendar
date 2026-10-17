# Abwesenheiten/Urlaubsblöcke

## Problem / Motivation
Familienmitglieder sind oft für mehrere Tage am Stück nicht verfügbar (Dienstreise, Urlaub, Kuraufenthalt, Kinder bei den Großeltern). Das als normalen Termin abzubilden funktioniert technisch (ein mehrtägiger `all_day`-Termin existiert bereits), aber es fehlt eine klare visuelle/semantische Unterscheidung zu normalen Terminen und die Möglichkeit, bei der Terminplanung gezielt auf Abwesenheiten zu prüfen (verwandt mit `terminkonflikt-warnung.md`).

## Zielgruppe & Nutzen
Alle Familienmitglieder. Nutzen: auf einen Blick sehen, wer wann nicht verfügbar ist, ohne Abwesenheiten mit echten Terminen zu verwechseln; Grundlage für eine spätere Konflikterkennung ("Du planst einen Familienausflug, aber Papa ist im Urlaub auf Dienstreise").

## Funktionsumfang (v1)
- Eine Abwesenheit ist ein mehrtägiger, ganztägiger Eintrag mit genau einem betroffenen Familienmitglied, optionalem Grund/Label ("Urlaub", "Dienstreise", "Kur" – Freitext oder kleine Auswahl).
- Eigene, klar abgesetzte Darstellung im Kalenderraster (z.B. durchgehender Balken am Tagesrand statt normaler Termin-Chip).
- Anlegen/Bearbeiten/Löschen nur für die eigene Abwesenheit oder durch den Familienadmin.
- Abwesenheiten sind rein informativ (v1): kein automatisches Blockieren neuer Termine, nur sichtbare Kennzeichnung.

## User Stories
- Als Familienmitglied möchte ich eintragen, dass ich vom 10. bis 17. August im Urlaub bin, damit die anderen das sofort sehen.
- Als Elternteil möchte ich auf einen Blick erkennen, wer diese Woche nicht zuhause ist, ohne jeden Termin einzeln zu öffnen.
- Als Nutzer möchte ich Abwesenheiten optisch von normalen Terminen unterscheiden können.

## Datenmodell
Eigene Tabelle statt Zweckentfremdung von `events`, da Semantik und Abfragen (z.B. "ist Person X an Datum Y abwesend?") sich klar von normalen Terminen unterscheiden:
```sql
CREATE TABLE IF NOT EXISTS absences (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  family_member_id INT NOT NULL,
  label VARCHAR(100) NULL,            -- z.B. "Urlaub", "Dienstreise"; NULL = keine Angabe
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  INDEX idx_family_range (family_id, starts_on, ends_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
Bewusst `DATE` statt `DATETIME` (immer ganztägig, kein Uhrzeit-Konzept nötig), analog zu `all_day`-Terminen aber ohne den Rest des `events`-Gepäcks (scope, recurrence_rule, source, etc., die hier nicht sinnvoll sind).

## API-Endpunkte
- `GET /api/absences?from=YYYY-MM-DD&to=YYYY-MM-DD` — alle Abwesenheiten der Familie im Zeitraum (analog zu `GET /api/events`).
- `POST /api/absences` — Body `{ family_member_id, label?, starts_on, ends_on }`.
- `DELETE /api/absences/{id}` — nur eigene Abwesenheit oder `requireAdmin()`.

## UI/UX
`calendar.php` lädt zusätzlich zu `/api/events` auch `/api/absences` für den sichtbaren Zeitraum und rendert sie als schmalen farbigen Balken über dem jeweiligen Tag (ähnlich einer "Out of Office"-Markierung in gängigen Kalender-Apps), in der Personenfarbe des betroffenen Mitglieds. Ein neues schlankes Formular `absence_form.php` (analog zu `event_form.php`, aber ohne Uhrzeit-/Teilnehmer-/Wiederholungsfelder) zum Anlegen. Eintragspunkt: kleiner "+ Abwesenheit" Link in der Sidebar neben den Mitglieder-Chips oder im Toolbar-Bereich.

## Abhängigkeiten & Integration
- Eigenständig, keine Abhängigkeit zu anderen Features – ist aber die natürliche Grundlage für eine spätere erweiterte `terminkonflikt-warnung.md` ("Teilnehmer ist in diesem Zeitraum als abwesend markiert").

## Aufwandsschätzung
**M.** Neue Tabelle + CRUD-Endpunkte sind einfach (vergleichbar mit `calendar_sources`-Grundgerüst), der nennenswerte Aufwand liegt in der zusätzlichen Kalenderraster-Darstellung (zweite Datenquelle neben `events` im selben Grid rendern, ohne das bestehende Layout von `calendar.php` zu verkomplizieren).

## Offene Fragen / Risiken
- Sollen Abwesenheiten in Wochen-/Tagesansicht genauso dargestellt werden wie in der Monatsansicht, oder dort eher als Banner?
- Label als Freitext oder feste Auswahl (Urlaub/Dienstreise/Kur/Sonstiges) – Freitext ist flexibler, feste Auswahl ermöglicht später Icons/Filter.
- Sollen Abwesenheiten auch im ICS-Export (`ics_export.php`) auftauchen?

## Out of Scope (v1)
- Automatisches Blockieren/Warnen bei Terminkonflikten mit Abwesenheiten (eigenes Folge-Feature).
- Genehmigungs-Workflow (z.B. Admin muss Abwesenheit bestätigen).
- Wiederkehrende Abwesenheiten (z.B. "jeden zweiten Freitag im Homeoffice").
