# Wetter-Integration

## Problem / Motivation
Bei der Planung von Terminen (Ausflüge, Grillabende, Sportveranstaltungen) ist die Wetterlage oft die entscheidende Zusatzinformation. Aktuell müssen Nutzer dafür die App verlassen und eine separate Wetter-App/Webseite konsultieren. Eine kleine Wettervorhersage direkt neben den Terminen der nächsten Tage würde die App als zentrale Planungsstelle stärken.

## Zielgruppe & Nutzen
Familien, die Outdoor-Aktivitäten planen (Ausflüge, Gartenarbeit, Sport). Nutzen: schnellerer Blick, ob ein geplanter Termin bei dem zu erwartenden Wetter überhaupt sinnvoll ist, ohne App-Wechsel.

## Funktionsumfang (v1)
- Pro Familie wird ein Standort (Ort/PLZ) in den Familieneinstellungen hinterlegt.
- Für Termine der nächsten 5–7 Tage wird neben dem Termin ein kompaktes Wetter-Badge angezeigt (z.B. "18°C, sonnig" mit kleinem Icon).
- Wetterdaten werden über eine externe Wetter-API (z.B. Open-Meteo, das keinen API-Key benötigt und kostenlos ist — passt zum Stil minimaler Abhängigkeiten) abgerufen und serverseitig zwischengespeichert (z.B. 1–3 Stunden Cache pro Standort), um Rate-Limits und Latenz zu vermeiden.
- Nur für Termine mit Datum in der Zukunft innerhalb des Vorhersagezeitraums; keine historischen/rückwirkenden Wetterdaten.

## User Stories
- Als Familienmitglied möchte ich beim Blick auf den Kalender sofort sehen, ob für den geplanten Grillabend am Samstag Regen erwartet wird, damit ich frühzeitig umplanen kann.
- Als Admin möchte ich einmalig den Wohnort der Familie hinterlegen, damit alle Mitglieder passende Wettervorhersagen sehen, ohne das selbst einzurichten.
- Als Nutzer möchte ich, dass die Wetteranzeige nicht die App verlangsamt, auch wenn die externe API mal langsam antwortet.

## Datenmodell
- Neue Spalten in `families`: `weather_location_name` (string, z.B. "Dresden"), `weather_lat` (decimal), `weather_lon` (decimal) — Koordinaten werden einmalig bei Eingabe des Ortsnamens per Geocoding-API aufgelöst und gespeichert, damit nicht bei jeder Anzeige erneut geocodiert werden muss.
- Neue Tabelle `weather_cache` (`id`, `lat`, `lon` gerundet auf z.B. 2 Dezimalstellen als Cache-Key, `forecast_data` JSON, `fetched_at`) — unabhängig von `family_id`, da sich Familien an denselben Koordinaten die Cache-Daten teilen können (DSGVO-neutral, da nur Wetterdaten, keine Personenbezug).

## API-Endpunkte
- `PUT /api/families/{id}/weather-location` — Standort setzen (löst Geocoding aus, speichert Koordinaten).
- `GET /api/families/{id}/weather-forecast` — liefert die Vorhersage für die nächsten Tage (aus Cache oder frisch von der externen API, falls Cache abgelaufen); wird von `calendar.php` beim Laden der Monats-/Wochen-/Tagesansicht zusätzlich aufgerufen.

## UI/UX
- In den Familieneinstellungen (vermutlich `family_settings.php` o.ä.) ein neues Feld "Standort für Wettervorhersage" mit Ortsnamen-Eingabe.
- In Monats-/Wochen-/Tagesansicht: kleines Wetter-Badge (Icon + Temperatur) neben dem Datum eines Tages, an dem mind. ein Termin liegt und für den eine Vorhersage verfügbar ist — nicht für jeden Tag ohne Termin, um die Ansicht nicht zu überladen.
- Fallback: Wenn kein Standort hinterlegt ist, wird einfach kein Wetter-Badge angezeigt (kein Pflichtfeld, kein Onboarding-Zwang).

## Abhängigkeiten & Integration
- Neuer externer HTTP-Call (via `curl`, analog zum bestehenden `ApiClient`-Muster) an eine Wetter-API und ggf. eine Geocoding-API (Open-Meteo bietet beide kostenlos ohne Key an).
- Serverseitiges Caching nötig, um Latenz/Ausfälle der externen API nicht auf jeden Kalender-Aufruf durchschlagen zu lassen (bei Fehler: Badge einfach weglassen, nie einen Fehler im Kalender anzeigen).
- Betrifft `calendar.php` (Anzeige), neue Settings-Seite, neuen API-Controller.

## Aufwandsschätzung
**M** — Die eigentliche API-Anbindung ist einfach, der Aufwand liegt im Caching-Mechanismus, der Geocoding-Fehlerbehandlung (unbekannter Ort) und der platzsparenden UI-Integration in drei verschiedene Kalenderansichten.

## Offene Fragen / Risiken
- DSGVO: Der Standort ist ein Familienstandort, kein Einzelpersonenstandort, und wird nur zur Wetterabfrage genutzt (keine Weitergabe an Dritte außer der Wetter-API selbst) — sollte in `datenschutz.php` als neuer Verarbeitungszweck ergänzt werden, inkl. Nennung der genutzten externen API als Auftragsverarbeiter/Drittanbieter.
- Was passiert bei Terminen mit abweichendem Ort (`location`-Feld des Termins, falls vorhanden) statt dem Familienstandort? Für v1: Wetter bezieht sich immer auf den Familienstandort, nicht auf den im Termin angegebenen Ort (sonst müsste jeder Termin-Ort einzeln geocodiert werden — höherer Aufwand, in v2 denkbar).
- Externe API-Verfügbarkeit/Rate-Limits im Blick behalten, falls viele Familien gleichzeitig denselben Standort abfragen (Cache mit gerundeten Koordinaten mindert das bereits).

## Out of Scope (v1)
- Wetter pro individuellem Termin-Ort (nur Familienstandort).
- Wetterwarnungen/Push-Benachrichtigungen bei Unwetter.
- Mehrere Standorte pro Familie (z.B. Zweitwohnsitz).
