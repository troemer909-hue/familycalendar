# Family-Hub-Kiosk-Modus

## Problem / Motivation
Viele Familien würden ein dauerhaft in der Küche/im Flur hängendes Tablet gerne als "Family Hub" nutzen, der permanent den Kalender zeigt (ähnlich einem smarten Küchendisplay). Die App unterstützt aktuell nur den normalen, vollständig eingeloggten Nutzerzugriff — ein für diesen Einsatzzweck spezialisierter, abgesicherter Anzeige-Modus fehlt.

## Zielgruppe & Nutzen
Familien, die ein Tablet fest montieren/aufstellen wollen (Wandhalterung, Küchentisch-Ständer) als gemeinsamer Blick auf den Familienkalender, ohne dass sich jedes Mal jemand einloggen muss — aber auch ohne dass das Tablet im Falle eines Diebstahls vollen Account-Zugriff offenlegt.

## Funktionsumfang (v1)
- Neue eingeschränkte "Anzeige-Rolle" (Display-Only), die ausschließlich lesenden Zugriff auf den Kalender hat — keine Termine anlegen/bearbeiten/löschen, kein Zugriff auf Mitgliederverwaltung, Einstellungen oder Plattform-Admin.
- Aktivierung über einen zeitlich begrenzten Einmal-Code oder QR-Code, den ein vollberechtigtes Familienmitglied in den Einstellungen generiert und auf dem Kiosk-Gerät einscannt/eingibt — das Gerät erhält dadurch ein eigenes, dauerhaftes aber eingeschränktes Session-Token, unabhängig vom Account des erstellenden Mitglieds.
- Darstellung im Vollbild-Kiosk-Layout (große Schrift, Fokus auf aktuelle Woche/Monat, automatischer Refresh z.B. alle paar Minuten).
- Jedes vollberechtigte Mitglied kann in den Einstellungen alle aktiven Kiosk-Geräte der Familie sehen und einzeln widerrufen (z.B. bei Verlust/Diebstahl des Tablets) — Widerruf sperrt sofort nur dieses eine Gerät-Token, nicht den ganzen Account.

## User Stories
- Als Elternteil möchte ich ein altes Tablet in der Küche als Kalender-Anzeige einrichten, ohne dass es meinen vollen Account-Zugriff hat.
- Als Elternteil möchte ich, dass bei Diebstahl des Küchen-Tablets niemand Termine einsehen/verändern kann, die über das hinausgehen, was ohnehin am Küchen-Display sichtbar war — und ich möchte den Zugriff dieses einen Geräts jederzeit per Klick sperren können.
- Als Nutzer des Kiosk-Tablets möchte ich keine Login-Maske sehen, sondern direkt den Kalender, da das Gerät ja ohnehin nur zum Anschauen da ist.

## Datenmodell
- Neue Tabelle `display_devices` (`id`, `family_id`, `label` [z.B. "Küchen-Tablet"], `device_token_hash`, `created_by_user_id`, `created_at`, `last_seen_at`, `revoked_at` nullable).
- Neue Tabelle `display_device_setup_codes` (`id`, `family_id`, `code_hash`, `created_by_user_id`, `expires_at`, `used_at` nullable) — Einmal-Code, der nach Einlösen (Austausch gegen ein `display_devices`-Token) sofort ungültig wird und nach z.B. 10 Minuten automatisch verfällt.
- Kein Eintrag in der bestehenden `users`-Tabelle — Kiosk-Geräte sind explizit KEINE Nutzerkonten, um eine klare Trennung zur bestehenden Rollenlogik (`family_members`) zu erhalten.

