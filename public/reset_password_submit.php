<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireCsrf();

$apiBaseUrl = trim($_POST['api_base_url'] ?? '');
$token = trim($_POST['token'] ?? '');
$password = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';

$redirectBack = function (string $message) use ($token): void {
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=' . urlencode($message));
    exit;
};

if ($apiBaseUrl === '' || $token === '' || strlen($password) < 8) {
    $redirectBack('Bitte API-Adresse, Code und ein Passwort (min. 8 Zeichen) angeben.');
}
if ($password !== $passwordConfirm) {
    $redirectBack('Die beiden Passwörter stimmen nicht überein.');
}

saveConfig(['api_base_url' => $apiBaseUrl]);

$client = new ApiClient($apiBaseUrl, null);
$res = $client->request('POST', '/api/auth/password-reset/confirm', [
    'token' => $token,
    'password' => $password,
]);

if (!$res['ok']) {
    $redirectBack($res['data']['error'] ?? 'Passwort konnte nicht geändert werden.');
}

header('Location: login.php?notice=' . urlencode('Passwort geändert. Bitte neu anmelden.'));
exit;
