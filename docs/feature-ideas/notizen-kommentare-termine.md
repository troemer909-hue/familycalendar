# Notizen/Kommentare zu Terminen

## Problem / Motivation
Termine haben aktuell nur `description` (vom Ersteller einmalig verfasst) und `location`. Es gibt keine Möglichkeit, dass mehrere Familienmitglieder nachträglich kurze Infos zu einem Termin ergänzen ("Treffpunkt ist jetzt beim Nordeingang", "Ich bringe den Kuchen mit"). Solche Absprachen landen heute in externen Chats und gehen dort unter.

## Zielgruppe & Nutzen
Alle Familienmitglieder mit Login, besonders bei Terminen mit mehreren Teilnehmern (`scope='family'` oder mehrere `event_participants`). Nutzen: eine einzige, mit dem Termin verknüpfte Stelle für Absprachen statt verstreuter Chat-Nachrichten.

## Funktionsumfang (v1)
- Pro Termin eine chronologische Liste kurzer Textkommentare (kein Rich-Text, keine Threads/Antworten).
- Jeder angemeldete Teilnehmer des Termins (oder family-weite Termine: jedes Familienmitglied mit Login) kann kommentieren.
- Kommentare zeigen Autor (display_name), Zeitstempel, Text.
- Eigene Kommentare können gelöscht werden; Familienadmin kann alle Kommentare eines Termins löschen.
- Kein Bearbeiten nach dem Absenden (Einfachheit v1).

## User Stories
- Als Familienmitglied möchte ich unter einem Termin kurz schreiben können, dass sich der Treffpunkt geändert hat, damit alle Teilnehmer informiert sind.
- Als Elternteil möchte ich sehen, wer zu einem gemeinsamen Termin schon etwas angemerkt hat, ohne eine separate Chat-App zu öffnen.
- Als Nutzer möchte ich einen eigenen Kommentar löschen können, wenn ich mich vertippt habe.
- Als Familienadmin möchte ich einen unangemessenen Kommentar entfernen können.

## Datenmodell
```sql
CREATE TABLE IF NOT EXISTS event_comments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  event_id INT NOT NULL,
  family_member_id INT NOT NULL,
  body VARCHAR(1000) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  INDEX idx_event_created (event_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
Kein neues Feld an `events` nötig. `family_id` wird für die Mandantentrennung indirekt über das verknüpfte `event_id` geprüft (Controller muss beim Lesen/Schreiben verifizieren, dass `events.family_id = TenantContext::familyId()`).

## API-Endpunkte
- `GET /api/events/{id}/comments` — Liste aller Kommentare eines Termins (geordnet nach `created_at`), nur wenn der Termin zur eigenen Familie gehört.
- `POST /api/events/{id}/comments` — Body `{ body }`, legt Kommentar mit `family_member_id = TenantContext::familyMemberId()` an.
- `DELETE /api/events/{id}/comments/{commentId}` — nur eigener Kommentar oder `TenantContext::requireAdmin()`.

## UI/UX
Erweiterung von `public/event_form.php` (bzw. einer neuen schreibgeschützten Detailansicht, falls `event_form.php` weiterhin reines Bearbeitungsformular bleiben soll): unterhalb der Termin-Details ein Abschnitt "Notizen" mit Liste + einfachem Texteingabefeld + "Senden"-Button (POST wie andere Formulare, kein JS nötig, volle Seiten-Neuladen passend zum bestehenden "kein JavaScript außer für Push"-Prinzip der App). Kein eigener Sidebar-Eintrag nötig, da kontextabhängig am Termin selbst.

## Abhängigkeiten & Integration
- Baut direkt auf `events` und `family_members` auf, keine Abhängigkeit zu anderen hier vorgeschlagenen Features.
- Könnte später mit Push-Benachrichtigungen verknüpft werden ("Neuer Kommentar zu deinem Termin"), ist aber nicht Teil von v1.

## Aufwandsschätzung
**S–M.** Vergleichbar mit `reminders` (einfache 1:n-Tabelle an `events`, keine komplexe Logik), aber zusätzlich ein neues PHP-Formular/Teilansicht und zwei neue API-Endpunkte plus Lösch-Berechtigungslogik.

## Offene Fragen / Risiken
- Sollen Kommentare bei wiederkehrenden Terminen pro Instanz (`recurrence_parent_id`) oder nur am Master sichtbar sein?
- Erlaubte Länge/Moderation bei missbräuchlichen Inhalten (aktuell nur Familienadmin kann löschen, kein Melde-Mechanismus).
- Soll der Termin-Ersteller bei neuem Kommentar per Mail/Push benachrichtigt werden (koppelt an `notification_preferences`)?

## Out of Scope (v1)
- Bearbeiten von Kommentaren nach dem Absenden.
- Anhänge/Bilder in Kommentaren.
- Threading/Antworten auf einzelne Kommentare.
- Automatische Benachrichtigung bei neuem Kommentar.
