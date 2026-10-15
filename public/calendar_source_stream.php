<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();

$client = currentApiClient();

$name = trim($_POST['name'] ?? '');
$sourceUrl = trim($_POST['source_url'] ?? '');
$interval = (int) ($_POST['sync_interval_minutes'] ?? 60);
$familyMemberId = !empty($_POST['family_member_id']) ? (int) $_POST['family_member_id'] : null;

if ($name === '' || $sourceUrl === '') {
    header('Location: calendar_sources.php?error=' . urlencode('Bitte Name und ICS-Adresse angeben.'));
    exit;
}

$res = $client->request('POST', '/api/calendar-sources/stream', [
    'name' => $name,
    'source_url' => $sourceUrl,
    'sync_interval_minutes' => $interval,
    'family_member_id' => $familyMemberId,
]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: calendar_sources.php?error=' . urlencode($res['data']['error'] ?? 'Abo konnte nicht eingerichtet werden.'));
    exit;
}

header('Location: calendar_sources.php?notice=' . urlencode('Abo eingerichtet und einmal synchronisiert.'));
exit;
