# Patchwork-Familien-Modus

## Problem / Motivation
Getrennte Eltern teilen sich oft die Betreuung gemeinsamer Kinder, führen aber zwei getrennte Haushalte/Accounts (zwei `families`-Datensätze). Aktuell gibt es keine Möglichkeit, einen Termin oder ein Kind zwischen zwei unabhängigen Familien sichtbar zu teilen – jede Familie ist strikt isoliert (`family_id`-Mandantentrennung).

## Zielgruppe & Nutzen
Getrennte/patchwork-lebende Eltern mit gemeinsamen Kindern. Nutzen: Übergabetermine, Kita-/Schultermine oder Arzttermine des Kindes müssen nur einmal gepflegt werden und sind für beide Haushalte sichtbar, statt doppelt in zwei Kalendern erfasst oder per Chat abgestimmt zu werden.

## Funktionsumfang (v1)
- Ein Kind kann als "geteiltes Mitglied" zwischen zwei Familien markiert werden, nachdem beide Seiten einer Einladung zugestimmt haben.
- Termine, die explizit für das geteilte Kind angelegt werden, können optional "auch in der anderen Familie anzeigen" markiert werden und erscheinen dann lesend auch im anderen Kalender.
- Jede Familie bleibt administrativ komplett getrennt (eigene Mitglieder, eigene übrigen Termine, eigenes Abo) – geteilt wird ausschließlich das eine Kind-Profil und die dafür markierten Termine.
- Kein Login-Wechsel nötig: ein Nutzer bleibt in genau einer Familie eingeloggt, sieht aber die geteilten Termine des Kindes zusätzlich in seinem eigenen Kalender.

## User Stories
- Als getrennter Elternteil möchte ich Übergabetermine meines Kindes einmal eintragen, damit sie automatisch auch im Kalender des anderen Elternteils erscheinen.
- Als Elternteil möchte ich einer anderen Familie eine Einladung schicken, um ein gemeinsames Kind zu verknüpfen, ohne dass diese Familie Zugriff auf meine übrigen Termine erhält.
- Als Elternteil möchte ich eine Verknüpfung jederzeit wieder auflösen können.
- Als Nutzer möchte ich geteilte Termine optisch erkennen (z.B. kleines Icon), um sie von eigenen Terminen zu unterscheiden.

## Datenmodell
Die Kernfrage ist, wie ein Kind zwischen zwei `family_id`-Mandanten existieren kann, ohne die bestehende Isolation aufzuweichen. Zwei Ansätze wurden abgewogen:

**Ansatz A – gespiegeltes Kind-Profil (zwei `family_members`-Zeilen):** Das Kind bekäme in jeder Familie eine eigene `family_members`-Zeile (wie heute schon möglich, da `UNIQUE KEY uniq_family_user (family_id, user_id)` nur *pro Familie* eindeutig ist, nicht global). Beide Zeilen würden über eine neue Verknüpfungstabelle als "dasselbe Kind" markiert. Nachteil: Termine müssten bei jeder Änderung in beide Zeilen/Familien dupliziert/synchronisiert werden – hohe Komplexität, Inkonsistenzrisiko (zwei Wahrheiten).

**Ansatz B – Verknüpfungstabelle für geteilte Termine (gewählter Ansatz für v1):** Keine Datenduplizierung. Eine schlanke Tabelle verknüpft zwei Familien über ein konkretes Kind-Mitglied und erlaubt pro Termin eine explizite "auch dort anzeigen"-Freigabe:
```sql
CREATE TABLE IF NOT EXISTS family_links (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_a_id INT NOT NULL,
  family_b_id INT NOT NULL,
  shared_child_member_id INT NOT NULL,   -- family_members.id, gehört zu family_a_id (Haushalt, in dem das Kind "primär" gepflegt wird)
  status ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
  created_by INT NOT NULL,               -- users.id, Initiator der Einladung
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  confirmed_at DATETIME NULL,
  FOREIGN KEY (family_a_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (family_b_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (shared_child_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_link (family_a_id, family_b_id, shared_child_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE events
  ADD COLUMN shared_with_family_id INT NULL AFTER family_id,
  ADD FOREIGN KEY (shared_with_family_id) REFERENCES families(id) ON DELETE SET NULL;
-- NULL = normaler Termin, sonst: Termin ist lesend auch in dieser Familie sichtbar
-- (nur gültig, wenn eine aktive family_links-Zeile zwischen events.family_id
-- und shared_with_family_id existiert).
```
Lesezugriffe über die Grenze hinweg müssen serverseitig explizit geprüft werden (siehe API), **nicht** über `TenantContext::familyId()` allein – das bleibt wie bisher strikt einwertig pro Request.

