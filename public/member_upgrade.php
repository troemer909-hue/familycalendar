<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireLogin();
requireCsrf();

$client = currentApiClient();

$id = (int) ($_POST['id'] ?? 0);
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (!$id || $email === '' || strlen($password) < 8) {
    header('Location: member_upgrade_form.php?id=' . $id . '&error=' . urlencode('E-Mail und Passwort (min. 8 Zeichen) erforderlich.'));
    exit;
}

$res = $client->request('POST', "/api/members/{$id}/upgrade", [
    'email' => $email,
    'password' => $password,
]);
handleUnauthorized($res);

if (!$res['ok']) {
    header('Location: member_upgrade_form.php?id=' . $id . '&error=' . urlencode($res['data']['error'] ?? 'Upgrade fehlgeschlagen.'));
    exit;
}

header('Location: members.php');
exit;
