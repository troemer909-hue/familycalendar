<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$result = currentApiClient()->request('DELETE', '/api/alexa/link');
handleUnauthorized($result);
if (!$result['ok']) {
    header('Location: notification_settings.php?error=' . urlencode($result['data']['error'] ?? 'Alexa-Verknüpfung konnte nicht gelöst werden.'));
    exit;
}
header('Location: notification_settings.php?saved=1');
exit;
