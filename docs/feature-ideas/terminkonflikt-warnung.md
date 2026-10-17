# Terminkonflikt-Warnung

## Problem / Motivation
Beim Anlegen eines Termins prüft die App aktuell nicht, ob ein
ausgewählter Teilnehmer zur selben Zeit schon anderweitig verplant ist.
Überschneidungen fallen damit erst auf, wenn es zu spät ist (z.B. zwei
gleichzeitige Arzttermine für dasselbe Kind).

## Zielgruppe & Nutzen
Alle Familien, insbesondere mit mehreren Terminen pro Woche und mehreren
aktiven Mitgliedern (Sport, Schule, Arzttermine). Nutzen: Doppelbuchungen
werden sofort beim Anlegen sichtbar, statt erst beim Blick in den
Kalender oder gar nicht.

## Funktionsumfang (v1)
- Beim Speichern eines Termins (Anlegen/Bearbeiten) serverseitig prüfen,
  ob für einen der ausgewählten Teilnehmer (`event_participants`) im
  Zeitraum `starts_at`-`ends_at` bereits ein anderer Termin existiert.
- Bei Konflikt: Termin wird trotzdem gespeichert (kein hartes Verbot,
  reale Leben kennt Ausnahmen), aber eine Warnung mit den
  überschneidenden Terminen wird zurückgegeben/angezeigt.
- Konflikt-Prüfung berücksichtigt nur echte Zeit-Overlaps (nicht
  ganztägig gegen ganztägig als automatischen Konflikt, falls gewünscht
  konfigurierbar).

## User Stories
- Als Elternteil möchte ich beim Anlegen eines Termins für mein Kind
  gewarnt werden, wenn es zur selben Zeit schon einen anderen Termin hat.
- Als Nutzer möchte ich die Warnung sehen, aber den Termin trotzdem
  speichern können, falls die Überschneidung gewollt ist (z.B. "ganztägig
  krank" + ein kurzer Arzttermin).
- Als Nutzer möchte ich in der Warnung sehen, WELCHER Termin kollidiert
  (Titel, Zeit), nicht nur dass es einen Konflikt gibt.

## Datenmodell
Keine Schemaänderung nötig - die Prüfung ist eine reine Lese-Query gegen
die bestehenden Tabellen `events`/`event_participants`:

```sql
SELECT e.id, e.title, e.starts_at, e.ends_at
FROM events e
JOIN event_participants ep ON ep.event_id = e.id
WHERE ep.family_member_id IN (:participant_ids)
  AND e.id != :current_event_id  -- beim Bearbeiten sich selbst ausschließen
  AND e.starts_at < :ends_at
  AND e.ends_at > :starts_at
```

Der bestehende Index `idx_family_range (family_id, starts_at, ends_at)`
auf `events` deckt diese Abfrage bereits gut ab.

## API-Endpunkte
- `POST /api/events` und `PUT /api/events/{id}` (bestehende Endpunkte in
  `EventController`) liefern im Erfolgsfall zusätzlich ein optionales
  Feld `conflicts: [{event_id, title, starts_at, ends_at, member_name}]`
  in der Response, statt eines separaten Endpunkts - vermeidet einen
  zusätzlichen Roundtrip.
- Optional: `GET /api/events/check-conflicts?participant_ids=...&starts_at=...&ends_at=...`
  für eine Live-Prüfung VOR dem Speichern (z.B. während der Nutzer das
  Formular ausfüllt) - das würde aber client-seitiges JavaScript
  erfordern, was dem bisherigen "kein JS außer für Push"-Prinzip der App
  widerspricht (siehe Abhängigkeiten).

## UI/UX
- `event_form.php`: nach dem Absenden, falls Konflikte zurückkommen,
  wird die Formularseite erneut mit einer Warnbox (`error-box`-ähnlicher,
  aber gelber "warning-box"-Stil) über den kollidierenden Termin(en)
  angezeigt, mit einem zweiten Bestätigungs-Button "Trotzdem speichern".
- Erfordert einen zusätzlichen Zwischenschritt im Formular-Flow: der
  erste Submit prüft nur, der zweite (mit einem versteckten Feld
  `confirm_conflict=1`) speichert endgültig trotz Warnung.

## Abhängigkeiten & Integration
- `EventController::create()`/`update()` (bestehend) wird um die
  Konflikt-Prüfung erweitert.
- `event_form.php`/`event_save.php` (Client) brauchen den zusätzlichen
  Bestätigungsschritt.
- Da die App bewusst ohne clientseitiges JavaScript für normale
  Formular-Interaktionen auskommt (siehe README), ist eine Live-Prüfung
  während der Eingabe nicht ohne Weiteres möglich - die Prüfung passiert
  serverseitig beim Submit, mit Extra-Request-Zyklus für die Bestätigung.

## Aufwandsschätzung
**S** - Reine Lese-Query auf bestehende Tabellen plus ein zusätzlicher
Bestätigungsschritt im Formular. Kein neues Datenmodell, keine neue
Seite, kleinste der hier beschriebenen Ideen.

## Offene Fragen / Risiken
- Soll ganztägig-gegen-ganztägig automatisch als Konflikt zählen, oder
  nur bei überlappenden Uhrzeiten?
- Soll die Prüfung auch bei importierten/gestreamten Terminen
  (`source != 'manual'`) laufen, oder nur bei manuell angelegten?
- Ab wann wird die Warnung bei wiederkehrenden Terminen (RRule) zu
  aufdringlich (potenziell viele Instanzen, viele Warnungen)?

## Out of Scope (v1)
- Automatische Konfliktlösung/Terminvorschläge ("stattdessen 15 Uhr?").
- Konflikt-Prüfung gegen externe/importierte Kalenderquellen Dritter
  außerhalb der Familie.
- Harte Sperre (Speichern verhindern) statt Warnung.
