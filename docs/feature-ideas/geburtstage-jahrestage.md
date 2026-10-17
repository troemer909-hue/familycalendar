# Geburtstags-/Jahrestage-Übersicht

## Problem / Motivation
Jedes Familienmitglied-Profil speichert bereits ein Geburtsjahr
(`family_members.birth_year`), aber die App nutzt diese Information
aktuell nirgends. Eltern tragen Geburtstage deshalb doppelt manuell als
normale Termine ein (oder vergessen sie), obwohl die App das automatisch
könnte.

## Zielgruppe & Nutzen
Alle Familien mit Kindern/Angehörigen, deren Geburtsjahr bereits beim
Anlegen des Profils hinterlegt wird. Nutzen: ein Geburtstag erscheint ohne
manuellen Aufwand jedes Jahr automatisch im Kalender, inkl. automatischer
Erinnerung (z.B. "in 3 Tagen") und optional dem runden Alter ("wird 8").

## Funktionsumfang (v1)
- Für jedes Familienmitglied mit gesetztem `birth_year` und einem
  zusätzlich erfassten Geburtstag (Monat/Tag) wird automatisch ein
  jährlich wiederkehrender, familienweiter Ganztagestermin generiert/
  angezeigt - ohne dass jemand ihn manuell anlegt.
- Darstellung im Kalender mit eigenem Icon (🎂) statt normaler Event-Farbe.
- Automatische Erinnerung X Tage vorher (Standardwert konfigurierbar),
  nutzt den bestehenden Reminder-/Push-Mechanismus.
- Anzeige des erreichten Alters im Termin-Titel ("Annas 8. Geburtstag").
- Eigene, zusätzliche Jahrestage (Hochzeitstag, Einzugsdatum o.ä.) als
  freier Eintrag, nicht nur an ein `family_members`-Profil gebunden.

## User Stories
- Als Elternteil möchte ich beim Anlegen eines Profils optional Monat und
  Tag des Geburtstags angeben, damit die App das jedes Jahr automatisch
  im Kalender zeigt.
- Als Familienmitglied möchte ich automatisch einige Tage vorher an
  bevorstehende Geburtstage erinnert werden, damit ich rechtzeitig ein
  Geschenk besorgen kann.
- Als Admin möchte ich zusätzlich einen Hochzeitstag eintragen können, der
  nicht an ein Mitgliedsprofil gebunden ist.
- Als Nutzer möchte ich auf einen Blick sehen, wie alt die Person an
  diesem Geburtstag wird.

## Datenmodell
`family_members.birth_year` existiert bereits (Typ `YEAR`), erfasst aber
nur das Jahr, nicht Monat/Tag - für eine wiederkehrende Jahresanzeige
reicht das nicht. Zwei Spalten ergänzen:

```sql
ALTER TABLE family_members
  ADD COLUMN birthday_month TINYINT UNSIGNED NULL AFTER birth_year,
  ADD COLUMN birthday_day TINYINT UNSIGNED NULL AFTER birthday_month;
```

Neue Tabelle für freie Jahrestage (nicht an ein Profil gebunden):

```sql
CREATE TABLE IF NOT EXISTS anniversaries (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,             -- z.B. "Hochzeitstag"
  month TINYINT UNSIGNED NOT NULL,
  day TINYINT UNSIGNED NOT NULL,
  year_established YEAR NULL,              -- optional, für "seit X Jahren"
  reminder_days_before INT NOT NULL DEFAULT 3,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Geburtstage selbst werden NICHT als echte Zeilen in `events` materialisiert
(das würde bei jedem Jahreswechsel Pflege brauchen), sondern zur Laufzeit
aus `birthday_month`/`birthday_day` berechnet - analog zur bereits
bestehenden RRule-Logik für wiederkehrende Termine, aber ohne eigene DB-
Zeile pro Instanz.

## API-Endpunkte
- `GET /api/members` erweitert um `birthday_month`/`birthday_day` im
  Response (ergänzt die bestehende Spaltenliste in `MemberController`).
- `PUT /api/members/{id}` (neu, oder Erweiterung der bestehenden
  Profilbearbeitung) zum Setzen von Monat/Tag.
- `GET /api/anniversaries`, `POST /api/anniversaries`,
  `DELETE /api/anniversaries/{id}` - CRUD für freie Jahrestage, analog zu
  `CalendarSourceController`.
- `GET /api/events` (bestehender Endpunkt) müsste serverseitig die
  berechneten Geburtstags-/Jahrestags-"Pseudo-Termine" für den
  angefragten Zeitraum in die Antwort einmischen, oder alternativ ein
  separater `GET /api/birthdays?from=...&to=...`, den `calendar.php`
  zusätzlich zu `/api/events` abfragt (einfacher zu implementieren, siehe
  Abhängigkeiten).

## UI/UX
- `members.php`: Formular um Monat/Tag-Felder (zwei `<select>` oder ein
  `<input type="date">` ohne Jahr) ergänzen.
- Neue Seite `anniversaries.php` (+ `anniversary_form.php`) für freie
  Jahrestage, erreichbar über die Sidebar-Gruppe "Kalender" neben
  "Mitglieder verwalten".
- `calendar.php`: berechnete Geburtstags-Einträge werden in
  `$eventsByDay` eingemischt (serverseitig zusammengeführt mit den echten
  `/api/events`-Daten) und mit 🎂-Icon statt der sonst üblichen
  Personenfarbe dargestellt.

## Abhängigkeiten & Integration
- Reminder-/Push-Mechanismus (`reminders`, `PushSender`,
  `bin/send-reminders.php`) wird wiederverwendet, braucht aber eine
  Erweiterung, die auch berechnete (nicht in `events` gespeicherte)
  Geburtstags-Termine erkennen und Erinnerungen dafür verschicken kann -
  das ist der technisch anspruchsvollste Teil, da der bestehende
  Reminder-Mechanismus bislang nur an eine echte `events.id` gebunden ist.
- `members.php` (Profilverwaltung) wird erweitert.

## Aufwandsschätzung
**M** - Die reine Anzeige (berechnete Pseudo-Termine einblenden) ist klein
und ähnlich simpel wie die bestehende RRule-Expansion. Die Erinnerungs-
Integration braucht aber eine Erweiterung des Reminder-Datenmodells
(Erinnerungen ohne feste `event_id`), das treibt den Aufwand auf
mittel.

## Offene Fragen / Risiken
- Soll ein Geburtstag für die ganze Familie sichtbar sein (vermutlich ja,
  analog zu `scope='family'`) oder individuell ausblendbar, falls jemand
  seinen Geburtstag nicht öffentlich im Familienkalender haben möchte?
- Wie werden Erinnerungen für berechnete (nicht gespeicherte) Termine
  sauber ohne doppelten Versand (z.B. bei Schaltjahren, 29. Februar)
  realisiert?
- Soll das Alter wirklich angezeigt werden (manche Nutzer empfinden das
  als unerwünscht, z.B. bei Erwachsenen)?

## Out of Scope (v1)
- Externe Geburtstagsglückwunsch-Mails/-Karten.
- Geschenkideen-/Wunschlisten-Verknüpfung.
- Synchronisation mit Kontakten aus dem geplanten Kontaktbuch-Feature.