## API-Endpunkte
- `POST /api/display-devices/setup-codes` — erzeugt einen neuen zeitlich begrenzten Einmal-Code (nur für vollberechtigte Mitglieder).
- `POST /api/display-devices/claim` — Kiosk-Gerät tauscht den Einmal-Code gegen ein dauerhaftes, aber eingeschränktes Device-Token.
- `GET /api/display/calendar` — liefert ausschließlich lesbare Kalenderdaten, akzeptiert NUR ein Device-Token (kein normales Nutzer-Token), mit eigener, restriktiver Middleware-Prüfung getrennt von der normalen Auth.
- `GET /api/display-devices` / `DELETE /api/display-devices/{id}` — Verwaltung/Widerruf der aktiven Kiosk-Geräte durch vollberechtigte Mitglieder.

## UI/UX
- Neue eigenständige Kiosk-Ansicht (`public/display.php` o.ä.), die beim Laden das Device-Token (z.B. aus `localStorage` bzw. Query-Parameter nach Einrichtung) prüft und bei Erfolg direkt den Kalender im Großformat zeigt — kein Login-Formular, kein Sidebar-Menü mit administrativen Funktionen.
- In den normalen Familieneinstellungen: neue Sektion "Kiosk-Geräte" mit Liste aktiver Geräte (Label, zuletzt gesehen) und Buttons "Neuen Code generieren" / "Zugriff widerrufen".
- Einrichtungsbildschirm auf dem Kiosk-Gerät: einfaches Eingabefeld für den Code (kein Scannen in v1, das wäre v2 mit QR + Kamera-Zugriff, unnötige Komplexität für den ersten Schritt).

## Abhängigkeiten & Integration
- Benötigt eine komplett separate, bewusst restriktive Auth-Middleware für Device-Tokens, getrennt von der bestehenden `TenantContext`/Nutzer-Token-Logik, damit ein Kompromittieren eines Device-Tokens niemals auf Nutzer-Accounts oder Schreibzugriff durchschlägt.
- Betrifft `TenantContext`/`ApiClient`-Erweiterung um einen zweiten, eingeschränkten Token-Typ, neue Migration für die beiden neuen Tabellen, neue Client-Seite.

## Aufwandsschätzung
**L** — Größter Teil mehrerer bisheriger Ideen: eigenes Berechtigungsmodell, eigene Token-Art, eigene Verwaltungsseite UND eigene Anzeige-Seite. Sicherheitskritisch (neue Angriffsfläche durch Device-Tokens), daher sorgfältige Umsetzung nötig, keine Abkürzungen bei der Trennung von Lese- und Schreibzugriff.

## Offene Fragen / Risiken
- Zentrale Sicherheitsfrage (bereits in der Problemstellung angelegt): Wie verhindert man, dass ein gestohlenes Kiosk-Tablet vollen Account-Zugriff bietet? Antwort in diesem Entwurf: eigene, rein lesende Geräte-Token-Klasse ohne Verbindung zu einem Nutzerpasswort, jederzeit einzeln widerrufbar, kein Zugriff auf Mitglieder-/Einstellungsverwaltung über den Device-Token-Endpunkt — diese Trennung ist nicht verhandelbar und muss auch bei künftigen Erweiterungen (v2) strikt eingehalten werden.
- Wie lange soll ein Device-Token gültig bleiben, bevor es sich automatisch erneuern muss (reine Lebensdauer vs. "läuft nie ab, bis manuell widerrufen")? Empfehlung: kein automatisches Ablaufdatum, da Kiosk-Geräte dauerhaft laufen sollen, aber dafür eine gut sichtbare, einfache Widerrufsmöglichkeit.
- Sollten private/sensible Termine (falls es ein Sichtbarkeits-Konzept für einzelne Mitglieder gibt) auf dem Kiosk-Display grundsätzlich ausgeblendet werden, auch wenn sie regulär family-weit sichtbar wären? Für v1: Kiosk zeigt genau das, was ein normales Familienmitglied auch sähe — keine Extra-Filterung, um Komplexität zu vermeiden.

## Out of Scope (v1)
- QR-Code-Scannen zur Einrichtung (nur manueller Code in v1).
- Interaktion auf dem Kiosk-Display (Termine anlegen/bearbeiten) — rein lesend.
- Individuelle Sichtbarkeits-Filterung einzelner Termine für den Kiosk-Modus.
