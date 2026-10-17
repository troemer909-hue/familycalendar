# Kontaktbuch

## Problem / Motivation
Familien verwalten viele wichtige Kontakte (Kinderarzt, Lehrer, Babysitter, Freunde, Großeltern), deren Telefonnummern/Adressen heute verstreut in Telefon-Adressbüchern einzelner Familienmitglieder liegen. Beim Anlegen eines Termins ("Zahnarzttermin Lena") fehlt ein schneller Zugriff auf die zugehörigen Kontaktdaten.

## Zielgruppe & Nutzen
Alle Familienmitglieder mit Login. Nutzen: eine gemeinsame, familienweite Adressliste statt mehrerer privater Telefonbücher; direkte Verknüpfung zwischen Terminen und den dazugehörigen Ansprechpartnern.

## Funktionsumfang (v1)
- Familienweite Liste von Kontakten mit Name, Rolle/Kategorie (z.B. "Arzt", "Lehrer", "Freund", "Babysitter", "Familie", "Sonstiges"), Telefonnummer, E-Mail, Adresse, Freitext-Notiz.
- Anlegen/Bearbeiten/Löschen durch jedes Familienmitglied mit Login (kein getrenntes Berechtigungskonzept in v1 – alle sehen und pflegen dieselbe Liste).
- Optionale Verknüpfung eines Kontakts mit einem oder mehreren Terminen ("Ansprechpartner für diesen Termin").
- Einfache Suche/Filterung nach Name und Kategorie.

## User Stories
- Als Elternteil möchte ich die Telefonnummer der Kinderärztin schnell finden, ohne im eigenen Handy suchen zu müssen.
- Als Familienmitglied möchte ich einen Termin mit dem passenden Kontakt verknüpfen, damit beim Öffnen des Termins direkt die Telefonnummer sichtbar ist.
- Als Nutzer möchte ich Kontakte nach Kategorie filtern (z.B. nur "Ärzte"), um schnell den richtigen zu finden.
- Als Familienmitglied möchte ich einen veralteten Kontakt löschen können.

## Datenmodell
```sql
CREATE TABLE IF NOT EXISTS contacts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(50) NULL,          -- z.B. 'arzt', 'lehrer', 'freund', 'babysitter', 'familie', 'sonstiges'
  phone VARCHAR(50) NULL,
  email VARCHAR(191) NULL,
  address VARCHAR(255) NULL,
  notes VARCHAR(1000) NULL,
  created_by INT NOT NULL,            -- users.id
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_family_name (family_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_contacts (
  event_id INT NOT NULL,
  contact_id INT NOT NULL,
  PRIMARY KEY (event_id, contact_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
`email` bewusst `VARCHAR(191)` entsprechend dem bestehenden Namens-/Typstil für indizierte E-Mail-ähnliche Felder (siehe `users.email`), auch wenn hier kein UNIQUE-Index nötig ist – zur Konsistenz. `event_contacts` als reine n:m-Verknüpfungstabelle, da ein Kontakt an mehreren Terminen hängen kann und ein Termin mehrere Kontakte haben kann (z.B. Arzttermin mit zwei begleitenden Kindern und einem Arzt-Kontakt plus einem Fahrdienst-Kontakt).

## API-Endpunkte
- `GET /api/contacts?category=...&q=...` — Liste aller Kontakte der Familie, optional gefiltert nach Kategorie und Suchbegriff (Name).
- `POST /api/contacts` — Body `{ name, category?, phone?, email?, address?, notes? }`.
- `PUT /api/contacts/{id}` — Bearbeiten.
- `DELETE /api/contacts/{id}` — löschen (entfernt auch zugehörige `event_contacts`-Einträge via CASCADE).
- `GET /api/events/{id}/contacts` — verknüpfte Kontakte eines Termins.
- `POST /api/events/{id}/contacts` — Body `{ contact_id }`, verknüpft Kontakt mit Termin.
- `DELETE /api/events/{id}/contacts/{contactId}` — löst Verknüpfung.

## UI/UX
Neue Seite `public/contacts.php` (Sidebar-Gruppe "Verwaltung", neben `members.php`): Tabellenliste aller Kontakte mit Suchfeld und Kategorie-Dropdown-Filter, "+ Kontakt hinzufügen"-Button führt zu `contact_form.php` (analog zu `event_form.php`: Name, Kategorie-Dropdown, Telefon, E-Mail, Adresse, Notizen). In `event_form.php` ein zusätzlicher Abschnitt "Verknüpfte Kontakte" mit Mehrfachauswahl (Checkboxen analog zu `participant-checkboxes`, siehe `styles.css`) aus der bestehenden Kontaktliste. In der Termin-Detailansicht/Agenda (`day-event` in `calendar.php`) erscheinen verknüpfte Kontakte mit Telefonnummer als schnell sichtbare Zusatzinfo.

## Abhängigkeiten & Integration
- Eigenständige Grundfunktion (Kontaktliste), zusätzlich optionale Verknüpfung zu `events` über die neue `event_contacts`-Tabelle.
- Keine Abhängigkeit zu anderen hier vorgeschlagenen Features, ergänzt sich aber gut mit `notizen-kommentare-termine.md` (z.B. "Rückruf beim Arzt nötig" als Kommentar unter einem Termin mit verknüpftem Arzt-Kontakt).

## Aufwandsschätzung
**S–M.** Zwei einfache Tabellen (eine Stammdaten-Tabelle analog zu `family_members`, eine schlanke n:m-Verknüpfung), Standard-CRUD ähnlich dem bestehenden `calendar_sources`-Grundgerüst, plus eine neue Übersichtsseite und eine kleine Erweiterung von `event_form.php`.

## Offene Fragen / Risiken
- Sollen Kontakte einem einzelnen Familienmitglied "gehören" (z.B. "das ist Lenas Kinderärztin") oder sind sie grundsätzlich familienweit ohne Mitglieder-Zuordnung? v1 geht von familienweit ohne Zuordnung aus, der Freitext-Notizbereich kann das abdecken.
- Feste Kategorie-Liste (Enum) vs. Freitext – feste Liste ermöglicht später Icons/Filter-Konsistenz, Freitext ist flexibler für Sonderfälle.
- Darf jedes Familienmitglied jeden Kontakt löschen, oder nur der Ersteller/Admin (analog zu Kommentar-Löschrechten in `notizen-kommentare-termine.md`)?

## Out of Scope (v1)
- Import aus externen Adressbüchern (vCard/CSV).
- Foto/Avatar pro Kontakt.
- Rollenbasierte Sichtbarkeit einzelner Kontakte (z.B. nur für bestimmte Mitglieder sichtbar).
- Automatischer Abgleich mit Telefon-Kontakten des Geräts.
