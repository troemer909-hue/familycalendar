<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB reicht für einen Jahreskalender locker

$client = currentApiClient();

$name = trim($_POST['name'] ?? '');
$familyMemberId = !empty($_POST['family_member_id']) ? (int) $_POST['family_member_id'] : null;

$redirectError = function (string $message): void {
    header('Location: calendar_sources.php?error=' . urlencode($message));
    exit;
};

if ($name === '') {
    $redirectError('Bitte einen Namen für die Quelle angeben.');
}

$file = $_FILES['ics_file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    $redirectError('Bitte eine .ics-Datei auswählen.');
}
if ($file['size'] > MAX_UPLOAD_BYTES) {
    $redirectError('Die Datei ist zu groß (max. 5 MB).');
}

$content = file_get_contents($file['tmp_name']);
if ($content === false || $content === '') {
    $redirectError('Die Datei konnte nicht gelesen werden.');
}

$res = $client->request('POST', '/api/calendar-sources/import-ics', [
    'name' => $name,
    'ics_content' => $content,
    'family_member_id' => $familyMemberId,
]);
handleUnauthorized($res);

if (!$res['ok']) {
    $redirectError($res['data']['error'] ?? 'Import fehlgeschlagen.');
}

header('Location: calendar_sources.php?notice=' . urlencode(
    ($res['data']['imported'] ?? 0) . ' Termine importiert.'
));
exit;
