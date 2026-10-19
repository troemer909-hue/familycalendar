<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);
$reminderMinutes = !empty($_POST['reminder_minutes']) ? (int) $_POST['reminder_minutes'] : null;
$color = trim($_POST['color'] ?? '');

if (!$id) {
    header('Location: calendar_sources.php?error=' . urlencode('Quelle nicht gefunden.'));
    exit;
}

$payload = ['reminder_minutes' => $reminderMinutes];
if ($color !== '') {
    $payload['color'] = $color;
}

$res = $client->request('PUT', "/api/calendar-sources/{$id}", $payload);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: calendar_sources.php?error=' . urlencode($res['data']['error'] ?? 'Speichern fehlgeschlagen.'));
    exit;
}

header('Location: calendar_sources.php?notice=' . urlencode('Quelle aktualisiert.'));
exit;
