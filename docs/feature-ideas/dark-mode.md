# Dark Mode

## Problem / Motivation
Die App ist aktuell ausschließlich hell gestaltet (`public/styles.css`, helle Flächen `#fff`/`#f4f5f7`). Abends am Handy oder auf einem dauerhaft angezeigten Küchen-Tablet blendet eine helle Oberfläche unnötig. Viele Nutzer erwarten heute, dass eine App sich an die Systemeinstellung (hell/dunkel) hält.

## Zielgruppe & Nutzen
Alle Nutzer, besonders abendliche/nächtliche Nutzung (z.B. kurz vor dem Schlafengehen einen Termin eintragen) und Dauerbetrieb auf einem Tablet (siehe `family-hub-kiosk-modus.md`), wo ein helles Display nachts störend wirkt.

## Funktionsumfang (v1)
- Automatisches Dark Mode via `prefers-color-scheme: dark` (keine manuelle Einstellung nötig für v1).
- Alle bestehenden Komponenten (Sidebar, Formulare, Kalenderraster, Event-Chips, Badges, Login-Seiten) bekommen ein dunkles Gegenstück.
- Farbkontrast bleibt WCAG-AA-tauglich (Text auf dunklem Grund).
- NICHT enthalten in v1: ein manueller Umschalter in der UI (kommt erst mit v2, falls gewünscht — reine `prefers-color-scheme`-Lösung deckt die meisten Fälle ab und braucht keine neue Einstellung/Persistenz).

## User Stories
- Als Nutzer mit systemweitem Dark Mode möchte ich, dass die App sich automatisch anpasst, damit ich abends nicht geblendet werde.
- Als Betreiber eines Kiosk-Tablets in der Küche möchte ich die App nachts in einem gedämpften Farbschema zeigen können, damit sie nicht wie eine Lichtquelle wirkt.
- Als Nutzer möchte ich, dass alle Seiten (nicht nur der Kalender) im Dark Mode konsistent aussehen, damit es sich nicht wie ein halbfertiges Feature anfühlt.

## Datenmodell
Keine Datenbankänderung nötig — Dark Mode ist rein eine CSS-/Darstellungsfrage, es gibt keinen nutzerspezifischen Zustand zu speichern (v1 folgt ausschließlich der Systemeinstellung des Geräts, keine Server-seitige Präferenz).

## API-Endpunkte
Keine neuen Endpunkte nötig.

## UI/UX
- `public/styles.css` bekommt einen zweiten Satz CSS-Variablen innerhalb eines `@media (prefers-color-scheme: dark) { :root { ... } }`-Blocks, der die bestehenden Variablen (`--navy`, `--teal`, `--teal-2`, `--warm-1/2/3`, `--muted`) sowie neue, bisher hart codierte Hex-Werte (z.B. `#f4f5f7` Seitenhintergrund, `#fff` Kartenhintergrund, `#1f2430` Textfarbe, `#e5e7ec` Rahmenfarben) überschreibt.
- Jede Stelle im CSS, die aktuell einen harten Hex-Wert statt einer Variable nutzt (z.B. `.sidebar { background: #fff; }`, `.calendar-day { background: #fff; }`), muss schrittweise auf CSS-Variablen umgestellt werden, damit der Dark-Mode-Block sie zentral überschreiben kann — das ist der eigentliche Arbeitsaufwand, nicht das Hinzufügen des Media-Query-Blocks selbst.
- Besondere Prüfung nötig: Formular-Inputs (`input[type=text]` etc., aktuell `border: 1px solid #d7dae1`), der warme Gradient-Hintergrund auf den Login-/Auth-Seiten (`.auth-panel`, `.auth-standalone` — funktioniert als farbiger Verlauf vermutlich auch im Dark Mode unverändert gut, da er schon kräftige Farben nutzt), sowie die Logo-SVG (`brandLogo()` in `src/Helpers.php`), die z.T. feste navy-Füllfarben für Konturen hat und auf dunklem Grund ggf. schlecht lesbar wird.
- `<meta name="theme-color">` (siehe `pwaHeadTags()` in `src/Helpers.php`, aktuell fest `#203650`) könnte zusätzlich dynamisch per `media`-Attribut für hell/dunkel gesetzt werden, damit die Status-/Adressleiste des Browsers mitzieht.

## Abhängigkeiten & Integration
Betrifft ausschließlich `public/styles.css` und ggf. `src/Helpers.php` (`pwaHeadTags()`, `brandLogo()`). Keine Rückwirkung auf Backend/API. Sollte nach Möglichkeit VOR größeren neuen UI-Features (Aufgabenliste, Einkaufsliste etc.) gebaut werden, da sonst jedes neue Feature zusätzlich nachträglich für Dark Mode angepasst werden müsste.

## Aufwandsschätzung
**M** — Technisch einfach (ein `@media`-Block), aber der Umstellungsaufwand liegt im Durcharbeiten aller ca. 400+ Zeilen `styles.css` und aller ~20 PHP-Templates, um sicherzustellen, dass wirklich jede Komponente (inkl. Fehlermeldungen, Badges, der Sidebar-Navigation aus dem Branding-Umbau) im Dark Mode lesbar bleibt. Vergleichbar mit dem Aufwand des bereits durchgeführten Marketing-Branding-Umbaus (PR "Branding an Marketing-Webseite angleichen").

## Offene Fragen / Risiken
- Soll es zusätzlich einen manuellen Umschalter geben (v2), z.B. für Nutzer, deren Betriebssystem keinen Dark Mode unterstützt oder die bewusst abweichen wollen? Bräuchte dann doch eine Persistenz (Cookie oder `notification_preferences`-ähnliche neue Spalte).
- Das Logo (`brandLogo()`) hat feste Farben (Kalender-Icon-Gradient, navy Konturen) — im Dark Mode ggf. auf einem leicht helleren Kartenhintergrund statt direkt auf Schwarz platzieren, damit es nicht untergeht.
- Druckansicht (siehe `druckansicht-wochenplan.md`) sollte NIE im Dark Mode drucken (Tinte sparen) — `@media print`-Regeln müssen Vorrang vor `prefers-color-scheme: dark` haben, das ist in CSS mit korrekter Regel-Reihenfolge/Spezifität sicherzustellen.

## Out of Scope (v1)
- Manueller Dark-Mode-Umschalter in der UI.
- Pro-Nutzer-Präferenz-Speicherung in der Datenbank.
- Mehrere Farbthemen zur Auswahl (nur hell/dunkel, keine weiteren Varianten).
