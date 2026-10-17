# Druckansicht Wochenplan

## Problem / Motivation
Viele Familien hängen einen Wochenplan an den Kühlschrank oder die Pinnwand,
damit auch Mitglieder ohne Smartphone (jüngere Kinder, Großeltern zu Besuch)
auf einen Blick sehen, was diese Woche anliegt. Die App bietet aktuell nur
die Bildschirm-Wochenansicht (`calendar.php?view=week`), die weder auf
Papier optimiert ist (Sidebar, Navigation, Farben auf hellem Hintergrund)
noch sich sauber ausdrucken lässt.

## Zielgruppe & Nutzen
Alle Familien, insbesondere mit kleinen Kindern oder Mitgliedern, die
keinen regelmäßigen App-Zugriff haben. Nutzen: ein optisch klarer, auf A4
optimierter Ausdruck, der ohne Weiteres an den Kühlschrank passt und auch
ohne Internetzugang lesbar bleibt.

## Funktionsumfang (v1)
- Eigene Druckansicht für eine gewählte Kalenderwoche, erreichbar über
  einen "Drucken"-Link in der bestehenden Wochenansicht.
- Layout für A4 (Hoch- oder Querformat), 7 Spalten (Mo-So) oder 7 Zeilen,
  mit Termintitel, Uhrzeit und zuständigem Mitglied (Farbe/Initiale statt
  vollem Foto, da s/w-Druck häufig ist).
- Nutzung des Browser-eigenen Druckdialogs (`window.print()` bzw. direkt
  `@media print`-CSS auf einer eigenständigen, schlanken HTML-Seite ohne
  Sidebar/Navigation) - kein PDF-Rendering auf dem Server nötig.
- Auswahl, welche Mitglieder/Kategorien auf dem Ausdruck erscheinen sollen
  (z.B. nur die Termine der Kinder, nicht die der Eltern).

## User Stories
- Als Elternteil möchte ich die aktuelle Woche mit einem Klick ausdrucken,
  um sie an den Kühlschrank zu hängen.
- Als Nutzer möchte ich auf dem Ausdruck nur die Termine bestimmter
  Familienmitglieder sehen, nicht den ganzen Familienkalender.
- Als Nutzer möchte ich, dass der Ausdruck auch in Schwarz-Weiß gut lesbar
  bleibt (nicht nur über Farbcodierung unterscheidbar).

## Datenmodell
Keine Schemaänderung nötig - reine Präsentationsschicht auf den
bestehenden `/api/events`-Daten für den gewählten Zeitraum.

## API-Endpunkte
Kein neuer Endpunkt nötig; die Druckseite nutzt denselben
`GET /api/events?from=...&to=...`-Abruf, den auch `calendar.php` bereits
verwendet, nur mit anderer Darstellung.

## UI/UX
- Neue Seite `week_print.php`, aufgerufen mit demselben Wochenbezug wie
  `calendar.php?view=week&date=...`, aber ohne `app-layout`/Sidebar - nur
  die reine Tabelle plus ein eigenes, druckoptimiertes `<link>` auf eine
  `print.css` (`@media print { .no-print { display:none; } }` für den
  "Drucken"-Button selbst und etwaige Navigationselemente).
- In der bestehenden Wochenansicht (`calendar.php`) einen unauffälligen
  "Drucken"-Link im Toolbar-Bereich ergänzen, der zu `week_print.php` mit
  dem aktuell angezeigten Zeitraum verlinkt.
- Checkbox-Filter für Mitglieder/Kategorien direkt auf `week_print.php`
  als GET-Parameter (`?members=2,5`), damit der gefilterte Zustand auch
  beim tatsächlichen Druck erhalten bleibt (kein JS-Zustand, der beim
  Drucken verloren ginge).

## Abhängigkeiten & Integration
- Reine Erweiterung im Client (`public/`), keine Änderung an
  `EventController` oder der API nötig.
- Nutzt dieselbe Session-/Auth-Logik (`requireLogin()`) wie jede andere
  Client-Seite.

## Aufwandsschätzung
**S** - Reine Darstellungsschicht ohne neue Datenbank- oder API-Logik;
im Wesentlichen eine zusätzliche Template-Seite plus Druck-CSS.

## Offene Fragen / Risiken
- Soll es auch eine Monats-Druckansicht geben, oder reicht v1 mit nur der
  Woche?
- Wie weit soll die Mitglieder-/Kategorien-Filterung gehen - reicht eine
  einfache Checkbox-Auswahl, oder sollen auch ganztägige vs. zeitgebundene
  Termine getrennt filterbar sein?

## Out of Scope (v1)
- Serverseitige PDF-Generierung (z.B. via wkhtmltopdf/Headless-Chrome) -
  v1 verlässt sich bewusst auf den Browser-Druckdialog, um ohne weitere
  Server-Abhängigkeit auszukommen.
- Versand des Wochenplans als E-Mail-Anhang (siehe stattdessen das
  separate PDF-Jahresplaner-Feature für eine serverseitige PDF-Variante).
- Individuelle Layout-Anpassung (Schriftgröße, Spaltenbreite) durch den
  Nutzer selbst.
