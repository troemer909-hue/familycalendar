# Hausaufgaben-/Elternbrief-Ablage

## Problem / Motivation
Elternbriefe, Hausaufgabenzettel und ähnliche Papier-/PDF-Mitteilungen aus Schule oder Kita landen heute in Fotogalerien, E-Mail-Postfächern oder gar nicht digital — und sind beim passenden Termin (z.B. "Wandertag") nicht griffbereit. Ein Anhang direkt am Kalender-Termin macht die Information dort auffindbar, wo sie gebraucht wird.

## Zielgruppe & Nutzen
Eltern von Schul-/Kita-Kindern, die Dokumente (Einladungen, Hausaufgabenzettel, Einverständniserklärungen) einem Termin zuordnen wollen, statt sie separat zu verwalten. Nutzen: ein Blick auf den Termin zeigt sofort, ob und welches Dokument dazugehört.

## Funktionsumfang (v1)
- Eine Datei (Foto/PDF, max. ~5 MB) an einen bestehenden Termin anhängen.
- Anhänge werden in der Terminansicht/-bearbeitung als Liste mit Dateiname + Download-Link angezeigt.
- Ein Anhang kann gelöscht werden (nur durch den Ersteller des Termins oder einen Familien-Admin).
- Unterstützte Dateitypen v1: JPEG, PNG, PDF.
- Kein Inline-Vorschaubild, kein Editieren von Anhängen nach Upload (nur Ersetzen = Löschen + neu hochladen).

## User Stories
- Als Elternteil möchte ich ein Foto des Elternbriefs an den Termin "Wandertag" hängen, damit alle Infos an einem Ort sind.
- Als Elternteil möchte ich ein PDF mit Hausaufgaben an den passenden Tag hängen, damit mein Kind es wiederfindet.
- Als Familienmitglied möchte ich beim Öffnen eines Termins sofort sehen, ob ein Dokument dranhängt.
- Als Ersteller:in des Termins möchte ich einen versehentlich hochgeladenen Anhang wieder löschen können.

## Datenmodell
Neue Tabelle `event_attachments`:

```sql
CREATE TABLE IF NOT EXISTS event_attachments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  event_id INT NOT NULL,
  uploaded_by INT NULL,              -- family_members.id, NULL falls Mitglied später gelöscht
  file_name VARCHAR(255) NOT NULL,   -- Original-Dateiname, zur Anzeige
  storage_path VARCHAR(255) NOT NULL, -- relativer Pfad unterhalb eines geschützten Upload-Verzeichnisses
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES family_members(id) ON DELETE SET NULL,
  INDEX idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Wichtig: `storage_path` verweist NICHT auf einen öffentlich erreichbaren Pfad unterhalb von `public/` — Dateien müssen über einen authentifizierten API-Endpunkt ausgeliefert werden (siehe unten), sonst wäre jeder Anhang per erratenem Dateinamen für jeden im Internet abrufbar, Mandantentrennung wäre umgangen.

## API-Endpunkte
- `POST /api/events/{id}/attachments` — Multipart-Upload, legt Datei auf dem Server ab (außerhalb des Document Roots, z.B. `storage/attachments/{family_id}/`) und Zeile in `event_attachments`. Prüft Dateigröße/MIME-Type serverseitig, nicht nur clientseitig.
- `GET /api/events/{id}/attachments` — Liste der Anhänge eines Termins (Metadaten, kein Dateiinhalt).
- `GET /api/attachments/{id}/download` — Liefert die Datei aus, NUR wenn der angefragte Nutzer zur selben `family_id` gehört wie der zugehörige Termin (TenantContext-Prüfung wie überall sonst).
- `DELETE /api/attachments/{id}` — löscht Datenbankzeile + Datei vom Server. Nur Ersteller:in des Termins oder Familien-Admin.

## UI/UX
- `event_form.php`: neuer Abschnitt "Anhänge" mit Datei-Auswahlfeld (`<input type="file">`) und Liste bereits hochgeladener Anhänge mit Lösch-Button je Zeile.
- Da die App bewusst ohne JavaScript auskommt (Ausnahme: Web Push, siehe README), muss der Upload über ein klassisches `<form method="post" enctype="multipart/form-data">` mit vollem Seiten-Reload laufen, nicht über eine AJAX-Fortschrittsanzeige — passt zum bestehenden Stil (z.B. `member_add.php`).
- In der Tages-/Monatsansicht (`calendar.php`) ein kleines Büroklammer-Icon neben Terminen mit Anhang, als visueller Hinweis.

## Abhängigkeiten & Integration
- Erstes Datei-Upload-Feature der App überhaupt — es gibt noch keine Konvention für Speicherort/-limits/Bereinigung. Diese Spec legt den Grundstein (Speicherort außerhalb `public/`, Auslieferung nur über authentifizierten Endpunkt).
- Betrifft `EventController` (neue Methoden) und ein neues `AttachmentController`.
- Löschen eines Termins muss `event_attachments` per `ON DELETE CASCADE` mitlöschen — zusätzlich müssen beim Löschen einer DB-Zeile auch die zugehörigen Dateien auf der Festplatte entfernt werden (sonst Datenleiche); das muss im Controller explizit passieren, da MySQL keine Dateisystem-Operationen auslösen kann.
- Hosting-Abhängigkeit: der Webserver-Prozess braucht Schreibrechte auf ein Upload-Verzeichnis — auf manchem Shared Hosting (siehe bisherige Erfahrungen mit Dateirechten/Ownership in diesem Projekt) ein möglicher Stolperstein.

## Aufwandsschätzung
**M bis L.** Deutlich aufwendiger als z.B. CalendarSources, weil es die erste Funktion mit echtem Dateisystem-Zugriff ist: Validierung (Dateigröße, MIME-Type-Spoofing-Schutz), Speicherort-Konvention, Zugriffskontrolle beim Ausliefern, Aufräumen beim Löschen, Speicherplatz-Limits pro Familie (sonst unbegrenztes Hochladen möglich) müssen alle neu durchdacht werden.

## Offene Fragen / Risiken
- Speicherplatz-Limit pro Familie/Plan? Ohne Begrenzung kann eine einzelne Familie den Server volladen.
- Virenscan der hochgeladenen Dateien? Für ein kleines Self-Hosted-Projekt vermutlich v1 bewusst weggelassen, aber als Risiko zu nennen.
- Soll es, wie bei Pledge/Billing, ein Limit in der `plans`-Tabelle geben (`max_attachment_mb` o.ä.)?
- Bildkomprimierung von Fotos vor dem Speichern (Smartphone-Fotos sind oft mehrere MB)?

## Out of Scope (v1)
- Inline-Bildvorschau/Thumbnail-Generierung.
- Versionierung von Anhängen (Ersetzen = Löschen + Neu-Upload).
- Anhänge an andere Objekte als Termine (z.B. an Familienmitglieder-Profile).
- Automatische Texterkennung (OCR) von Elternbriefen.
