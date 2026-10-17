# Statistik/Wochenrückblick

## Problem / Motivation
Familien sehen aktuell nur die rohe Terminliste (Monat/Woche/Tag), aber keine verdichtete Übersicht wie "Diese Woche: 7 Termine, 2 Geburtstage, 3 Termine mit Lina". Eine kurze, lesbare Zusammenfassung schafft einen schnellen Überblick, ohne jeden einzelnen Termin durchgehen zu müssen — besonders praktisch als Einstiegsseite oder E-Mail/Push-Zusammenfassung am Wochenanfang.

## Zielgruppe & Nutzen
Alle Familien, insbesondere Eltern, die sich am Sonntagabend oder Montagmorgen einen schnellen Überblick über die kommende Woche verschaffen wollen, statt durch den vollen Kalender zu scrollen. Nutzen: Zeitersparnis, bessere Vorausplanung.

## Funktionsumfang (v1)
- Eine neue Übersichtsseite "Wochenrückblick"/"Wochenvorschau" mit einfachen Kennzahlen zur aktuellen bzw. kommenden Kalenderwoche: Gesamtzahl Termine, Anzahl Geburtstage/Jahrestage (siehe `geburtstage-jahrestage.md`, falls vorhanden), Anzahl Termine je Familienmitglied, verglichen mit der Vorwoche (z.B. "3 mehr als letzte Woche").
- Reine Auswertung bestehender Daten (`events`, `event_participants`, `family_members`) — **keine neuen Rohdaten-Tabellen nötig.**
- Navigierbar wie die bestehende Kalenderansicht (Wochenweise vor/zurück), Standardansicht: aktuelle Woche.

## User Stories
- Als Elternteil möchte ich am Wochenanfang auf einen Blick sehen, wie viele Termine diese Woche anstehen, ohne den ganzen Kalender durchzugehen.
- Als Familienmitglied möchte ich sehen, wessen Woche besonders voll ist, um ggf. zu entlasten oder zu unterstützen.
- Als Nutzer:in möchte ich sehen, ob diese Woche mehr oder weniger los ist als letzte Woche.

## Datenmodell
Keine Schemaänderung notwendig. Die Statistik wird zur Laufzeit aus bestehenden Tabellen aggregiert, z.B.:

```sql
-- Anzahl Termine je Familienmitglied in einer Kalenderwoche:
SELECT fm.id, fm.name, COUNT(DISTINCT e.id) AS event_count
FROM family_members fm
LEFT JOIN event_participants ep ON ep.family_member_id = fm.id
LEFT JOIN events e ON e.id = ep.event_id
  AND e.start_at >= :week_start AND e.start_at < :week_end
WHERE fm.family_id = :family_id
GROUP BY fm.id, fm.name;

-- Anzahl Geburtstage/Jahrestage in der Woche (Spalte category bzw. ein dediziertes
-- Feld, je nachdem wie geburtstage-jahrestage.md das Feature letztlich modelliert):
SELECT COUNT(*) FROM events
WHERE family_id = :family_id
  AND category = 'birthday'
  AND start_at >= :week_start AND start_at < :week_end;
```

Da es sich um reine Lesezugriffe auf bereits bestehende, indizierte Spalten handelt (`events.start_at`, `events.family_id` sind schon durch bestehende Abfragen der Kalenderansicht genutzt), ist keine zusätzliche Indexierung zu erwarten — sollte sich bei spürbarer Last doch eine brauchen, würde sie im Rahmen der Umsetzung ergänzt.

## API-Endpunkte
- `GET /api/stats/week?week_start=YYYY-MM-DD` — liefert die aggregierten Kennzahlen für eine Kalenderwoche (Gesamtzahl Termine, Aufschlüsselung je Mitglied, Anzahl Geburtstage, Vergleich zur Vorwoche). Rein lesend, kein Schreibzugriff, keine neue Berechtigungsebene (nutzt dieselbe `TenantContext`-Prüfung wie alle anderen Endpunkte).

## UI/UX
- Neue Seite `week_summary.php` (oder als zusätzlicher Reiter innerhalb von `calendar.php`), erreichbar über einen neuen Sidebar-Menüpunkt "Wochenrückblick".
- Darstellung als kurze, textuelle Kennzahlen-Liste (keine Diagramme/Charts in v1, um konsistent mit dem schlichten, JS-armen Stil der App zu bleiben): z.B. "7 Termine diese Woche (+3 ggü. letzter Woche) · 2 Geburtstage · Lina: 4 Termine, Papa: 3 Termine".
- Wochen-Navigation analog zur bestehenden `.month-nav`/`view-switch`-Optik in `calendar.php` (gleiche CSS-Klassen wiederverwendbar).

## Abhängigkeiten & Integration
- Rein additiv: neuer `StatsController` (oder Methode in einem bestehenden Controller) mit ausschließlich lesenden Aggregationsabfragen — berührt keine bestehende Schreiblogik.
- Profitiert von, ist aber nicht hart abhängig von `geburtstage-jahrestage.md` — falls dieses Feature noch nicht existiert, fällt die Geburtstags-Kennzahl in v1 einfach weg bzw. zählt 0.
- Könnte später (Out of Scope v1, siehe unten) mit dem bestehenden E-Mail/Push-Mechanismus (`Mailer`/`PushSender`) kombiniert werden, um die Zusammenfassung aktiv zuzustellen statt sie nur auf Abruf anzuzeigen.

## Aufwandsschätzung
**S.** Reine Lesezugriffe auf bestehende Tabellen, keine Schemaänderung, keine neuen Schreibpfade — der Aufwand liegt fast ausschließlich in den Aggregationsabfragen und einer neuen, einfachen Anzeigeseite.

## Offene Fragen / Risiken
- Soll der Wochenrückblick die laufende Woche (inkl. bereits vergangener Tage) oder die kommende Woche zeigen? Vermutlich beides per Navigation, mit "aktuelle Woche" als Default.
- Reicht ein Vergleich nur zur Vorwoche, oder wäre ein Jahresvergleich ("verglichen mit derselben Woche letztes Jahr") für manche Familien interessant? Für v1 bewusst einfach halten.
- Soll diese Ansicht die Startseite nach dem Login werden, oder bleibt `calendar.php` das Standard-Landing?

## Out of Scope (v1)
- Grafische Diagramme/Charts (Balken-/Liniendiagramme).
- Aktive Zustellung der Zusammenfassung per E-Mail oder Push (z.B. "jeden Sonntag um 18 Uhr") — in v1 nur auf Abruf in der App sichtbar.
- Monats- oder Jahresstatistiken (nur Wochenebene in v1).
- Vergleich mit anderen Familien/Benchmarks.
