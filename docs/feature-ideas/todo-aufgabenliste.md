# To-Do-/Aufgabenliste

## Problem / Motivation
Der Familienkalender verwaltet bisher ausschließlich zeitgebundene Termine. Viele Familienaufgaben sind aber nicht an eine feste Uhrzeit gebunden ("Rasen mähen diese Woche", "Steuererklärung vorbereiten") und landen deshalb in WhatsApp-Gruppen, auf Zetteln oder gar nicht. Eine einfache, gemeinsame Aufgabenliste schließt diese Lücke, ohne das bestehende Terminmodell zu verbiegen.

## Zielgruppe & Nutzen
Alle Familienmitglieder mit Login (Erwachsene, ältere Kinder). Nutzen: ein zentraler Ort für "was muss diese Woche erledigt werden", mit klarer Zuständigkeit statt stillschweigender Annahme ("wird schon jemand machen").

## Funktionsumfang (v1)
- Aufgabe anlegen: Titel, optionale Beschreibung, optionales Fälligkeitsdatum, optionale Zuweisung an ein Familienmitglied (oder "offen"/niemand).
- Aufgabenliste je Familie, Filter nach "offen" / "erledigt" / "mir zugewiesen".
- Als erledigt markieren (Checkbox), inkl. wer und wann erledigt hat.
- Aufgabe bearbeiten/löschen (jeder Login-Nutzer, kein Admin-Zwang — wie bei Terminen).
- Keine Uhrzeit, keine Wiederholung in v1 (siehe `rotierende-aufgaben.md` für die Erweiterung).

## User Stories
- Als Elternteil möchte ich eine Aufgabe anlegen und einem Familienmitglied zuweisen, damit klar ist, wer zuständig ist.
- Als Familienmitglied möchte ich meine eigenen offenen Aufgaben auf einen Blick sehen, damit ich nichts vergesse.
- Als Elternteil möchte ich erledigte Aufgaben abhaken sehen, damit ich nicht nachfragen muss.
- Als Nutzer möchte ich eine Aufgabe ohne feste Zuweisung anlegen können, damit sich jeder freiwillig melden kann.

## Datenmodell
Neue Tabelle, analog zum Stil von `events`/`event_participants`:

```sql
CREATE TABLE IF NOT EXISTS tasks (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  created_by INT NULL,                      -- family_members.id
  assigned_to INT NULL,                     -- family_members.id, NULL = offen/niemand
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  due_date DATE NULL,
  status ENUM('open','done') NOT NULL DEFAULT 'open',
  completed_by INT NULL,                    -- family_members.id
  completed_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (completed_by) REFERENCES family_members(id) ON DELETE SET NULL,
  INDEX idx_family_status (family_id, status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## API-Endpunkte
- `GET /api/tasks?status=open|done&assigned_to=me` — Liste der Aufgaben der eigenen Familie, mit Filtern.
- `POST /api/tasks` — Aufgabe anlegen (`title`, optional `description`, `due_date`, `assigned_to`).
- `PATCH /api/tasks/{id}` — Felder ändern (Titel, Zuweisung, Fälligkeit).
- `POST /api/tasks/{id}/complete` — als erledigt markieren (setzt `completed_by`/`completed_at`).
- `POST /api/tasks/{id}/reopen` — erledigt rückgängig machen.
- `DELETE /api/tasks/{id}` — löschen.

## UI/UX
Neue Seiten `tasks.php` (Liste + Formular, analog `members.php`) und `task_form.php` (analog `event_form.php`). Neuer Sidebar-Punkt "Aufgaben" in der Gruppe "Kalender" (`public/calendar.php`), mit Badge für die Anzahl offener, mir zugewiesener Aufgaben. Auf der Kalenderseite optional ein kompaktes "Offene Aufgaben"-Widget unterhalb der Mitgliederliste.

## Abhängigkeiten & Integration
Nutzt `family_members` für Zuweisung (gleiches Muster wie `events.created_by`). Keine Abhängigkeit zu bestehenden Controllern, aber `TenantContext::familyId()` wird wie überall zur Mandantentrennung gebraucht. Grundlage für `rotierende-aufgaben.md`.

## Aufwandsschätzung
**M.** Vergleichbar mit dem bestehenden Event-CRUD, aber ohne die Komplexität von Wiederholungsregeln/Teilnehmerlisten — eine Tabelle, fünf einfache Endpunkte, zwei neue Client-Seiten.

## Offene Fragen / Risiken
- Sollen Aufgaben wie Termine eine `scope`-Unterscheidung (persönlich/familienweit) bekommen, oder reicht `assigned_to`?
- Braucht es Benachrichtigungen bei Fälligkeit (Kopplung an `reminders`/Push), oder ist das v2?

## Out of Scope (v1)
Wiederkehrende/rotierende Aufgaben, Unteraufgaben/Checklisten innerhalb einer Aufgabe, Priorisierung/Labels, Erinnerungen per Push/Mail.
