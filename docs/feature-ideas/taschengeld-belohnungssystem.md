# Taschengeld-/Belohnungssystem

## Problem / Motivation
Erledigte Aufgaben (siehe geplantes Feature `todo-aufgabenliste.md`) motivieren Kinder stärker, wenn sie sichtbar belohnt werden. Ein einfaches Punkte-/Sterne-System macht Mitarbeit im Haushalt spielerisch nachvollziehbar, ohne dass Eltern manuell Strichlisten führen müssen.

## Zielgruppe & Nutzen
Familien mit Kindern, die Hausaufgaben/Pflichten über ein Belohnungssystem motivieren wollen. Nutzen: automatische, transparente Punktezählung statt Zettel am Kühlschrank.

## Funktionsumfang (v1)
- Jede erledigte Aufgabe (aus `todo-aufgabenliste.md`) kann optional mit einer Punktzahl versehen sein.
- Beim Abhaken einer Aufgabe als erledigt werden die Punkte automatisch dem erledigenden Familienmitglied gutgeschrieben.
- Ein Admin kann Punkte auch manuell anpassen (Korrektur, z.B. bei irrtümlichem Abhaken, oder Bonus/Abzug außerhalb von Aufgaben).
- Eine einfache Übersicht "Punktestand" pro Mitglied, keine Einlösungs-/Shop-Logik in v1 (das Einlösen in echtes Taschengeld bleibt analog, außerhalb der App).

## User Stories
- Als Kind möchte ich sehen, wie viele Punkte ich diese Woche gesammelt habe.
- Als Elternteil möchte ich einer Aufgabe eine Punktzahl zuweisen, damit sie beim Erledigen automatisch gutgeschrieben wird.
- Als Elternteil möchte ich Punkte manuell korrigieren können, falls etwas nicht richtig erledigt wurde.

## Datenmodell
Setzt die Tabelle `tasks` aus `todo-aufgabenliste.md` voraus. Erweiterung dort um ein Punkte-Feld, plus eine neue Tabelle für die Historie/den Kontostand:

```sql
-- Erweiterung der tasks-Tabelle aus todo-aufgabenliste.md:
ALTER TABLE tasks
  ADD COLUMN reward_points INT NOT NULL DEFAULT 0 AFTER title;

-- Punkte-Buchungen (sowohl automatisch durch Aufgaben-Erledigung als auch manuelle Korrekturen):
CREATE TABLE IF NOT EXISTS reward_points_ledger (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_member_id INT NOT NULL,
  task_id INT NULL,                 -- NULL bei manueller Buchung durch einen Admin
  points INT NOT NULL,              -- positiv oder negativ
  reason VARCHAR(255) NULL,         -- Freitext bei manueller Buchung, z.B. "Bonus für Geduld"
  booked_by INT NULL,               -- family_members.id des Admins bei manueller Buchung
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
  FOREIGN KEY (booked_by) REFERENCES family_members(id) ON DELETE SET NULL,
  INDEX idx_member (family_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Der aktuelle Punktestand eines Mitglieds wird bei Bedarf als `SUM(points)` über `reward_points_ledger` berechnet (kein eigenes "Kontostand"-Feld, um Inkonsistenzen durch doppelte Buchführung zu vermeiden).

## API-Endpunkte
- `GET /api/reward-points?family_member_id=` — Punktestand + Buchungshistorie eines Mitglieds.
- `POST /api/reward-points` — manuelle Buchung (nur Admin), Body: `{family_member_id, points, reason}`.
- Automatische Buchung geschieht NICHT über einen eigenen Endpunkt, sondern als Seiteneffekt von `PATCH /api/tasks/{id}` (Aufgabe als erledigt markieren, siehe `todo-aufgabenliste.md`), wenn die Aufgabe `reward_points > 0` hat.

## UI/UX
- Neuer Menüpunkt "Punktestand" in der Sidebar-Gruppe "Kalender" (oder einer neuen Gruppe, falls mit `todo-aufgabenliste.md` zusammen eine eigene Gruppe "Aufgaben" entsteht) — zeigt pro Mitglied den aktuellen Punktestand und eine Liste der letzten Buchungen.
- Admin-Ansicht mit kleinem Formular "Punkte manuell buchen" (Mitglied, Punkte, Grund).
- In der Aufgabenliste selbst (siehe `todo-aufgabenliste.md`) wird die Punktzahl einer Aufgabe als kleines Badge angezeigt (z.B. "+10 Punkte").

## Abhängigkeiten & Integration
- **Harte Abhängigkeit**: setzt das Aufgaben-/To-Do-Feature (`todo-aufgabenliste.md`) voraus — ohne Aufgaben keine automatische Punktevergabe, nur die manuelle Buchung wäre für sich allein sinnvoll nutzbar.
- Berührt `TaskController` (aus dem Aufgaben-Feature) für den Seiteneffekt beim Erledigen.

## Aufwandsschätzung
**S bis M**, ABER erst sinnvoll umsetzbar, nachdem `todo-aufgabenliste.md` existiert. Isoliert betrachtet ist die Ledger-Tabelle + einfache Summenanzeige klein; der Mehraufwand liegt in der sauberen Integration mit dem Aufgaben-Feature.

## Offene Fragen / Risiken
- Sollen Punkte pro Familie in eine echte Währung umrechenbar sein (z.B. "100 Punkte = 1 €"), oder bleibt es abstrakt?
- Negative Punktestände erlauben (Strafpunkte) oder bei 0 deckeln?
- Soll es eine Bestenliste/Vergleich zwischen Geschwistern geben, oder ist das pädagogisch unerwünscht (Konkurrenzdenken)? Eher als Opt-in-Einstellung pro Familie denken.

## Out of Scope (v1)
- Echte Auszahlungs-/Einlöse-Logik (Shop, Belohnungen gegen Punkte eintauschen).
- Bestenlisten/Vergleich zwischen Geschwistern.
- Wiederkehrende automatische Boni (z.B. "Jeden Sonntag +5 Punkte für alle").
