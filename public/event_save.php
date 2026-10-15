<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
$backMonth = $_POST['back_month'] ?? date('Y-m');

$title = trim($_POST['title'] ?? '');
$startsAt = str_replace('T', ' ', $_POST['starts_at'] ?? '') . ':00';
$endsAt = str_replace('T', ' ', $_POST['ends_at'] ?? '') . ':00';
$scope = $_POST['scope'] ?? 'personal';
$participantIds = array_map('intval', $_POST['participant_ids'] ?? []);

$redirectBack = function (string $message) use ($id, $backMonth): void {
    $target = $id ? "event_form.php?id={$id}&back_month=" . urlencode($backMonth)
                  : 'event_form.php?back_month=' . urlencode($backMonth);
    header('Location: ' . $target . '&error=' . urlencode($message));
    exit;
};

if ($title === '' || empty($_POST['starts_at']) || empty($_POST['ends_at'])) {
    $redirectBack('Titel, Beginn und Ende sind erforderlich.');
}
if ($scope === 'personal' && empty($participantIds)) {
    $redirectBack('Bitte mindestens eine Person auswählen.');
}

$payload = [
    'title' => $title,
    'starts_at' => $startsAt,
    'ends_at' => $endsAt,
    'all_day' => isset($_POST['all_day']),
    'scope' => $scope,
    'participant_ids' => $participantIds,
    'location' => trim($_POST['location'] ?? '') ?: null,
    'category' => trim($_POST['category'] ?? '') ?: null,
    'description' => trim($_POST['description'] ?? '') ?: null,
];

// Wiederholung gilt NUR beim Neu-Anlegen (keine nachträgliche Änderung der
// Serie) - bei $id (Bearbeiten) wird recurrence_rule absichtlich gar nicht
// mitgeschickt, damit EventController::update() sie unangetastet lässt.
if (!$id) {
    $freqMap = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'];
    $repeatFreq = $_POST['repeat_freq'] ?? 'none';

    if ($repeatFreq !== 'none' && isset($freqMap[$repeatFreq])) {
        $endType = $_POST['repeat_end_type'] ?? 'count';

        if ($endType === 'until' && !empty($_POST['repeat_until'])) {
            $until = str_replace('-', '', $_POST['repeat_until']); // YYYY-MM-DD -> YYYYMMDD
            $payload['recurrence_rule'] = "FREQ={$freqMap[$repeatFreq]};UNTIL={$until}";
        } else {
            $count = max(1, min(366, (int) ($_POST['repeat_count'] ?? 12)));
            $payload['recurrence_rule'] = "FREQ={$freqMap[$repeatFreq]};COUNT={$count}";
        }
    }
}

$res = $id
    ? $client->request('PUT', "/api/events/{$id}", $payload)
    : $client->request('POST', '/api/events', $payload);

handleUnauthorized($res);

if (!$res['ok']) {
    $redirectBack($res['data']['error'] ?? 'Speichern fehlgeschlagen.');
}

header('Location: calendar.php?month=' . urlencode($backMonth));
exit;
