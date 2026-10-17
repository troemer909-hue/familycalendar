# PDF-Jahresplaner

## Problem / Motivation
Manche Familien möchten einen Jahres- oder Monatsüberblick ausdrucken oder als PDF archivieren/versenden (z.B. an Großeltern ohne App-Zugang, oder zum Aufhängen am Kühlschrank). Die App hat aktuell keine PDF-Erzeugung — es gibt nur die normale Bildschirmansicht.

## Zielgruppe & Nutzen
Nutzer, die einen physischen/druckbaren Überblick brauchen (siehe auch verwandte Idee `druckansicht-wochenplan.md`, dort speziell für die Wochenansicht im Browser-Druckdialog), hier erweitert auf einen zusammenhängenden Jahresplaner als echte PDF-Datei zum Herunterladen/Teilen — unabhängig vom Browser-Druckdialog.

## Funktionsumfang (v1)
- Ein Button "Jahresplaner als PDF" (z.B. in den Kalendereinstellungen oder der Jahresansicht, falls vorhanden) erzeugt ein PDF mit 12 Monatsübersichten (eine Seite pro Monat) inkl. aller Termine der Familie.
- Download als Datei, kein Versand per E-Mail in v1.
- Enthält nur Termine, auf die der anfragende Nutzer laut bestehender Berechtigungslogik Zugriff hat (keine Umgehung der Mandanten-/Sichtbarkeitsregeln).

## User Stories
- Als Elternteil möchte ich zu Jahresbeginn einen kompletten Jahresplaner als PDF herunterladen und ausdrucken, um ihn an die Pinnwand zu hängen.
- Als Nutzer möchte ich das PDF an Großeltern schicken können, die keine eigene App-Anmeldung haben.
- Als Nutzer möchte ich, dass das PDF genauso aktuell ist wie der Online-Kalender zum Erzeugungszeitpunkt (keine veralteten Zwischenstände).

## Datenmodell
Keine neue Tabelle nötig — das PDF wird zur Laufzeit aus den bestehenden `events`/`event_participants`-Daten generiert, nicht gespeichert (kein Caching/Archivieren von PDF-Dateien in v1, um Speicherplatz und Sync-Probleme mit späteren Terminänderungen zu vermeiden).

## API-Endpunkte
- `GET /api/export/year-pdf?year=2026` — liefert die PDF-Datei direkt als Response (Content-Type `application/pdf`), kein Zwischenspeichern auf dem Server nötig (Generierung + Stream in einem Request).

## UI/UX
- Button/Link in den Kalender-Einstellungen oder einer neuen "Exportieren"-Sektion, mit Jahresauswahl (Dropdown, Standard: aktuelles Jahr).
- Klick löst direkten Download aus (kein Zwischenbildschirm nötig, einfacher `<a href>`-Link auf den Export-Endpunkt mit Jahresparameter).

## Abhängigkeiten & Integration
Zwei technische Optionen, beide mit Vor-/Nachteilen bezüglich des im Projekt (`composer.json` "suggest"-Sektion) etablierten Stils minimaler Abhängigkeiten:

1. **Reines Print-CSS (`@page`-Regeln) + serverseitiges HTML-zu-PDF-Rendering über eine Kommandozeilen-Lösung** (z.B. falls auf dem Server `wkhtmltopdf` oder ein Headless-Chrome verfügbar ist) — kein neues Composer-Paket nötig, aber Abhängigkeit von einem externen Systemprogramm, das auf dem Produktionsserver installiert sein muss (nicht garantiert, höheres Betriebsrisiko).
2. **PHP-PDF-Bibliothek als Composer-Abhängigkeit** (z.B. `dompdf/dompdf` oder `mpdf/mpdf` — reine PHP-Lösungen ohne Systemabhängigkeiten, ziehen aber zusätzliche Composer-Pakete nach sich, was dem bisherigen Minimalismus-Stil der Suggest-Sektion entgegenläuft, dafür aber zuverlässiger auf jedem Server läuft ohne externe Programme).

**Empfehlung**: Option 2 (z.B. `dompdf/dompdf`), da sie ohne Server-Abhängigkeiten auskommt und das Deployment nicht verkompliziert — der Kompromiss bei den Abhängigkeiten ist vertretbar, weil `dompdf` selbst keine weiteren Fremdabhängigkeiten mitbringt.

## Aufwandsschätzung
**M/L** — Die Dateneinbindung ist einfach (bereits vorhandene Termindaten), der Aufwand liegt im Layout einer druckfreundlichen 12-Monats-Übersicht (Tabellenlayout mit variabler Zeilenhöhe je nach Terminanzahl pro Tag) und dem Testen der PDF-Bibliothek mit deutschen Umlauten/Schriftarten.

## Offene Fragen / Risiken
- Bei sehr terminreichen Monaten (viele Termine am selben Tag) wird eine klassische Monatsraster-Ansicht im PDF schnell unleserlich — ggf. Begrenzung auf die ersten N Termine pro Tag mit "+X weitere" Hinweis, analog zu einer typischen Kalender-UI.
- Lizenz der gewählten PDF-Bibliothek prüfen (dompdf: LGPL, unproblematisch für kommerzielle Nutzung).
- Laufzeit/Speicherverbrauch bei der PDF-Generierung für ein ganzes Jahr mit vielen Terminen — ggf. ein Timeout-Limit im Blick behalten.

## Out of Scope (v1)
- Versand des PDFs per E-Mail direkt aus der App.
- Speichern/Archivieren erzeugter PDFs auf dem Server.
- Individuell anpassbares PDF-Layout (Farben, Logo-Platzierung) durch den Nutzer.
