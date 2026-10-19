# Konzept: Aufgabenlisten für den Family-Kalender

## Zielbild

Gemeinsame, geteilte Aufgaben-/Einkaufslisten für die Familie (z.B. "Einkaufsliste", "Haushalt", "Packliste Urlaub") — getrennt vom Kalender (keine festen Uhrzeiten nötig), aber mit denselben Prinzipien wie Termine: gemeinsamer Familien-Workspace, Zuweisung an einzelne Mitglieder, Echtzeitsync beim nächsten Seitenaufruf.

Orientierung an etablierten Familien-Apps (Cozi, FamilyWall, OurHome): zentrale Stärken dort sind geteilte Listen mit Personenzuweisung, Trennung Einkauf/Haushalt und (bei OurHome) ein Belohnungssystem für Kinder bei erledigten Aufgaben — letzteres als mögliche spätere Ausbaustufe, nicht für den ersten Wurf.

## Datenmodell

Neue Tabellen, analog zum bestehenden Mandantenmodell (`family_id` wie bei `events`):

```sql
-- database/migrations/014_task_lists.sql
CREATE TABLE task_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    family_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
);

CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_list_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    assigned_member_id INT NULL,
    due_date DATE NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    done_at TIMESTAMP NULL,
    done_by INT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_list_id) REFERENCES task_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_member_id) REFERENCES family_members(id) ON DELETE SET NULL
);
```

Bewusst **eigene Tabellen statt Zweckentfremdung von `events`**: eine Aufgabe hat kein `starts_at`/`ends_at`, keine Dauer, keinen Ort — eine Vermischung würde `EventController` mit Sonderfällen verunreinigen (vgl. die Begründung für das reine Freitextfeld `events.category`, siehe `CategoryController`-Doc).

## API-Endpunkte

Analog zum bestehenden REST-Muster (`EventController`, `MemberController`):

| Methode | Route | Zweck |
|---|---|---|
| GET | `/api/task-lists` | Alle Listen der Familie |
| POST | `/api/task-lists` | Neue Liste anlegen |
| DELETE | `/api/task-lists/{id}` | Liste löschen |
| GET | `/api/task-lists/{id}/tasks` | Aufgaben einer Liste |
| POST | `/api/task-lists/{id}/tasks` | Neue Aufgabe |
| PATCH | `/api/tasks/{id}` | Bearbeiten/Abhaken/Zuweisen |
| DELETE | `/api/tasks/{id}` | Aufgabe löschen |

Mandantentrennung wie überall über `TenantContext::familyId()` in jeder Query — kein neuer Isolationsmechanismus nötig.

## UI

- Neuer Menüpunkt "Aufgaben" neben "Kalender"/"Mitglieder"/"Quellen" (`appChromeOpen('tasks')`)
- Listenübersicht (Karten wie bei `calendar_sources.php`) → Klick öffnet Detailansicht mit Checkbox-Items
- Zuweisung per Mitglied-Chip, identisches Muster wie die Teilnehmer-Auswahl im bestehenden `event_form.php`
- Optional (Phase 2): kleines Badge in der Bottom-Nav bei offenen, fälligen Aufgaben — analog zum ungelesen-Punkt beim Push-Menüpunkt

## Plan-Gating

Vorschlag, analog zum Kategorien-Muster (`requirePlusPlan()`):
- **Free-Plan:** eine feste Liste ("Haushalt"), kein Anlegen weiterer Listen
- **Plus-Plan:** beliebig viele Listen

Offene Entscheidung: ob das überhaupt gated werden soll, oder Aufgabenlisten (anders als Kategorien) als reines Basis-Feature für alle Pläne dienen sollen, um die Kernfunktion "gemeinsamer Familienplaner" nicht zu stark zu beschneiden.

## Verzahnung mit dem Kalender (Phase 2, optional)

Aufgaben mit `due_date` könnten im Kalender als kleine Markierung am jeweiligen Tag erscheinen (separates Rendering, **keine** Zeile in `events` — vermeidet Datenvermischung). Erst sinnvoll, sobald die Grundfunktion steht und sich zeigt, ob Nutzer das wirklich wollen.

## Phasenplan & Aufwand (grobe Einschätzung)

| Phase | Umfang | Aufwand |
|---|---|---|
| 1 | Datenmodell + CRUD-API + einfache UI (Liste anlegen, Aufgaben abhaken) | 3–4 Tage |
| 2 | Zuweisung an Mitglieder + Fälligkeitsdatum + Bottom-Nav-Badge | 2–3 Tage |
| 3 | Wiederkehrende Aufgaben (bestehende `RRule`-Logik aus Terminen wiederverwenden) | 2 Tage |
| 4 (optional) | Kalender-Verzahnung (Tagesmarkierung bei fälligen Aufgaben) | 1–2 Tage |

Quellen zur Einordnung gegenüber etablierten Familien-Apps:
- [Cozi / FamilyWall / OurHome Übersicht (toolguide.io)](https://toolguide.io/de/tool/cozi/)
- [Familienkalender-Apps im Vergleich (GIGA)](https://www.giga.de/artikel/familienkalender-apps-die-besten-familienplaner-fuer-android-iphone/)
