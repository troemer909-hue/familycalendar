# Konzept: Alexa-Anbindung für den Family-Kalender

## Zielbild

Nutzer sollen per Sprachbefehl an ein Alexa-Gerät ("Alexa, frag Family-Kalender, was heute ansteht") Termine abfragen und (in Ausbaustufe 2) auch anlegen können — ohne Handy in der Hand. Realisiert als **Custom Alexa Skill mit Account-Linking**, nicht als Smart-Home-Skill (dafür ist ein Kalender kein Gerät).

## Architektur-Optionen

**Option A — Eigener HTTPS-Endpunkt (empfohlen):**
Alexa unterstützt neben AWS Lambda auch einen direkten HTTPS-Webservice als Skill-Backend. Passt zu dieser Codebasis (reines PHP, kein zusätzlicher AWS-Account nötig):

- Neue Route `POST /api/alexa/webhook` → `AlexaController`
- Amazon signiert jede Anfrage (Signatur-Zertifikat-Header) — Pflicht: Signaturprüfung server-seitig, sonst kann jeder beliebige Anfragen an den Endpunkt schicken und sich als Alexa ausgeben
- Läuft im bestehenden In-Process-Dispatch (`LocalApiClient`/Router) mit, kein separater Dienst nötig

**Option B — AWS Lambda:** Amazons Standardweg, aber zusätzliche Infrastruktur (AWS-Account, Lambda, ASK-SDK in Node/Python) außerhalb des bestehenden PHP-Stacks — mehr Betriebsaufwand für wenig Zusatznutzen hier. Nur sinnvoll, falls ohnehin AWS geplant ist.

→ **Option A passt besser zum bestehenden Ein-Server-Modell** (wie der Rest der App: reines PHP, kein zusätzlicher Dienst).

## Account-Linking (Auth)

Alexa-Skills mit Nutzerbezug brauchen OAuth2 Authorization-Code-Flow. Neu zu bauen:

- `GET /oauth/authorize` — Login-/Consent-Seite (kann die bestehende Login-UI wiederverwenden), leitet nach Login mit `code` an Alexa zurück
- `POST /oauth/token` — tauscht `code` gegen Access-Token; kann den bestehenden `Token`-Mechanismus (`src/Core/Token.php`) wiederverwenden, nur mit kürzerer Lebensdauer als die 10 Jahre der Web-Session (z.B. 1 Stunde + Refresh-Token), damit ein verlorenes Amazon-Konto nicht dauerhaft Zugriff behält
- Mapping-Tabelle `alexa_links` (user_id ↔ Alexa-Access-Token-Hash) — keine Änderung an `TenantContext`/Mandantentrennung nötig, Alexa-Requests laufen einfach mit dem verlinkten `user_id` durch dieselbe Middleware wie jeder andere API-Call

**Offene Entscheidung:** Bei mehreren Familienmitgliedern mit eigenem Login — wessen Account wird verlinkt? Vorschlag: das verlinkte Konto ist immer der jeweils einladende/anmeldende Nutzer selbst (1:1 wie bei jedem anderen Login), keine Mehrfachauswahl nötig.

## Voice-Interaction-Model (Intents)

**Phase 1 (nur lesen):**
- `GetTodayEventsIntent` — "was steht heute an"
- `GetTomorrowEventsIntent` — "was steht morgen an"
- `GetNextEventIntent` — "wann ist mein nächster Termin"

**Phase 2 (schreiben):**
- `AddEventIntent` mit Slots `{title}`, `{date}`, `{time}` — "trag {title} für {date} um {time} Uhr ein"
- `DeleteEventIntent` — "lösch meinen Termin {title}"

Jeder Intent mappt serverseitig 1:1 auf bestehende Controller-Methoden (`EventController::index()`/`store()`/`destroy()`) — kein neuer Business-Logic-Code, nur ein Übersetzer zwischen Alexa-JSON und den bestehenden API-Routen.

## Datenmodell

Keine Änderung an `events`/`family_members` nötig. Neu: eine schlanke `alexa_links`-Tabelle (user_id, access_token_hash, refresh_token_hash, expires_at) — passt ins bestehende Migrationssystem (`database/migrations/014_alexa_links.sql`).

## Sicherheit

- Signaturprüfung jeder eingehenden Alexa-Anfrage (Amazon-Zertifikatskette, Timestamp-Toleranz gegen Replay-Angriffe)
- Skill-ID-Abgleich (`context.System.application.applicationId`) gegen eine in der `.env` hinterlegte erwartete ID
- Kurzlebige Access-Tokens + Refresh-Token-Rotation statt der langen Web-Session-Lebensdauer

## Phasenplan & Aufwand (grobe Einschätzung)

| Phase | Umfang | Aufwand |
|---|---|---|
| 1 | Account-Linking (OAuth-Endpunkte) + Alexa-Entwicklerkonto/Skill-Grundgerüst | 2–3 Tage |
| 2 | Lese-Intents (heute/morgen/nächster Termin) inkl. Sprachmodell + Signaturprüfung | 2–3 Tage |
| 3 | Schreib-Intents (Termin anlegen/löschen) inkl. Slot-Validierung, Datum-Parsing | 3–4 Tage |
| 4 | Amazon-Zertifizierung (Skill-Review für Veröffentlichung im Skill-Store) | 1–2 Wochen Wartezeit, kaum eigener Aufwand |

**Business-seitig:** böte sich als Plus-Plan-exklusives Feature an, analog zu den freien Kategorien — passt zum bestehenden Tier-Gating-Muster (`requirePlusPlan()`).
