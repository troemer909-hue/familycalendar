# Gemeinsam genutzte Ressourcen (z.B. Auto, Werkzeug, Spielkonsole)

## Problem / Motivation
Familien teilen sich oft knappe, nicht beliebig verfügbare Dinge – das Familienauto, den Anhänger, die Bohrmaschine, das Gäste-/Arbeitszimmer, die Spielkonsole, das Gartengrillgerät. Wer gerade was nutzt (oder nutzen möchte), ist heute nirgends sichtbar: Konflikte ("ich brauche doch grad das Auto!") entstehen erst, wenn zwei Familienmitglieder gleichzeitig darauf zugreifen wollen. Ein normaler Termin im Kalender bildet das nur unvollständig ab, da Ressourcen nicht an eine Person, sondern an ein geteiltes "Ding" gebunden sind und mehrere, unabhängig voneinander angelegte Termine denselben Gegenstand betreffen können.

## Zielgruppe & Nutzen
Familien mit mind. einer knappen, gemeinsam genutzten Ressource (klassischerweise das Auto, aber auch Werkzeug, Fahrrad-Anhänger, Spielkonsole, Gästezimmer, Beamer o.ä.). Nutzen: Reservierungen sind für alle sichtbar, Doppelbuchungen werden aktiv verhindert bzw. markiert, und es ist auf einen Blick klar, wer eine Ressource aktuell "hat".

## Funktionsumfang (v1)
- Familienweite Liste benannter Ressourcen (z.B. "Auto (VW Touran)", "Anhänger", "Bohrmaschine"), jeweils mit Name, optionaler Kategorie/Icon und optionaler Freitext-Notiz (z.B. "Tankkarte liegt im Handschuhfach").
- Reservierung einer Ressource für einen Zeitraum (Start/Ende), angelegt von einem Familienmitglied, sichtbar für alle.
- Eine Ressource kann zu **keinem** normalen Kalender-Termin gehören müssen – Reservierung geht auch ganz ohne Termin ("ich nehme morgen 14–17 Uhr das Auto"), kann aber **optional** mit einem bestehenden Termin verknüpft werden (z.B. "Zahnarzttermin" ↔ "Auto").
- Harte Konflikterkennung: Überlappende Reservierungen derselben Ressource werden beim Anlegen abgelehnt bzw. mit Warnung + Vorschlag angezeigt (analog zur Terminkonflikt-Logik, siehe `terminkonflikt-warnung.md`, aber auf Ressourcen statt Personen bezogen – rein additiv, nicht technisch identisch).
- Tagesübersicht "Wer hat heute was reserviert?" als einfache Liste (nicht zwingend im Kalendergrid, siehe UI/UX).

## User Stories
- Als Elternteil möchte ich das Auto für morgen Nachmittag reservieren, damit mein Partner es nicht gleichzeitig für den Großeinkauf einplant.
- Als Familienmitglied möchte ich sofort eine Fehlermeldung sehen, wenn ich eine Ressource für einen bereits reservierten Zeitraum buchen will, statt die Überschneidung erst beim Abholen der Autoschlüssel zu merken.
- Als Nutzer möchte ich eine Reservierung mit einem bestehenden Termin verknüpfen können, damit beim Öffnen des Termins direkt sichtbar ist, welches Fahrzeug dafür vorgesehen ist.
- Als Familienmitglied möchte ich auf einen Blick sehen, welche Ressourcen heute/diese Woche reserviert sind, ohne jeden einzelnen Kalender-Termin durchlesen zu müssen.
- Als Nutzer möchte ich eine eigene, nicht mehr benötigte Reservierung löschen können.

