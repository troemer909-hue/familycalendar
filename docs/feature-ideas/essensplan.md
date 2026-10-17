# Essensplan / Wochenmenü

## Problem / Motivation
"Was essen wir heute?" ist eine der häufigsten täglichen Familien-Diskussionen. Ein gemeinsamer Wochen-Essensplan, direkt neben dem Kalender sichtbar, nimmt diese Entscheidung vorweg und hilft gleichzeitig bei der Einkaufsplanung.

## Zielgruppe & Nutzen
Alle Familienmitglieder, besonders die Person, die hauptsächlich kocht/einkauft. Nutzen: Planungssicherheit für die Woche, weniger spontaner "wir bestellen was"-Stress, bessere Verzahnung mit der Einkaufsliste.

## Funktionsumfang (v1)
- Pro Tag ein bis zwei Mahlzeiten-Einträge (z.B. Mittag/Abend), Freitext-Titel ("Spaghetti Bolognese"), optionale Notiz (Rezept-Link, Zutaten-Stichpunkte).
- Wochenansicht analog zur bestehenden Kalender-Wochenansicht (siehe `public/calendar.php`, `view=week`), als eigene Spalte oder eigene Seite.
- Essensplan-Eintrag einem Familienmitglied als "kocht heute" zuordnen (optional, nutzt `family_members`).
- Kein voller Rezept-Datenbank-Ansatz in v1 — reiner Planungs-Kalender, kein Kochbuch.

## User Stories
- Als Elternteil möchte ich für die Woche im Voraus festlegen, was gekocht wird, damit der Einkauf gezielt passiert.
- Als Familienmitglied möchte ich sehen, was heute Abend gekocht wird, ohne nachfragen zu müssen.
- Als Elternteil möchte ich festlegen, wer heute kocht, damit die Aufgabe klar verteilt ist.

## Datenmodell
```sql
CREATE TABLE IF NOT EXISTS meal_plan_entries (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  meal_date DATE NOT NULL,
  meal_slot ENUM('breakfast','lunch','dinner') NOT NULL DEFAULT 'dinner',
  title VARCHAR(255) NOT NULL,
  notes TEXT NULL,
  cooked_by INT NULL,                       -- family_members.id, wer kocht
  created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (cooked_by) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES family_members(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_family_date_slot (family_id, meal_date, meal_slot),
  INDEX idx_family_date (family_id, meal_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## API-Endpunkte
- `GET /api/meal-plan?from=YYYY-MM-DD&to=YYYY-MM-DD` — Einträge im Zeitraum (gleiches Range-Muster wie `GET /api/events`).
- `POST /api/meal-plan` — Eintrag anlegen (`meal_date`, `meal_slot`, `title`, optional `notes`, `cooked_by`).
- `PATCH /api/meal-plan/{id}` — ändern.
- `DELETE /api/meal-plan/{id}` — löschen.

## UI/UX
Neue Seite `meal_plan.php`: Wochenraster analog zur bestehenden Wochenansicht, aber mit Mahlzeiten statt Terminen als Zellen-Inhalt. Neuer Sidebar-Punkt "Essensplan" in der Gruppe "Kalender". Optional: kleiner "Heute: 🍝 Spaghetti"-Hinweis auf der normalen Kalenderseite (analog zu den Mitglieder-Chips in der Sidebar), um die Sichtbarkeit zu erhöhen, ohne eine eigene Seite aufrufen zu müssen.

## Abhängigkeiten & Integration
Eigenständige Tabelle, lose an `family_members` gekoppelt. Guter Kandidat für spätere Verzahnung mit `einkaufsliste.md` (z.B. "Zutaten zur Einkaufsliste hinzufügen"-Button pro Essensplan-Eintrag), das ist aber explizit nicht Teil von v1.

## Aufwandsschätzung
**M.** Datenmodell und CRUD sind einfach (vergleichbar mit `einkaufsliste.md`), aber die Wochenraster-UI braucht eigene Template-Arbeit (nicht einfach die bestehende Kalender-Wochenansicht wiederverwendbar, da andere Zellen-Struktur: feste Slots statt beliebig vieler Termine).

## Offene Fragen / Risiken
- Slot-Modell (`breakfast`/`lunch`/`dinner`) könnte zu starr sein für Familien mit anderen Essenszeiten — ggf. freie Slot-Bezeichnung statt ENUM erwägen.
- Soll der Essensplan als normaler Termin in der Hauptkalenderansicht erscheinen (z.B. über `events.category = 'meal'` statt eigener Tabelle)? Eigene Tabelle wurde hier bevorzugt, weil Mahlzeiten kein Start-/Endzeit-Konzept brauchen und die Slot-Struktur klarer ist als ein generischer Termin.

## Out of Scope (v1)
Rezeptdatenbank/Zutatenlisten, automatische Zutaten-Übernahme in die Einkaufsliste, Nährwert-Tracking, Mahlzeiten-Vorlagen/Wiederholung.
