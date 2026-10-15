<?php
require_once __DIR__ . '/../src/bootstrap.php';

$apiBaseUrl = trim($_POST['api_base_url'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($apiBaseUrl === '' || $email === '' || $password === '') {
    header('Location: login.php?error=' . urlencode('Bitte alle Felder ausfüllen.'));
    exit;
}

// API-Adresse merken, BEVOR wir sie brauchen – auch wenn der Login scheitert,
// muss das Feld beim nächsten Versuch nicht neu eingetippt werden.
saveConfig(['api_base_url' => $apiBaseUrl, 'token' => null]);

$client = new ApiClient($apiBaseUrl, null);
$res = $client->request('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);

if (!$res['ok']) {
    $message = $res['data']['error'] ?? 'Anmeldung fehlgeschlagen.';
    header('Location: login.php?error=' . urlencode($message));
    exit;
}

session_regenerate_id(true);
saveConfig(['api_base_url' => $apiBaseUrl, 'token' => $res['data']['token']]);
header('Location: calendar.php');
exit;
