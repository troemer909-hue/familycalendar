# Zufällige Aufgaben-Zuteilung (Losliste)

## Problem / Motivation
In Familien mit mehreren Kindern gibt es ständig Streit darüber, wer welche Aufgabe macht ("ich war letztes Mal dran", "das ist nicht fair"). Eine feste, manuell gepflegte Zuweisung wird schnell zur Diskussionsgrundlage selbst. Löst stattdessen eine Automatik per Los aus, wer eine Aufgabe übernimmt, entfällt die Diskussion - die App entscheidet, nicht die Eltern.

Unterscheidet sich von der bereits dokumentierten `rotierende-aufgaben.md`: dort rotiert eine **einzelne** Aufgabe in **fester Reihenfolge** durch eine Personenliste. Hier geht es um eine ganze **Aufgabenliste mit mehreren, unterschiedlichen Aufgaben** (z.B. "Spülmaschine ausräumen", "Mülltonne rausstellen", "Tisch decken"), bei der **jede Aufgabe unabhängig per Zufall** (nicht in fester Reihenfolge) neu verlost wird. Beide Konzepte könnten später dieselbe UI-Sektion teilen, sind aber unterschiedliche Mechanismen und hier bewusst getrennt geplant.

## Zielgruppe & Nutzen
Familien mit mehreren Kindern/mehreren Erwachsenen, die Haushaltsaufgaben fair und ohne wiederkehrende Diskussion verteilen wollen. Eine bestehende Aufgabenliste (siehe `todo-aufgabenliste.md`, bereits mit Vorlagen-Aufgaben befüllbar) bekommt eine "Los-Automatik": Pool an Personen + Rhythmus auswählen, danach läuft die Zuteilung von selbst.

## User Stories
- Als Elternteil möchte ich eine Aufgabenliste mit festen Aufgaben anlegen und einen Kreis von Personen festlegen, aus dem jede Aufgabe automatisch im gewählten Rhythmus neu verlost wird.
- Als Kind möchte ich sehen, wer gerade für welche Aufgabe eingeteilt ist, ohne dass das ein Elternteil manuell einträgt.
- Als Elternteil möchte ich wählen können, ob es reiner Zufall ist (auch mehrfach hintereinander dieselbe Person möglich) oder ob die Automatik fair rotiert (wer dran war, ist beim nächsten Mal für dieselbe Aufgabe nicht sofort wieder dran).
- Als Elternteil möchte ich eine einzelne Aufgabe aus der Automatik herausnehmen können (z.B. weil sie dauerhaft bei einer bestimmten Person bleiben soll), ohne die ganze Losliste zu deaktivieren.
- Als Elternteil möchte ich pro Person eine Obergrenze festlegen, wie viele Aufgaben sie pro Zeitraum maximal bekommen soll - ist die Summe der Obergrenzen ausgeschöpft, bleiben übrige Aufgaben unzugeteilt und damit unerledigt, statt zwangsweise doch noch jemandem zugewiesen zu werden. Funktioniert dadurch auch mit nur einer einzigen Person im Pool (z.B. "maximal 3 von 5 Aufgaben pro Tag").

## Funktionsumfang (v1)
- Eine bestehende Aufgabenliste (`task_lists`) bekommt optional einen **Zuteilungs-Pool**: frei auswählbare Teilmenge der Familienmitglieder + Rhythmus (täglich/wöchentlich/monatlich) + Modus (**Zufall** oder **Fair**, siehe unten).
- Beim Erreichen des nächsten Intervalls (Cron-Job) wird für **jede Aufgabe dieser Liste unabhängig** ein neues Mitglied aus dem Pool gelost und `is_done` auf "offen" zurückgesetzt (neue Periode = frische Erledigung der Aufgabe).
- **Zufallsmodus**: rein zufällige Ziehung aus dem Pool, auch mehrfach hintereinander dieselbe Person möglich.
- **Fairnessmodus**: die Person, die eine Aufgabe zuletzt hatte, wird bei der nächsten Ziehung für genau diese Aufgabe ausgeschlossen (solange der Pool mindestens 2 Mitglieder hat) - verhindert "schon wieder ich".
- Einzelne Aufgaben können von der Automatik ausgenommen werden (`auto_assign = 0`), bleiben dann bei ihrer manuell gesetzten Zuweisung.
- **Maximalzahl pro Person** (optional, pro Pool-Mitglied einzeln einstellbar): begrenzt, wie viele Aufgaben eine Person pro Ziehung maximal bekommt. Ist die Kapazität aller Pool-Mitglieder zusammen ausgeschöpft, bleiben die restlichen Aufgaben dieser Ziehung **ohne Zuweisung** (`assigned_member_id = NULL`) und damit unerledigt - keine Person wird über ihre Obergrenze hinaus eingeteilt. Ohne gesetzte Obergrenze (Standard) ist eine Person unbegrenzt einteilbar, wie bisher geplant.
- Push-Benachrichtigung an die neu eingeteilte Person bei jeder Änderung (keine Benachrichtigung, wenn dieselbe Person erneut dran ist, z.B. Pool-Größe 1, oder wenn eine Aufgabe mangels Kapazität unzugeteilt bleibt).

