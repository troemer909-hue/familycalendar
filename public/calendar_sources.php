<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$sourcesRes = $client->request('GET', '/api/calendar-sources');
handleUnauthorized($sourcesRes);
$sources = $sourcesRes['ok'] ? $sourcesRes['data'] : [];

$membersRes = $client->request('GET', '/api/members');
handleUnauthorized($membersRes);
$members = $membersRes['ok'] ? $membersRes['data'] : [];

$error = $_GET['error'] ?? null;
$notice = $_GET['notice'] ?? null;

$typeLabels = [
    'import_ics' => 'ICS-Import (einmalig)',
    'import_csv' => 'CSV-Import (einmalig)',
    'stream_ics' => 'ICS-Abo (läuft weiter)',
];
$statusLabels = [
    'active' => 'Aktiv',
    'error' => 'Fehler',
    'paused' => 'Pausiert',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Kalenderquellen</title>
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<?= appChromeOpen('sources') ?>
  <div class="form-card" style="max-width:720px;">
    <h1>Kalenderquellen</h1>
    <p class="hint-text">
      Importiere Termine aus einer .ics- oder .csv-Datei (einmalig) oder
      abonniere einen laufenden ICS-Link (z.B. Schule, Verein, Google/Outlook-
      Freigabe) - der wird regelmäßig automatisch nachgezogen.
    </p>

    <?php if ($error): ?><p class="error-box"><?= h($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="error-box" style="background:#dcfce7;color:#166534;"><?= h($notice) ?></p><?php endif; ?>

    <h2 style="font-size:16px;">Bestehende Quellen</h2>
    <?php if (empty($sources)): ?>
      <p class="hint-text">Noch keine externe Kalenderquelle eingerichtet.</p>
    <?php else: ?>
      <div class="members-list">
        <?php foreach ($sources as $s): ?>
          <div class="member-row" style="align-items:flex-start;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
              <strong><?= h($s['name']) ?></strong><br />
              <span class="hint-text">
                <?= h($typeLabels[$s['type']] ?? $s['type']) ?>
                <?php if ($s['type'] === 'stream_ics'): ?>
                  · alle <?= (int) $s['sync_interval_minutes'] ?> Min.
                <?php endif; ?>
                · <?= h($statusLabels[$s['status']] ?? $s['status']) ?>
                <?php if ($s['last_synced_at']): ?>
                  · zuletzt synchronisiert: <?= h(formatGermanDateTime($s['last_synced_at'])) ?>
                <?php endif; ?>
              </span>
            </div>
            <div style="display:flex;gap:8px;">
              <?php if ($s['type'] === 'stream_ics'): ?>
                <form method="post" action="calendar_source_sync.php" style="margin:0;">
                  <?= csrfField() ?>
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>" />
                  <button type="submit" class="btn btn-secondary">Jetzt synchronisieren</button>
                </form>
              <?php endif; ?>
              <form method="post" action="calendar_source_delete.php" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>" />
                <button type="submit" class="btn btn-danger">Entfernen</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 style="font-size:16px;margin-top:28px;">.ics-Datei importieren (einmalig)</h2>
    <form method="post" action="calendar_source_import_ics.php" enctype="multipart/form-data">
      <?= csrfField() ?>
      <label>Name der Quelle</label>
      <input type="text" name="name" placeholder="z.B. Schulferien 2026" required />
      <label>.ics-Datei</label>
      <input type="file" name="ics_file" accept=".ics,text/calendar" required />
      <label>Nur für eine Person eintragen (optional, sonst ganze Familie)</label>
      <select name="family_member_id">
        <option value="">Ganze Familie</option>
        <?php foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>"><?= h($m['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Importieren</button>
      </div>
    </form>

    <h2 style="font-size:16px;margin-top:28px;">.csv-Datei importieren (einmalig)</h2>
    <p class="hint-text">Spalten: Titel, Beginn, Ende (Pflicht), optional Beschreibung, Ort, Ganztägig.</p>
    <form method="post" action="calendar_source_import_csv.php" enctype="multipart/form-data">
      <?= csrfField() ?>
      <label>Name der Quelle</label>
      <input type="text" name="name" placeholder="z.B. Trainingsplan Verein" required />
      <label>.csv-Datei</label>
      <input type="file" name="csv_file" accept=".csv,text/csv" required />
      <label>Nur für eine Person eintragen (optional, sonst ganze Familie)</label>
      <select name="family_member_id">
        <option value="">Ganze Familie</option>
        <?php foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>"><?= h($m['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Importieren</button>
      </div>
    </form>

    <h2 style="font-size:16px;margin-top:28px;">Laufendes ICS-Abo einrichten</h2>
    <p class="hint-text">Für einen ICS-Link, der sich ändert (z.B. Vereins- oder Google-Kalender-Freigabe) - wird automatisch alle X Minuten neu geladen.</p>
    <form method="post" action="calendar_source_stream.php">
      <?= csrfField() ?>
      <label>Name der Quelle</label>
      <input type="text" name="name" placeholder="z.B. Fußballverein Trainingsplan" required />
      <label>ICS-Adresse (URL)</label>
      <input type="text" name="source_url" placeholder="https://... oder webcal://..." required />
      <label>Sync-Intervall (Minuten)</label>
      <input type="number" name="sync_interval_minutes" value="60" min="5" />
      <label>Nur für eine Person eintragen (optional, sonst ganze Familie)</label>
      <select name="family_member_id">
        <option value="">Ganze Familie</option>
        <?php foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>"><?= h($m['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Abo einrichten</button>
        <div class="spacer"></div>
        <a href="calendar.php" class="btn btn-secondary">Zurück zum Kalender</a>
      </div>
    </form>
  </div>
<?= appChromeClose('sources') ?>
</body>
</html>
