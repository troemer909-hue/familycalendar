# Kalender-Kategorien (Filter & Darstellung)

## Problem / Motivation
`events.category` existiert bereits als freies Textfeld
(`VARCHAR(100) NULL`) und ist im Termin-Formular (`event_form.php`) sowie
in `EventController` beim Anlegen/Bearbeiten/Auslesen vollständig
verdrahtet - wird aber in `calendar.php` an keiner Stelle zur Anzeige oder
Filterung genutzt. Nutzer können zwar eine Kategorie eintragen, sehen davon
im Kalender selbst aber nichts: keine Farbcodierung nach Kategorie, kein
Filter "nur Schule anzeigen".

## Zielgruppe & Nutzen
Familien mit vielen und unterschiedlichen Terminarten (Schule, Sport,
Arzttermine, Haushalt, Freizeit). Nutzen: schnellere Orientierung im
Kalender durch Kategorie-Farben/-Icons, sowie die Möglichkeit, gezielt nach
Kategorie zu filtern (z.B. "nur Arzttermine der letzten 3 Monate" für einen
Jahresrückblick).

## Funktionsumfang (v1)
- Statt eines komplett freien Textfelds: eine feste, aber erweiterbare
  Liste vordefinierter Kategorien pro Familie (z.B. Schule, Sport, Arzt,
  Haushalt, Freizeit, Sonstiges), jeweils mit einer Farbe.
- Bestehende freie Texteinträge in `events.category` bleiben beim Umstieg
  erhalten (Migration ordnet unbekannte Werte automatisch "Sonstiges" zu
  oder legt sie als neue Kategorie an, siehe Datenmodell).
- Kategorie-Farbe wird im Kalender als zusätzlicher farbiger Punkt/Rahmen
  am Termin-Chip angezeigt (ergänzend, nicht ersetzend zur bestehenden
  Mitglieder-Farbcodierung).
- Filterleiste in `calendar.php`, um einzelne Kategorien ein-/auszublenden
  (ähnlich der bestehenden Mitglieder-Sidebar-Checkboxen).

## User Stories
- Als Nutzer möchte ich beim Anlegen eines Termins eine Kategorie aus
  einer Liste auswählen statt sie frei zu tippen, damit gleiche Termine
  nicht versehentlich unterschiedlich benannt werden ("Arzt" vs.
  "Arzttermin" vs. "Doktor").
- Als Nutzer möchte ich im Kalender auf einen Blick erkennen, um welche Art
  von Termin es sich handelt, ohne jeden Titel lesen zu müssen.
- Als Nutzer möchte ich die Ansicht auf bestimmte Kategorien einschränken
  können, um z.B. nur Schultermine zu sehen.
- Als Admin möchte ich eigene, familienspezifische Kategorien anlegen
  können (nicht nur die vordefinierte Standardliste).

## Datenmodell
Neue Tabelle, analog zum bestehenden Muster für familienspezifische,
farbcodierte Entitäten (vgl. `family_members.color`):

```sql
CREATE TABLE IF NOT EXISTS event_categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#94a3b8',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_family_category_name (family_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`events.category` (bestehende `VARCHAR(100)`-Spalte) wird durch eine
nullable Fremdschlüssel-Spalte ersetzt/ergänzt:

```sql
ALTER TABLE events
  ADD COLUMN category_id INT NULL AFTER category,
  ADD FOREIGN KEY (category_id) REFERENCES event_categories(id) ON DELETE SET NULL;
```

Migration: pro Familie werden beim Rollout die Standardkategorien
(Schule, Sport, Arzt, Haushalt, Freizeit, Sonstiges) automatisch angelegt;
ein einmaliges Skript gruppiert bestehende `events.category`-Freitextwerte
je Familie, mappt identische/ähnliche Werte auf eine neue
`event_categories`-Zeile und setzt `category_id` entsprechend. Die alte
`category`-Textspalte bleibt vorerst als Fallback/Anzeige für nicht
zuordenbare Altwerte erhalten, statt sie sofort zu löschen.

## API-Endpunkte
- `GET /api/categories`, `POST /api/categories`, `PUT /api/categories/{id}`,
  `DELETE /api/categories/{id}` - CRUD für familienspezifische Kategorien,
  analog zu `MemberController` für Mitglieder-Farben.
- `POST /api/events`/`PUT /api/events/{id}` (bestehend, `EventController`)
  erweitert um `category_id` statt des bisherigen freien `category`-Strings.
- `GET /api/events` (bestehend) liefert `category_id` + aufgelöste
  Kategorie-Farbe/-Name direkt mit, um einen zusätzlichen Request pro
  Seitenaufruf zu vermeiden.

## UI/UX
- `event_form.php`: das bisherige freie Textfeld für `category` wird durch
  ein `<select>` mit den familienspezifischen Kategorien ersetzt (plus
  einem Link "Kategorie verwalten" zur neuen Verwaltungsseite).
- Neue Seite `categories.php` (+ `category_form.php`) zur Verwaltung
  eigener Kategorien (Name, Farbe), erreichbar über die Sidebar neben
  "Mitglieder verwalten".
- `calendar.php`: Termin-Chips bekommen einen zusätzlichen farbigen
  Indikator (z.B. linker Rand in Kategorie-Farbe) neben der bestehenden
  Mitglieder-Farbcodierung; zusätzliche Filterleiste/Checkboxen analog zur
  Mitglieder-Sidebar, um einzelne Kategorien auszublenden.

## Abhängigkeiten & Integration
- `EventController` (bestehend) muss von freiem `category`-String auf
  `category_id`-Fremdschlüssel umgestellt werden - betrifft Anlegen,
  Bearbeiten und die Leseabfrage.
- `event_form.php` und `calendar.php` (Client) müssen entsprechend
  angepasst werden.
- Migration bestehender Freitextwerte (siehe Datenmodell) ist der
  heikelste Teil, da sie nicht rein additiv ist, sondern bestehende Daten
  umschreibt.

## Aufwandsschätzung
**M** - Das Datenmodell und die CRUD-Endpunkte sind unkompliziert und
folgen bestehenden Mustern, aber die Migration bestehender freier
`category`-Werte in strukturierte Kategorien sowie die Anpassung von
Formular und Kalenderanzeige machen das in Summe einen mittleren Aufwand.

## Offene Fragen / Risiken
- Wie aggressiv soll die automatische Migration bestehender Freitextwerte
  gruppieren (z.B. Groß-/Kleinschreibung, Tippfehler) - lieber konservativ
  (jeder eindeutige Altwert wird eine eigene Kategorie) oder mit
  Fuzzy-Matching?
- Sollen Kategorien mit Unterkategorien möglich sein, oder reicht eine
  flache Liste für v1?
- Wie wirkt sich eine zweite Farbcodierung (Kategorie zusätzlich zu
  Mitglied) auf die Lesbarkeit der bestehenden Termin-Chips aus - braucht
  es ein Redesign des Chip-Stils?

## Out of Scope (v1)
- Automatische Kategorie-Zuordnung per Stichwort-Erkennung im Titel (z.B.
  "Zahnarzt" automatisch als "Arzt" erkennen).
- Kategorie-basierte Statistiken/Auswertungen (siehe stattdessen das
  separate Statistik-/Wochenrückblick-Feature).
- Geteilte, familienübergreifende Standardkategorien-Bibliothek.
