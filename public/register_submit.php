<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireCsrf();

$apiBaseUrl = defaultApiBaseUrl();
$familyName = trim($_POST['family_name'] ?? '');
$adminName = trim($_POST['admin_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

saveConfig(['api_base_url' => $apiBaseUrl, 'token' => null]);

if ($apiBaseUrl === '' || $familyName === '' || $adminName === '' || $email === '' || strlen($password) < 8) {
    header('Location: register.php?error=' . urlencode('Bitte alle Felder ausfüllen (Passwort min. 8 Zeichen).'));
    exit;
}

$client = new ApiClient($apiBaseUrl, null);
$res = $client->request('POST', '/api/auth/register', [
    'family_name' => $familyName,
    'admin_name' => $adminName,
    'email' => $email,
    'password' => $password,
]);

if (!$res['ok']) {
    $message = $res['data']['error'] ?? 'Registrierung fehlgeschlagen.';
    header('Location: register.php?error=' . urlencode($message));
    exit;
}

session_regenerate_id(true);
saveConfig(['api_base_url' => $apiBaseUrl, 'token' => $res['data']['token']]);
header('Location: calendar.php');
exit;