## API-Endpunkte
- `POST /api/family-links` — Body `{ family_member_id, invite_email }`: schickt eine Einladung an eine andere Familie (per E-Mail-Adresse eines dortigen Admins), legt `family_links`-Zeile mit `status='pending'` an.
- `POST /api/family-links/{id}/confirm` — vom Admin der eingeladenen Familie, setzt `status='active'`.
- `DELETE /api/family-links/{id}` — löst die Verknüpfung auf (`status='revoked'`), ab sofort keine Sichtbarkeit mehr.
- `GET /api/events` (bestehender Endpunkt, erweitert) — liefert zusätzlich zu den eigenen Terminen auch Termine, bei denen `shared_with_family_id = TenantContext::familyId()` und eine aktive `family_links`-Zeile existiert, mit Lesezugriff (kein Bearbeiten/Löschen durch die empfangende Familie).
- `PUT /api/events/{id}` (bestehender Endpunkt, erweitert) — optionales Feld `shared_with_family_id`, nur setzbar, wenn eine aktive `family_links`-Zeile mit der Zielfamilie existiert und `events.family_id = TenantContext::familyId()`.

## UI/UX
Neue Seite `public/family_links.php` (Sidebar-Gruppe "Verwaltung", analog zu `members.php`): Liste bestehender Verknüpfungen (pending/aktiv), Formular "Verknüpfung anfragen" (Kind-Auswahl + E-Mail der anderen Familie), Bestätigen/Auflösen-Buttons. In `event_form.php` ein zusätzliches Dropdown "Auch sichtbar für" (nur befüllt, wenn aktive Verknüpfungen existieren). Im Kalenderraster (`calendar.php`) bekommen geteilte, von außen eingeblendete Termine ein kleines Verknüpfungs-Icon und sind nicht anklickbar zum Bearbeiten.

## Abhängigkeiten & Integration
- Durchbricht als einziges hier vorgeschlagenes Feature die bisher strikte Annahme aus `AuthMiddleware.php` (ein Nutzer gehört zu genau einer Familie, `LIMIT 1`) **nicht** – das bleibt unverändert; stattdessen wird die Grenze gezielt und auditierbar nur für einzelne, explizit freigegebene Termine durchlässig gemacht.
- Berührt `events`, `family_members`, `TenantContext` (zusätzliche Leseregel), `CalendarSourceController` nicht.

## Aufwandsschätzung
**L.** Architektonisch das anspruchsvollste Feature in diesem Katalog: erfordert eine neue, sorgfältig zu prüfende Cross-Tenant-Leseregel (jede Stelle, die heute "alles mit `family_id = X`" liest, muss um den Sharing-Fall erweitert werden, ohne versehentlich Schreibzugriff zu öffnen), einen Einladungs-/Bestätigungs-Flow zwischen zwei unabhängigen Accounts, und sorgfältiges Testen der Sicherheitsgrenzen.

## Offene Fragen / Risiken
- Wie wird die einladende Familie authentifiziert, dass die Ziel-E-Mail wirklich zur gewünschten anderen Familie gehört (Missbrauchspotential: Daten-Exfiltration durch Fake-Einladung)? Vermutlich zwingender Bestätigungsschritt durch einen Admin der Zielfamilie, kein Auto-Accept.
- Soll das geteilte Kind auch in der empfangenden Familie als `family_members`-Zeile auftauchen (z.B. für Teilnehmer-Auswahl in deren eigenen Terminen), oder bleibt es rein auf geteilte Termine beschränkt? v1 beschränkt sich bewusst auf Letzteres, um Ansatz A (Datenduplizierung) zu vermeiden.
- Was passiert mit bereits geteilten Terminen, wenn eine Verknüpfung aufgelöst wird (sofort unsichtbar vs. Bestandsschutz)?
- Abrechnung/Pläne (`plans`): zählt ein geteilter Termin gegen Limits der empfangenden Familie?

## Out of Scope (v1)
- Volles gespiegeltes Kind-Profil mit Synchronisierung in beide Richtungen (Ansatz A).
- Schreibzugriff der empfangenden Familie auf geteilte Termine.
- Mehr als zwei verknüpfte Familien pro Kind.
- Teilen ganzer Kalender/aller Termine statt einzelner, explizit markierter Termine.
