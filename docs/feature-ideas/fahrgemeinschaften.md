# Fahrgemeinschaften-Koordination

## Problem / Motivation
Bei Terminen mit Kindern (Sport, Musikschule, Verabredungen) ist oft unklar, wer fährt und wer abgeholt wird — das wird heute meist mündlich oder in einem separaten Chat geklärt, nicht im Kalender selbst. Eine explizite "Fahrer:in"-Rolle am Termin macht diese Zuständigkeit sichtbar und planbar.

## Zielgruppe & Nutzen
Familien mit Kindern, die zu Terminen gebracht/abgeholt werden müssen, und (optional, als Erweiterung) Familien, die sich mit anderen Familien abwechseln. Nutzen: auf einen Blick sehen, wer heute fährt, statt das für jeden Termin neu zu klären.

## Funktionsumfang (v1)
- Beim Anlegen/Bearbeiten eines Termins einem oder mehreren Teilnehmern zusätzlich die Rolle "Fahrer:in" zuweisen (Hinfahrt und/oder Rückfahrt getrennt wählbar).
- In der Terminansicht erscheint klar: "Teilnehmer: Lina · Fahrer:in: Papa (Hin), Mama (Rück)".
- Fahrer:in muss NICHT zwingend auch Teilnehmer:in des Termins sein (z.B. Papa fährt, nimmt aber selbst nicht am Training teil).
- Kein Koordinieren mit anderen Familien/externen Personen in v1 — nur innerhalb der eigenen Familie.

## User Stories
- Als Elternteil möchte ich beim Anlegen eines Sporttermins festlegen, wer mein Kind hinbringt und wer es abholt.
- Als Familienmitglied möchte ich in der Kalenderansicht auf einen Blick sehen, wer heute wohin fährt.
- Als Elternteil möchte ich eine Erinnerung bekommen, wenn ich als Fahrer:in für einen Termin eingetragen bin (nutzt bestehenden Reminder-Mechanismus).

## Datenmodell
Die bestehende `event_participants`-Tabelle bekommt ein neues Feld statt einer komplett neuen Tabelle, da eine Fahrer:in-Zuordnung konzeptionell eine Teilnahme-Art ist:

```sql
ALTER TABLE event_participants
  ADD COLUMN transport_role ENUM('none','driver_there','driver_back','driver_both') NOT NULL DEFAULT 'none' AFTER family_member_id;
```

Dadurch bleibt die bestehende `UNIQUE KEY uniq_event_member (event_id, family_member_id)` gültig — eine Person kann pro Termin genau eine Transport-Rolle zusätzlich zu ihrer normalen Teilnahme haben. Falls ein:e Fahrer:in NICHT Teilnehmer:in sein soll (siehe oben), muss `event_participants` für diesen Fall trotzdem einen Eintrag bekommen (mit einer Markierung, dass es sich um reine Fahrer-Teilnahme handelt) — alternativ ein zusätzliches Boolean-Feld `is_participant TINYINT(1) NOT NULL DEFAULT 1`, damit "nur fährt, nimmt nicht teil" sauber abgebildet ist.

## API-Endpunkte
- `POST /api/events` / `PUT /api/events/{id}` (bestehend, `EventController`) — Request-Body um `participants` mit optionalem `transport_role`-Feld pro Teilnehmer erweitern, statt nur einer ID-Liste.
- `GET /api/events` (bestehend) — Response pro Termin um die Fahrer-Zuordnung ergänzen.

## UI/UX
- `event_form.php`: in der Teilnehmer-Auswahl (`participant-checkboxes`, siehe `styles.css`) zusätzlich ein Dropdown je ausgewähltem Teilnehmer für die Transport-Rolle.
- In `calendar.php` (Tages-/Monatsansicht) ein kleines Auto-Symbol am Termin-Chip, wenn eine Fahrer-Zuordnung existiert, mit Tooltip/Kurztext, wer fährt.

## Abhängigkeiten & Integration
- Reine Erweiterung von `EventController` und `event_participants` — kein neuer Controller nötig.
- Reminder-Feature (bestehend) kann unverändert genutzt werden: eine Fahrer:in kann sich wie jede:r Teilnehmer:in eine Erinnerung für den Termin setzen.

## Aufwandsschätzung
**S.** Reine Erweiterung eines bestehenden Datenmodells (ein neues Feld) und zweier bestehender Formulare — kein neuer Controller, keine neue Hauptseite.

## Offene Fragen / Risiken
- Soll "nur fährt, nimmt nicht teil" wirklich unterstützt werden, oder reicht v1 mit "Fahrer:in ist immer auch Teilnehmer:in" (einfacher, aber weniger flexibel)?
- Soll es eine Erinnerung speziell für Fahrer:innen geben ("Du fährst in 30 Minuten"), getrennt von der normalen Termin-Erinnerung?

## Out of Scope (v1)
- Koordination mit anderen Familien/externen Fahrer:innen.
- Automatische Vorschläge, wer "dran" ist (siehe dazu das separate Feature "rotierende-aufgaben.md", falls auf Fahrdienste übertragen).
- Routenplanung/Kartenintegration.