## Datenmodell
```sql
CREATE TABLE IF NOT EXISTS shared_resources (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,         -- z.B. 'Auto (VW Touran)'
  category VARCHAR(50) NULL,          -- z.B. 'fahrzeug', 'werkzeug', 'technik', 'raum', 'sonstiges'
  notes VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,   -- "ausgemustert" statt löschen, falls bereits Reservierungen existieren
  created_by INT NOT NULL,            -- users.id
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_family_active (family_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS resource_reservations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  resource_id INT NOT NULL,
  family_id INT NOT NULL,             -- redundant zu resources.family_id, aber direkt für Tenant-Checks ohne Join nötig (gleiches Muster wie events.family_id)
  reserved_by INT NOT NULL,           -- users.id
  event_id INT NULL,                  -- optionale Verknüpfung zu einem Kalender-Termin
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  note VARCHAR(255) NULL,             -- z.B. 'Großeinkauf'
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resource_id) REFERENCES shared_resources(id) ON DELETE CASCADE,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (reserved_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
  INDEX idx_resource_zeitraum (resource_id, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
Die Überlappungsprüfung läuft rein serverseitig im Controller (`WHERE resource_id = ? AND starts_at < ? AND ends_at > ?`, klassische Intervall-Überschneidung) – **keine** DB-Constraint dafür, da MySQL/MariaDB keine nativen Exclusion-Constraints wie PostgreSQL kennt; das entspricht dem bestehenden Stil der App, Validierung in der Anwendungsschicht statt in der DB zu halten (siehe z.B. die Mandantentrennung über `TenantContext` statt Row-Level-Security).

## API-Endpunkte
- `GET /api/resources` — Liste aller aktiven Ressourcen der Familie.
- `POST /api/resources` — Body `{ name, category?, notes? }`.
- `PUT /api/resources/{id}` — Bearbeiten.
- `DELETE /api/resources/{id}` — setzt `is_active=0` statt Hard-Delete (bestehende Reservierungen bleiben als Historie erhalten).
- `GET /api/resources/{id}/reservations?from=...&to=...` — Reservierungen einer Ressource in einem Zeitraum.
- `GET /api/reservations?from=...&to=...` — alle Reservierungen der Familie über alle Ressourcen in einem Zeitraum (für die Tagesübersicht).
- `POST /api/resources/{id}/reservations` — Body `{ starts_at, ends_at, note?, event_id? }`. Antwort `409 Conflict` mit der/den überlappenden Reservierung(en) im Body, falls der Zeitraum bereits belegt ist.
- `DELETE /api/reservations/{id}` — löscht eine eigene Reservierung (oder, wie bei anderen Features, zusätzlich durch Familien-Admin möglich).

## UI/UX
Neue Seite `public/resources.php` (Sidebar-Gruppe "Verwaltung", neben `contacts.php`/`members.php`): Liste der Ressourcen, pro Ressource ein Mini-Zeitstrahl oder eine einfache Liste der kommenden Reservierungen. "+ Reservieren"-Button öffnet `resource_reservation_form.php` (Ressource-Dropdown, Start/Ende wie bei `event_form.php`, optionales Notizfeld, optionale Verknüpfung zu einem bestehenden Termin per Such-/Dropdown-Feld). Bei Zeitüberlappung zeigt das Formular die kollidierende Reservierung (wer, wann, wofür) direkt im Fehler-Banner, analog zum bestehenden `.error-box`-Stil. Zusätzlich ein kompakter Block "Heute reserviert" auf dem Kalender-Dashboard (`calendar.php`), der alle für den angezeigten Tag aktiven Reservierungen auflistet – bewusst **kein** eigenes Kalenderraster in v1, um die Komplexität klein zu halten.

## Abhängigkeiten & Integration
- Eigenständige Grundfunktion, keine harte Abhängigkeit zu anderen Features.
- Optionale, rein additive Verknüpfung zu `events` (ähnliches Muster wie `event_contacts` in `kontaktbuch.md` – daher dieselbe n:m-artige, aber hier 1:n-Beziehung über `resource_reservations.event_id`).
- Ergänzt sich konzeptionell mit `terminkonflikt-warnung.md` (beide lösen "wer/was ist zur selben Zeit schon verplant", aber auf unterschiedlichen Entitäten – Person vs. Ressource –, technisch getrennt zu implementieren, da unterschiedliche Tabellen und Konfliktdefinitionen).
- Passt gut mit `fahrgemeinschaften.md` zusammen (wer das Auto hat, kann relevant dafür sein, wer eine Fahrgemeinschaft übernehmen kann) – explizit keine Integration in v1, nur konzeptionelle Nähe.

## Aufwandsschätzung
**M.** Zwei neue Tabellen, Standard-CRUD für Ressourcen (einfach, analog zu `shared_resources`/`contacts.md`), plus die Überlappungsprüfung mit sauberer Konfliktmeldung (etwas Sorgfalt nötig, aber keine komplexe Logik – reine Intervall-Überschneidung ohne Wiederholungsregeln). Die neue Übersichtsseite und der Dashboard-Block sind zusätzlicher, aber überschaubarer UI-Aufwand.

## Offene Fragen / Risiken
- Darf eine Reservierung von jedem Familienmitglied gelöscht/verändert werden, oder nur vom Ersteller/Admin (gleiche offene Frage wie in `kontaktbuch.md` und `notizen-kommentare-termine.md` – sollte app-weit konsistent beantwortet werden)?
- Soll eine Überlappung **hart blockiert** (v1-Annahme) oder nur als Warnung mit Möglichkeit zum trotzdem-Anlegen angezeigt werden (z.B. wenn zwei Personen sich das Auto bewusst teilen wollen)? Hartes Blockieren ist einfacher und deckt den Hauptfall ("Konflikt verhindern") ab, eine Warn-Variante wäre flexibler aber komplexer in der UI.
- Wiederkehrende Reservierungen (z.B. "jeden Mittwoch das Auto für den Wocheneinkauf") sind in v1 nicht vorgesehen – jede Reservierung ist ein Einzeleintrag; bei Bedarf später über dieselbe `recurrence_rule`-Logik wie bei Terminen denkbar.
- Zeitzonen-/Tagesgrenzen-Behandlung sollte denselben Konventionen folgen wie bei normalen Terminen (`events.starts_at`/`ends_at`), um Inkonsistenzen zu vermeiden.

## Out of Scope (v1)
- Wiederkehrende/serielle Reservierungen.
- Warteliste oder automatische Vorschläge für freie Alternativzeiten bei Konflikt.
- Nutzungsprotokoll/Statistik ("wer hat das Auto diesen Monat am meisten genutzt").
- Zustands-/Zustandserfassung der Ressource selbst (z.B. Tankstand, Schäden, Wartungstermine – eigenes, deutlich größeres Thema).
- Freigabe einzelner Ressourcen nur für bestimmte Familienmitglieder (z.B. Auto nur für Erwachsene) – in v1 sehen und reservieren alle Mitglieder mit Login alle Ressourcen gleich.
