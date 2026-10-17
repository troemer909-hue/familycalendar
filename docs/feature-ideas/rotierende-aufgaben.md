# Rotierende Aufgaben

## Problem / Motivation
Viele Haushaltsaufgaben wiederholen sich regelmäßig, aber die Zuständigkeit soll zwischen Familienmitgliedern wechseln ("diese Woche Anna, nächste Woche Max beim Müll rausbringen"). Ohne Automatisierung muss das jede Woche manuell neu zugewiesen werden — genau die Art von Reibung, die eine Familien-App eigentlich abnehmen soll.

## Zielgruppe & Nutzen
Familien mit mehreren Erwachsenen/älteren Kindern, die Haushaltspflichten fair aufteilen wollen. Nutzen: eine Aufgabe einmal mit einer Rotation einrichten, die App weist automatisch im eingestellten Rhythmus neu zu.

## Funktionsumfang (v1)
- Aufgabe (siehe `todo-aufgabenliste.md`) mit Rotationsregel: Liste von Familienmitgliedern in fester Reihenfolge + Rhythmus (täglich/wöchentlich/monatlich).
- Beim Erreichen des nächsten Intervalls wird automatisch eine neue Aufgaben-Instanz mit dem nächsten Mitglied in der Rotation erzeugt (analog zur Materialisierung wiederkehrender Termine über `recurrence_rule`/`recurrence_parent_id` bei `events`).
- Rotation pausieren/beenden, Reihenfolge nachträglich ändern.
- Verlaufsansicht: wer hatte die Aufgabe wann.

## User Stories
- Als Elternteil möchte ich eine Aufgabe mit einer Liste von Personen anlegen, damit die Zuständigkeit automatisch rotiert.
- Als Familienmitglied möchte ich sehen, wann ich das nächste Mal dran bin, damit ich mich darauf einstellen kann.
- Als Elternteil möchte ich die Rotation pausieren können (z.B. während des Urlaubs eines Mitglieds), ohne die ganze Aufgabe zu löschen.

## Datenmodell
Erweiterung von `tasks` (aus `todo-aufgabenliste.md`) um eine Rotationsregel, materialisiert analog zum bestehenden `events.recurrence_parent_id`-Muster:

```sql
CREATE TABLE IF NOT EXISTS task_rotations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  task_template_id INT NOT NULL,            -- tasks.id des "Master"-Eintrags (Titel/Beschreibung)
  interval_unit ENUM('daily','weekly','monthly') NOT NULL,
  interval_count INT NOT NULL DEFAULT 1,
  next_run_at DATE NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_template_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_rotation_members (
  id INT PRIMARY KEY AUTO_INCREMENT,
  rotation_id INT NOT NULL,
  family_member_id INT NOT NULL,
  position INT NOT NULL,                    -- Reihenfolge in der Rotation
  FOREIGN KEY (rotation_id) REFERENCES task_rotations(id) ON DELETE CASCADE,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_rotation_position (rotation_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
`tasks` bekommt zusätzlich ein nullable `rotation_id INT NULL` (Fremdschlüssel auf `task_rotations`), um materialisierte Instanzen ihrer Rotation zuzuordnen — exakt das gleiche Prinzip wie `recurrence_parent_id` bei `events`.

## API-Endpunkte
- `POST /api/tasks/{id}/rotation` — Rotation für eine bestehende Aufgabe einrichten (`members[]` in Reihenfolge, `interval_unit`, `interval_count`).
- `PATCH /api/task-rotations/{id}` — Reihenfolge/Rhythmus ändern, pausieren (`active=0`).
- `GET /api/task-rotations/{id}` — aktuelle Rotation + Verlauf (welche `tasks`-Instanzen daraus entstanden sind).
- Hintergrundjob (siehe `bin/send-reminders.php` als Vorbild für einen Cron-Skript-Ansatz): `bin/advance-task-rotations.php`, erzeugt fällige neue `tasks`-Instanzen und erhöht `next_run_at`.

## UI/UX
Erweiterung des Aufgaben-Formulars (`task_form.php` aus `todo-aufgabenliste.md`) um einen optionalen "Rotation einrichten"-Abschnitt (Mitglieder per Drag&Drop oder Dropdown-Liste sortieren, Rhythmus wählen). In der Aufgabenliste ein Hinweis-Badge "rotiert" mit Tooltip "nächste Person: X ab dem Y".

## Abhängigkeiten & Integration
Setzt `todo-aufgabenliste.md` voraus (gleiche `tasks`-Tabelle). Technisch eng verwandt mit der bestehenden `recurrence_rule`/RRule-Logik bei Terminen (`src/Core/RRule.php`), nutzt aber ein einfacheres festes Intervall statt vollständiger RRULE-Syntax, da eine Personen-Rotation andere Anforderungen hat als Zeit-Wiederholung.

## Aufwandsschätzung
**L.** Zwei neue Tabellen, ein neuer Cron-Job, UI für Reihenfolge-Verwaltung, plus Sonderfälle (Mitglied aus Rotation entfernt, während es "dran" ist; Rotation während aktiver Aufgabe ändern).

## Offene Fragen / Risiken
- Was passiert, wenn ein rotierendes Mitglied aus der Familie entfernt wird (`member_downgrade.php`)? Rotation muss automatisch bereinigt werden.
- Soll eine verpasste/nicht erledigte Aufgabe die Rotation blockieren oder trotzdem weiterlaufen?
- Cron-Infrastruktur: `bin/send-reminders.php` setzt bereits einen externen Cron-Aufruf voraus (siehe README) — dieselbe Konvention gilt hier, erhöht aber die Hürde für Self-Hosting-Betreiber, die noch keinen Cron eingerichtet haben.

## Out of Scope (v1)
Ungleich gewichtete Rotation (manche Mitglieder öfter als andere), automatisches Tauschen zwischen zwei Personen, Benachrichtigung bei Rotationswechsel (kann über bestehende Reminder-Infrastruktur nachgezogen werden).
