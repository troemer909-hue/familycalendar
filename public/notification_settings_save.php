<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$body = [
    'own_events' => isset($_POST['own_events']),
    'family_events' => isset($_POST['family_events']),
    'channel_mail' => isset($_POST['channel_mail']),
    'channel_push' => isset($_POST['channel_push']),
    'reminder_default_minutes' => (int) ($_POST['reminder_default_minutes'] ?? 30),
];

$res = $client->request('PUT', '/api/notification-preferences', $body);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: notification_settings.php?error=' . urlencode($res['data']['error'] ?? 'Einstellungen konnten nicht gespeichert werden.'));
    exit;
}

header('Location: notification_settings.php?saved=1');
exit;
