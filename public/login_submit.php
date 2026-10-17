<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireCsrf();

$apiBaseUrl = defaultApiBaseUrl();
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($apiBaseUrl === '' || $email === '' || $password === '') {
    header('Location: login.php?error=' . urlencode('Bitte E-Mail und Passwort ausfüllen.'));
    exit;
}

saveConfig(['api_base_url' => $apiBaseUrl, 'token' => null]);

$client = new ApiClient($apiBaseUrl, null);
$res = $client->request('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);

if (!$res['ok']) {
    $message = $res['data']['error'] ?? 'Anmeldung fehlgeschlagen.';
    header('Location: login.php?error=' . urlencode($message));
    exit;
}

session_regenerate_id(true);
saveConfig([
    'api_base_url' => $apiBaseUrl,
    'token' => $res['data']['token'],
    'is_platform_admin' => $res['data']['is_platform_admin'] ?? false,
]);
header('Location: calendar.php');
exit;
