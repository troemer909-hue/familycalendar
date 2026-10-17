# Vorrat-Tracker / "Wird knapp"-Liste

## Problem / Motivation
Haushaltsgegenstände wie Windeln, Toilettenpapier oder Spülmittel gehen oft genau dann aus, wenn niemand daran gedacht hat, sie rechtzeitig nachzubestellen. Eine einfache Liste wiederkehrender Haushaltsartikel mit "wird knapp"-Markierung schließt diese Lücke, ohne ein vollwertiges Inventar-System zu sein.

## Zielgruppe & Nutzen
Familien mit Kindern (Windeln, Pflegeprodukte) oder größerem Haushalt. Nutzen: kein böses Erwachen, wenn der letzte Rest aufgebraucht ist — jeder kann markieren, dass etwas knapp wird, und es landet direkt auf der Einkaufsliste.

## Funktionsumfang (v1)
- Liste wiederkehrender Haushaltsartikel pro Familie (z.B. "Toilettenpapier", "Spülmaschinentabs") — einmalig angelegt, bleibt dauerhaft bestehen (anders als die Einkaufsliste, die nach dem Einkauf geleert wird).
- Button "wird knapp" pro Artikel → erzeugt automatisch einen Eintrag in der Einkaufsliste (siehe `einkaufsliste.md`) und markiert den Artikel als "gemeldet".
- Nach dem Einkauf (Abhaken in der Einkaufsliste) wird die Markierung "gemeldet" wieder zurückgesetzt, der Artikel bleibt aber in der Vorratsliste erhalten für die nächste Meldung.
- Kein Mengen-Tracking, kein automatisches Nachbestellen bei Online-Händlern.

## User Stories
- Als Familienmitglied möchte ich beim Entdecken des letzten Pakets Windeln einen Knopf drücken, damit es automatisch auf der Einkaufsliste landet, ohne selbst dort nachzutippen.
- Als Elternteil möchte ich eine feste Liste "diese Dinge brauchen wir regelmäßig" pflegen, damit nicht jedes Mal neu überlegt werden muss, was es überhaupt an Haushaltsartikeln gibt.

## Datenmodell
```sql
CREATE TABLE IF NOT EXISTS household_stock_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  label VARCHAR(255) NOT NULL,              -- z.B. "Toilettenpapier"
  low_stock TINYINT(1) NOT NULL DEFAULT 0,  -- aktuell als "wird knapp" gemeldet
  flagged_by INT NULL,
  flagged_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (flagged_by) REFERENCES family_members(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_family_label (family_id, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
Zusätzlich eine nullable Spalte `source_stock_item_id INT NULL` an `shopping_list_items` (aus `einkaufsliste.md`), damit beim Abhaken des erzeugten Einkaufslisten-Eintrags der zugehörige `household_stock_items.low_stock` automatisch wieder auf `0` gesetzt werden kann.

## API-Endpunkte
- `GET /api/household-stock` — alle Vorratsartikel der Familie, inkl. `low_stock`-Status.
- `POST /api/household-stock` — neuen dauerhaften Artikel anlegen (`label`).
- `POST /api/household-stock/{id}/flag-low` — als knapp markieren → erzeugt serverseitig einen `shopping_list_items`-Eintrag mit `source_stock_item_id` gesetzt.
- `DELETE /api/household-stock/{id}` — Artikel dauerhaft aus der Vorratsliste entfernen.

## UI/UX
Neue Seite `household_stock.php`: einfache Liste mit "Wird knapp"-Button pro Artikel (ausgegraut/markiert, wenn schon gemeldet), plus Formular zum Anlegen neuer dauerhafter Artikel. Erreichbar über einen Link von der Einkaufsliste-Seite aus ("Vorratsliste verwalten"), kein eigener Sidebar-Punkt nötig — ordnet sich der Einkaufsliste unter.

## Abhängigkeiten & Integration
**Setzt `einkaufsliste.md` voraus** (schreibt direkt in `shopping_list_items`). `MemberController`-Pattern für Zuordnung zu `family_members` wie bei anderen Features.

## Aufwandsschätzung
**S.** Kleine zusätzliche Tabelle, eine zusätzliche Spalte an einer bestehenden Tabelle, ein einziger "interessanter" Endpunkt (`flag-low`, der in eine andere Tabelle schreibt) — der Rest ist einfaches CRUD.

## Offene Fragen / Risiken
- Rückkopplung beim Abhaken in der Einkaufsliste (automatisches Zurücksetzen von `low_stock`) erfordert, dass `shopping_list_items`-Checkout-Logik um diesen Spezialfall erweitert wird — sauber nur lösbar, wenn beide Features zusammen geplant werden.
- Ist die 1:1-Kopplung an die Einkaufsliste zu eng, falls jemand die Vorratsliste ohne Einkaufsliste nutzen möchte? Für v1 bewusst gekoppelt, um keine zweite parallele Liste zu pflegen.

## Out of Scope (v1)
Mengenangaben/Bestandszahlen, automatisches Nachbestellen, Ablaufdaten (Haltbarkeit), Kategorisierung nach Raum/Schrank.
