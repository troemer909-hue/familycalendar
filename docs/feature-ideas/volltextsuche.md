# Volltextsuche

## Problem / Motivation
`calendar.php` lädt aktuell nur Termine im sichtbaren Zeitraum der jeweiligen Ansicht (Monat/Woche/Tag, siehe die `rangeFrom`/`rangeTo`-Logik). Es gibt keine Möglichkeit, gezielt nach einem Termin zu suchen ("Wann war nochmal der Zahnarzttermin von Lena?"), ohne manuell durch Monate zu blättern. Mit wachsender Terminhistorie wird das zunehmend unpraktisch.

## Zielgruppe & Nutzen
Alle Nutzer, besonders bei der Suche nach vergangenen Terminen (z.B. für Erinnerungen "wann war das letzte Mal...") oder bei vielen parallel laufenden wiederkehrenden Terminen, bei denen das Scrollen durch Monate mühsam ist.

## Funktionsumfang (v1)
- Ein Suchfeld (z.B. in der Sidebar oder Toolbar von `calendar.php`) durchsucht Titel, Beschreibung und Ort aller Termine der eigenen Familie — unabhängig vom aktuell sichtbaren Zeitraum.
- Ergebnisliste zeigt Termin-Titel, Datum und einen kurzen Ausschnitt, verlinkt zum jeweiligen Termin (bzw. zur Tagesansicht des entsprechenden Datums).
- Suche berücksichtigt die bestehende Mandanten-Trennung (`family_id`/`TenantContext`) strikt — niemals Termine anderer Familien in Ergebnissen.

## User Stories
- Als Nutzer möchte ich "Zahnarzt" eingeben und alle vergangenen und zukünftigen Zahnarzttermine der Familie sehen, ohne manuell zu blättern.
- Als Nutzer möchte ich aus den Suchergebnissen direkt zum jeweiligen Tag im Kalender springen können.
- Als Nutzer mit vielen Terminen möchte ich, dass die Suche auch bei Hunderten von Einträgen schnell antwortet.

## Datenmodell
- Keine neue Tabelle nötig — die Suche arbeitet auf der bestehenden `events`-Tabelle (Spalten `title`, `description`, `location`).
- Für performante Suche bei wachsender Terminzahl: MySQL `FULLTEXT`-Index auf `events(title, description, location)` statt einfacher `LIKE '%...%'`-Abfrage (die bei großen Tabellen keinen Index nutzen kann und mit steigender Terminzahl langsamer wird). `FULLTEXT` erfordert MyISAM oder InnoDB mit MySQL ≥ 5.6 (im Projekt bereits MySQL 8.0 laut CI-Service-Container, also unproblematisch).
- Migration über das bestehende `schema_migrations`-System.

## API-Endpunkte
- `GET /api/events/search?q=...` — neuer Endpunkt ohne Datumsfilter, durchsucht ausschließlich Termine der eigenen Familie (per `TenantContext`), begrenzt auf eine sinnvolle Ergebnisanzahl (z.B. 50) mit Relevanz-Sortierung (MySQL `MATCH ... AGAINST` liefert bereits eine Relevanz-Score).

## UI/UX
- Suchfeld in der Sidebar (oberhalb oder unterhalb der Mitglieder-Liste) oder in der Toolbar neben der Monatsnavigation.
- Ergebnisse erscheinen als Dropdown/Overlay unterhalb des Suchfelds (kein Seitenwechsel nötig für die erste Interaktion), mit Klick-Navigation zum Termin.
- Leere Suche oder < 2 Zeichen: keine Anfrage auslösen (vermeidet unnötige Last bei jedem Tastendruck); Debounce von z.B. 300ms beim Tippen.
- Da das Projekt laut README bewusst auf JavaScript außer für Push-Benachrichtigungen verzichtet, zwei Optionen: (a) Suche als klassisches Formular mit eigener Ergebnisseite (kein JS nötig, einfachster Weg, passt zum bestehenden Stil), oder (b) kleines dediziertes JS für Live-Ergebnisse ohne Seitenwechsel (Komfortgewinn, aber Abweichung vom bisherigen Prinzip). Empfehlung für v1: Option (a), analog zu den übrigen Formularseiten.

## Abhängigkeiten & Integration
- Neue Migration für den `FULLTEXT`-Index.
- Neuer API-Controller-Endpunkt, neue Such-Ergebnisseite im Client (`public/search.php` oder ähnlich), Einbindung des Suchfelds in `calendar.php`-Sidebar.
- Keine neuen Composer-Abhängigkeiten nötig (reine SQL-Funktion).

## Aufwandsschätzung
**S/M** — Der Kern (Endpunkt + einfache Ergebnisliste) ist klein; etwas Mehraufwand durch den Migrationsschritt und die Entscheidung zwischen klassischer Formular-Suche und Live-Suche.

## Offene Fragen / Risiken
- Soll die Suche auch nach Teilnehmernamen suchen (z.B. "Lena" findet alle Termine, an denen Lena als Teilnehmer markiert ist, auch wenn ihr Name nicht im Titel steht)? Würde einen JOIN über `event_participants`/`family_members` statt reinem `FULLTEXT` auf `events` erfordern — für v1 bewusst ausgeklammert, um den Endpunkt einfach zu halten.
- Bei kurzen/häufigen Suchbegriffen (z.B. "Termin") könnte die Ergebnisliste unübersichtlich lang werden — Begrenzung auf 50 Ergebnisse mit Hinweis "weitere Ergebnisse vorhanden, bitte genauer eingeben" einplanen.
- `FULLTEXT`-Suche auf Deutsch hat Eigenheiten (z.B. Mindestwortlänge, Stopwords) — kurze Suchbegriffe wie "KFZ" könnten je nach MySQL-Konfiguration nicht gefunden werden; ggf. `ngram`-Parser statt Standard-Parser in Betracht ziehen.

## Out of Scope (v1)
- Suche nach Teilnehmernamen.
- Fuzzy-Suche/Tippfehler-Toleranz über das hinaus, was MySQL `FULLTEXT` von sich aus bietet.
- Live-Suche per JavaScript ohne Seitenwechsel (v1 nutzt klassisches Formular).
