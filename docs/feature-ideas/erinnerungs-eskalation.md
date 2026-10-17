# Erinnerungs-Eskalation

## Problem / Motivation
Aktuell schickt `bin/send-reminders.php` eine Push-Erinnerung genau einmal, `remind_before_minutes` vor dem Termin. Reagiert niemand darauf (z.B. weil das Handy stumm liegt oder die Erinnerung übersehen wird), passiert nichts weiter — der Termin kann trotzdem verpasst werden. Eine Eskalationsstufe ("niemand hat reagiert, informiere jemand anderen") erhöht die Verlässlichkeit gerade bei wichtigen Terminen (Arzttermine, Abholzeiten).

## Zielgruppe & Nutzen
Familien, die sich auf Erinnerungen für wichtige Termine (Arzt, Abholzeiten, Fristen) verlassen wollen, nicht nur auf eine einzelne Push-Benachrichtigung. Nutzen: höhere Verlässlichkeit, ohne dass jemand manuell nachhaken muss.

## Funktionsumfang (v1)
- Eine Erinnerung kann optional als "eskalierend" markiert werden.
- Reagiert der/die ursprünglich erinnerte Person nicht innerhalb eines konfigurierbaren Zeitfensters (z.B. 15 Minuten) — "reagiert" heißt hier: öffnet die zugehörige Push-Benachrichtigung bzw. ruft den Termin in der App auf — wird automatisch eine zweite Erinnerung an ein weiteres, vorher festgelegtes Familienmitglied gesendet.
- Nur eine Eskalationsstufe in v1 (keine Eskalationsketten über mehrere Personen).
- Eskalation ist rein optional/opt-in pro Erinnerung, Standardverhalten bleibt wie bisher unverändert.

## User Stories
- Als Elternteil möchte ich bei einem wichtigen Arzttermin festlegen, dass mein:e Partner:in benachrichtigt wird, falls ich die Erinnerung nicht wahrnehme.
- Als Familienmitglied möchte ich durch das Öffnen der Erinnerung/des Termins signalisieren, dass ich sie wahrgenommen habe, damit keine unnötige Eskalation ausgelöst wird.
- Als Elternteil möchte ich beim Anlegen einer Erinnerung auswählen können, an wen eskaliert werden soll.

## Datenmodell
Erweiterung der bestehenden `reminders`-Tabelle statt einer komplett neuen Struktur, da Eskalation konzeptionell eine Zusatzeigenschaft einer Erinnerung ist:

```sql
ALTER TABLE reminders
  ADD COLUMN escalate_to_user_id INT NULL AFTER user_id,
  ADD COLUMN escalate_after_minutes INT NULL AFTER remind_before_minutes,
  ADD COLUMN acknowledged_at DATETIME NULL AFTER sent,
  ADD COLUMN escalated_at DATETIME NULL AFTER acknowledged_at,
  ADD CONSTRAINT fk_reminders_escalate_to FOREIGN KEY (escalate_to_user_id) REFERENCES users(id) ON DELETE SET NULL;
```

- `escalate_to_user_id`: an wen eskaliert wird (NULL = keine Eskalation für diese Erinnerung).
- `escalate_after_minutes`: Wartezeit nach `sent`, bevor eskaliert wird (NULL = keine Eskalation).
- `acknowledged_at`: Zeitpunkt, zu dem die ursprünglich erinnerte Person reagiert hat (gesetzt durch Öffnen der Erinnerung/des Termins). Bleibt NULL, wenn keine Reaktion erfolgt.
- `escalated_at`: Zeitpunkt der tatsächlichen Eskalation, verhindert Doppel-Eskalation beim nächsten Cron-Lauf.

## API-Endpunkte
- Kein neuer HTTP-Endpunkt für die Eskalationslogik selbst — sie läuft als Erweiterung von `bin/send-reminders.php` (Cron, kein HTTP-Request, kein Session-Kontext).
- `POST /api/reminders` / `PUT /api/reminders/{id}` (bestehend) — Request-Body um `escalate_to_user_id` und `escalate_after_minutes` erweitern.
- Neu: `POST /api/reminders/{id}/acknowledge` — wird von der App aufgerufen, sobald eine zugehörige Push-Benachrichtigung geöffnet oder der verlinkte Termin aufgerufen wird; setzt `acknowledged_at`. Muss idempotent sein (mehrfaches Aufrufen darf keinen Fehler werfen).

## UI/UX
- `reminder_form.php` (bzw. der Erinnerungs-Abschnitt in `event_form.php`): zusätzliches, optionales Feld "Bei keiner Reaktion eskalieren an" (Dropdown mit Familienmitgliedern, die einen Login haben) + "nach wie vielen Minuten" (Zahl).
- Die vom Push-Service ausgelieferte Benachrichtigung verlinkt auf den Termin; das Aufrufen dieser Seite löst `acknowledge` aus (klassischer Link-Klick, kein zusätzliches JS nötig außer dem bereits für Push vorhandenen).
- Eskalierte Erinnerungen werden in der empfangenden Person wie eine normale Push-Erinnerung angezeigt, mit Zusatztext ("Eskalierte Erinnerung: Lina hat nicht reagiert").

## Abhängigkeiten & Integration
- Baut direkt auf der bestehenden `reminders`-Tabelle, `bin/send-reminders.php` und `PushSender` auf — kein Parallelsystem. `send-reminders.php` bekommt einen zweiten Durchlauf/Query-Zweig: "Erinnerungen, die vor >= `escalate_after_minutes` gesendet wurden, nicht `acknowledged_at` haben und noch nicht `escalated_at` gesetzt haben" → `PushSender::sendToUser()` für `escalate_to_user_id`, dann `escalated_at` setzen.
- Nutzt dieselbe Infrastruktur wie Fahrgemeinschaften (`fahrgemeinschaften.md`) für "wer soll benachrichtigt werden" — keine neue Personenauswahl-UI nötig, kann denselben Mitglieder-Dropdown-Baustein verwenden.

## Aufwandsschätzung
**M.** Die Datenmodell-Erweiterung ist klein, aber die Cron-Logik muss sorgfältig gegen Doppel-Eskalation und Race Conditions (zwei gleichzeitige Cron-Läufe) abgesichert werden, und der `acknowledge`-Endpunkt muss zuverlässig in den bestehenden Push-Flow eingehängt werden.

## Offene Fragen / Risiken
- Was zählt genau als "reagiert"? Nur das Öffnen der Push-Benachrichtigung selbst, oder reicht auch ein beliebiger App-Login in diesem Zeitraum? Letzteres wäre technisch einfacher, aber ungenauer.
- Soll `acknowledge` auch automatisch ausgelöst werden, wenn die Person den Termin aus einem anderen Grund aufruft (z.B. beim normalen Kalender-Browsing), oder nur gezielt über den Push-Link?
- Mehrstufige Eskalationsketten (A → B → C) sind in v1 bewusst ausgeschlossen — wie groß ist der Bedarf dafür wirklich?
- Was passiert, wenn `escalate_to_user_id` selbst keine eingerichteten Push-Geräte hat (`PushSender::isConfigured()` / keine `push_subscriptions`)? Eskalation müsste dann evtl. auf E-Mail zurückfallen.

## Out of Scope (v1)
- Mehrstufige Eskalationsketten über mehr als eine zusätzliche Person.
- E-Mail-Fallback bei fehlender Push-Konfiguration der Eskalationsperson.
- Eskalation an Personen außerhalb der eigenen Familie.
- Konfigurierbare Eskalationsregeln pro Familie (z.B. "immer an den Familien-Admin eskalieren") — in v1 wird pro Erinnerung einzeln festgelegt.
