# Gemeinsame Einkaufsliste

## Problem / Motivation
Einkaufslisten werden in den meisten Familien aktuell in separaten Apps (Notizen, WhatsApp, Zettel am Kühlschrank) geführt — obwohl die Familien-App bereits der zentrale Ort für "was die Familie gemeinsam organisiert" ist. Eine einfache, gemeinsam bearbeitbare Liste schließt diese naheliegende Lücke.

## Zielgruppe & Nutzen
Alle Familienmitglieder mit Login. Nutzen: eine Liste, die jeder unterwegs (PWA auf dem Handy) einsehen und abhaken kann, ohne Mehrfach-Einkäufe oder vergessene Artikel.

## Funktionsumfang (v1)
- Eine Einkaufsliste pro Familie (kein Multi-Listen-Management in v1, z.B. nicht getrennt nach Supermarkt/Drogerie).
- Artikel hinzufügen (Freitext, optional Menge als Text z.B. "2x"), abhaken, entfernen.
- Abgehakte Artikel werden ausgegraut angezeigt, nicht sofort gelöscht (damit man sieht, was schon im Wagen liegt).
- "Liste leeren" (alle abgehakten Artikel entfernen) als separate Aktion.
- Keine Echtzeit-Synchronisation per WebSocket in v1 — einfacher Seiten-Reload/Pull-to-Refresh reicht, die App ist ohnehin bewusst JavaScript-arm gehalten (siehe README).

## User Stories
- Als Familienmitglied möchte ich beim Entdecken eines leeren Vorrats sofort einen Artikel zur Liste hinzufügen, damit er beim nächsten Einkauf nicht vergessen wird.
- Als einkaufende Person möchte ich die Liste auf dem Handy offen haben und Artikel direkt im Laden abhaken, damit ich den Überblick behalte.
- Als Familienmitglied möchte ich sehen, wer einen Artikel hinzugefügt hat, falls ich nachfragen will, was genau gemeint war.

## Datenmodell
```sql
CREATE TABLE IF NOT EXISTS shopping_list_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  added_by INT NULL,                        -- family_members.id
  label VARCHAR(255) NOT NULL,              -- z.B. "Milch, 2x"
  checked TINYINT(1) NOT NULL DEFAULT 0,
  checked_by INT NULL,
  checked_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (checked_by) REFERENCES family_members(id) ON DELETE SET NULL,
  INDEX idx_family_checked (family_id, checked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## API-Endpunkte
- `GET /api/shopping-list` — alle Artikel der Familie (offen + abgehakt).
- `POST /api/shopping-list` — Artikel hinzufügen (`label`).
- `POST /api/shopping-list/{id}/check` / `POST /api/shopping-list/{id}/uncheck` — abhaken/zurücksetzen.
- `DELETE /api/shopping-list/{id}` — einzelnen Artikel löschen.
- `POST /api/shopping-list/clear-checked` — alle abgehakten Artikel auf einmal entfernen.

## UI/UX
Neue Seite `shopping_list.php` (einfache Liste mit Eingabefeld oben, analog zum Formular-Stil von `members.php`), neuer Sidebar-Punkt "Einkaufsliste" in der Gruppe "Kalender". Checkbox-Interaktion per einfachem POST-Formular (kein JS nötig, passend zum bestehenden Stil der Client-Seiten) — ein Klick auf die Checkbox sendet direkt ein Formular ab und lädt die Seite neu.

## Abhängigkeiten & Integration
Eigenständiges Feature, keine Abhängigkeit zu Terminen. Grundlage für `vorrat-nachbestellen.md` (dort werden Artikel direkt in diese Liste übernommen).

## Aufwandsschätzung
**S.** Eine einfache Tabelle, CRUD ohne Sonderfälle, keine Wiederholungslogik, keine komplexen Berechtigungen (jedes Familienmitglied darf alles).

## Offene Fragen / Risiken
- Soll es Kategorien/Sortierung geben (z.B. nach Supermarkt-Gang), oder bleibt es bei einer flachen Liste?
- Konfliktbehandlung, wenn zwei Personen gleichzeitig denselben Artikel hinzufügen (Duplikate) — in v1 vermutlich einfach tolerieren, kein Merge nötig.

## Out of Scope (v1)
Mehrere benannte Listen, Mengen-/Preis-Tracking, Kategorisierung, Barcode-Scan, Teilen mit Personen außerhalb der Familie.
