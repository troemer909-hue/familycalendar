<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();
$listId = (int) ($_POST['list_id'] ?? 0);
$assignedMemberId = !empty($_POST['assigned_member_id']) ? (int) $_POST['assigned_member_id'] : null;

$payload = [
    'title' => trim($_POST['title'] ?? ''),
    'assigned_member_id' => $assignedMemberId,
    'due_date' => trim($_POST['due_date'] ?? ''),
];

// Analog zu event_save.php: dieselbe repeat_freq/repeat_count/repeat_until-
// Formularlogik, zu derselben RRULE-Syntax zusammengebaut. "biweekly"
// (alle 2 Wochen) ist kein eigenes RRULE-FREQ, sondern FREQ=WEEKLY mit
// INTERVAL=2 - siehe RRule::expand().
$freqMap = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'biweekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'];
$intervalMap = ['biweekly' => 2];
$repeatFreq = $_POST['repeat_freq'] ?? 'none';

if ($repeatFreq !== 'none' && isset($freqMap[$repeatFreq])) {
    $endType = $_POST['repeat_end_type'] ?? 'count';
    $interval = $intervalMap[$repeatFreq] ?? 1;
    $intervalPart = $interval > 1 ? ";INTERVAL={$interval}" : '';

    if ($endType === 'until' && !empty($_POST['repeat_until'])) {
        $until = str_replace('-', '', $_POST['repeat_until']);
        $payload['recurrence_rule'] = "FREQ={$freqMap[$repeatFreq]}{$intervalPart};UNTIL={$until}";
    } else {
        $count = max(1, min(366, (int) ($_POST['repeat_count'] ?? 8)));
        $payload['recurrence_rule'] = "FREQ={$freqMap[$repeatFreq]}{$intervalPart};COUNT={$count}";
    }
}

$res = $client->request('POST', "/api/task-lists/{$listId}/tasks", $payload);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: task_list_view.php?id=' . $listId . '&error=' . urlencode($res['data']['error'] ?? 'Aufgabe konnte nicht hinzugefügt werden.'));
    exit;
}

header('Location: task_list_view.php?id=' . $listId);
exit;