## Datenmodell
```sql
CREATE TABLE IF NOT EXISTS task_assignment_pools (
  id INT PRIMARY KEY AUTO_INCREMENT,
  task_list_id INT NOT NULL,
  rhythm ENUM('daily','weekly','monthly') NOT NULL,
  mode ENUM('random','fair') NOT NULL DEFAULT 'fair',
  next_draw_at DATE NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_list_id) REFERENCES task_lists(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_list_pool (task_list_id)  -- v1: genau ein Pool pro Liste
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_assignment_pool_members (
  id INT PRIMARY KEY AUTO_INCREMENT,
  pool_id INT NOT NULL,
  family_member_id INT NOT NULL,
  max_tasks INT NULL,                       -- NULL = unbegrenzt
  FOREIGN KEY (pool_id) REFERENCES task_assignment_pools(id) ON DELETE CASCADE,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_pool_member (pool_id, family_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
`tasks` (aus `todo-aufgabenliste.md`) bekommt ein zusätzliches `auto_assign TINYINT(1) NOT NULL DEFAULT 1` - nur relevant, wenn die zugehörige Liste einen aktiven Pool hat. Die aktuelle Zuweisung bleibt das bereits vorhandene `tasks.assigned_member_id`; für den Fairnessmodus reicht dessen aktueller Wert als "zuletzt dran" - es braucht keine zusätzliche Historien-Spalte, nur eine `task_assignment_history`-Tabelle, falls später eine Verlaufsansicht gewünscht ist (siehe Out of Scope).

## API-Endpunkte
- `PUT /api/task-lists/{id}/assignment-pool` — Pool anlegen/ändern (`members[]` als `{family_member_id, max_tasks?}`, `rhythm`, `mode`, `active`). Analog zum bereits bestehenden `PUT /api/calendar-sources/{id}` Muster für nachträglich änderbare Einstellungen.
- `DELETE /api/task-lists/{id}/assignment-pool` — Automatik deaktivieren/entfernen, bestehende Zuweisungen bleiben als letzter Stand erhalten.
- `PATCH /api/tasks/{id}` (bestehender Endpunkt) um `auto_assign` erweitern.
- Neuer Cron-Job `bin/draw-task-assignments.php` (Vorbild: `bin/sync-calendar-sources.php`): für jeden aktiven Pool mit `next_draw_at <= heute` werden die Aufgaben mit `auto_assign = 1` in zufälliger Reihenfolge einzeln verlost. Pro Aufgabe wird aus den Pool-Mitgliedern gezogen, die (a) im Fairnessmodus nicht die letzte Person dieser Aufgabe waren und (b) ihre `max_tasks`-Obergrenze in dieser Ziehung noch nicht erreicht haben (ein Zähler pro Mitglied wird während der Ziehung mitgeführt). Bleibt kein zulässiges Mitglied übrig, bekommt die Aufgabe `assigned_member_id = NULL` (unzugeteilt, siehe Maximalzahl-Funktion oben). Nach jeder Zuweisung: `is_done` zurückgesetzt, `next_draw_at` um den Rhythmus erhöht, Push an die neu eingeteilte Person (siehe `PushSender::sendToUser()`, bereits für Listen-Zuweisung aus PR #46 vorhanden) - keine Push bei unzugeteilten Aufgaben.

## UI/UX
Erweiterung von `task_lists.php` (seit PR #51 zeigt jede Liste ihre Aufgaben direkt eingeklappt auf einer Seite) um eine weitere aufklappbare Sektion pro Liste: "Zufalls-Zuteilung einrichten" - Mitglieder-Checkboxen (je ausgewähltem Mitglied ein optionales Zahlenfeld "Maximal X Aufgaben pro Zeitraum, leer = unbegrenzt"), Rhythmus-Dropdown, Modus-Auswahl (Zufall/Fair), Aktiv-Schalter. Jede automatisch zugeteilte Aufgabe bekommt ein Badge "🎲 verlost" mit Tooltip, wann die nächste Neuziehung ansteht; eine Aufgabe ohne Zuweisung mangels Kapazität bekommt stattdessen "🎲 nicht zugeteilt (Kapazität voll)". Ein Klick/Checkbox erlaubt das Herausnehmen einer Aufgabe aus der Automatik (`auto_assign = 0`).

## Abhängigkeiten & Integration
Setzt `todo-aufgabenliste.md` voraus (gleiche `tasks`/`task_lists`-Tabellen) sowie die bestehende Push-Infrastruktur aus PR #46. Bewusst **kein** Aufbau auf der `recurrence_rule`-Materialisierung (RRule/`recurrence_parent_id`): die Aufgabe selbst bleibt als eine Zeile bestehen, nur Zuweisung und Erledigt-Status wechseln pro Periode - vermeidet eine wachsende Zahl neuer Task-Zeilen pro Zyklus, die inhaltlich dieselbe Aufgabe sind.

## Aufwandsschätzung
**M-L.** Zwei neue Tabellen, ein neuer Cron-Job, UI-Erweiterung in `task_lists.php`, Push-Anbindung (bereits vorhandene Infrastruktur wiederverwendbar). Kleiner als `rotierende-aufgaben.md`, da kein Reihenfolge-Verwaltungs-UI (Drag&Drop) nötig ist - nur eine Mitglieder-Auswahl.

## Offene Fragen / Risiken
- Was passiert, wenn ein Mitglied aus dem Pool entfernt wird, während es gerade einer Aufgabe zugewiesen ist? Vorschlag: Zuweisung bleibt bis zur nächsten Ziehung bestehen, dann automatisch aus dem verkleinerten Pool neu gelost.
- Pool-Größe 1: Fairnessmodus kann dann nicht ausschließen - die eine Person bleibt immer dran (erwartetes, dokumentiertes Verhalten, keine Fehlermeldung nötig).
- Family-Mitglied ohne eigenes Login (reines Profil) im Pool: kann gelost werden, bekommt aber keine Push-Benachrichtigung (gleiche Einschränkung wie bei Erinnerungen/Listen-Zuweisung - kein Postfach/Gerät).
- Soll eine nicht erledigte Aufgabe die nächste Ziehung verzögern/blockieren, oder zieht die Automatik unabhängig vom Erledigt-Status stur im festen Rhythmus weiter? v1-Annahme: stur im Rhythmus weiter, unabhängig davon ob die vorherige Periode erledigt wurde (einfacher, vorhersehbarer; eine liegengebliebene Aufgabe "verschwindet" dann aber mit dem nächsten Los an eine andere Person - ggf. in v1 bewusst in Kauf genommen und in der UI sichtbar machen).
- Reihenfolge, in der Aufgaben bei knapper Gesamtkapazität verlost werden: v1-Annahme ist eine zufällige Reihenfolge pro Ziehung, d.h. auch WELCHE Aufgaben unzugeteilt bleiben, wechselt von Ziehung zu Ziehung - passt zum "Los"-Charakter der Funktion, könnte aber dazu führen, dass eine wichtige Aufgabe mal unzugeteilt bleibt. Alternative (Out of Scope v1): Aufgaben-Priorität/Reihenfolge manuell festlegbar, sodass wichtige Aufgaben zuerst verlost werden.
- Summe aller `max_tasks` kleiner als die Anzahl automatisch zugeteilter Aufgaben der Liste ist ein gültiger, gewollter Zustand (genau der vom Nutzer gewünschte Effekt) - keine Fehlermeldung, nur sichtbare "nicht zugeteilt"-Markierung.

## Out of Scope (v1)
Verlaufsansicht ("wer hatte diese Aufgabe wann"), ungleich gewichtete Ziehung (manche Personen öfter als andere), manuelles Tauschen zwischen zwei Personen, mehrere unabhängige Pools pro Liste, Berücksichtigung von Abwesenheiten/Urlaub beim Losen (siehe `abwesenheiten-urlaub.md`, falls das umgesetzt wird - dort ausgeschlossene Mitglieder könnten künftig automatisch aus dem Pool herausfallen), manuell festlegbare Aufgaben-Priorität/Reihenfolge für die Ziehung bei knapper Kapazität.
