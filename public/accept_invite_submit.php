<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireCsrf();

$apiBaseUrl = trim($_POST['api_base_url'] ?? '');
$token = trim($_POST['token'] ?? '');
$password = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';

$redirectBack = function (string $message) use ($token): void {
    header('Location: accept_invite.php?token=' . urlencode($token) . '&error=' . urlencode($message));
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
$res = $client->request('POST', '/api/auth/invite/accept', [
    'token' => $token,
    'password' => $password,
]);

if (!$res['ok']) {
    $redirectBack($res['data']['error'] ?? 'Einladung konnte nicht angenommen werden.');
}

// Anders als beim Passwort-Reset liefert acceptInvite() direkt ein
// Auth-Token zurück - die Person muss sich nach dem Festlegen ihres
// Passworts nicht noch separat einloggen.
session_regenerate_id(true);
saveConfig(['api_base_url' => $apiBaseUrl, 'token' => $res['data']['token']]);
header('Location: calendar.php');
exit;
