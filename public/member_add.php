<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$name = trim($_POST['display_name'] ?? '');
$color = trim($_POST['display_color'] ?? '') ?: '#94a3b8';
$birthYear = !empty($_POST['birth_year']) ? (int) $_POST['birth_year'] : null;

if ($name === '') {
    header('Location: members.php?error=' . urlencode('Bitte einen Namen eingeben.'));
    exit;
}

$res = $client->request('POST', '/api/members', [
    'display_name' => $name,
    'display_color' => $color,
    'birth_year' => $birthYear,
]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: members.php?error=' . urlencode($res['data']['error'] ?? 'Profil anlegen fehlgeschlagen.'));
    exit;
}

header('Location: members.php');
exit;
