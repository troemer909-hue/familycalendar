<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$eventId = (int) ($_POST['event_id'] ?? 0);
$minutes = (int) ($_POST['remind_before_minutes'] ?? 30);
$backMonth = $_POST['back_month'] ?? date('Y-m');

$redirectBack = function (string $message) use ($eventId, $backMonth): void {
    header('Location: event_form.php?id=' . $eventId . '&back_month=' . urlencode($backMonth) . '&error=' . urlencode($message));
    exit;
};

if (!$eventId) {
    $redirectBack('Ungültiger Termin.');
}

$res = $client->request('POST', "/api/events/{$eventId}/reminders", ['remind_before_minutes' => $minutes]);
handleUnauthorized($res);

if (!$res['ok']) {
    $redirectBack($res['data']['error'] ?? 'Erinnerung konnte nicht angelegt werden.');
}

header('Location: event_form.php?id=' . $eventId . '&back_month=' . urlencode($backMonth));
exit;
