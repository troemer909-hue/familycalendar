<?php
require_once __DIR__ . '/../src/bootstrap.php';
requireCsrf();

$apiBaseUrl = defaultApiBaseUrl();
$email = trim($_POST['email'] ?? '');

if ($apiBaseUrl === '' || $email === '') {
    header('Location: forgot_password.php?error=' . urlencode('Bitte E-Mail-Adresse angeben.'));
    exit;
}

saveConfig(['api_base_url' => $apiBaseUrl]);

$client = new ApiClient($apiBaseUrl, null);
$res = $client->request('POST', '/api/auth/password-reset/request', ['email' => $email]);

if (!$res['ok']) {
    // Nur bei echten technischen Fehlern (falsche API-Adresse etc.) - die
    // API selbst antwortet bei unbekannter E-Mail bewusst trotzdem mit ok,
    // kein User-Enumeration-Leak (siehe AuthController).
    header('Location: forgot_password.php?error=' . urlencode($res['data']['error'] ?? 'Anfrage fehlgeschlagen.'));
    exit;
}

header('Location: forgot_password.php?sent=1');
exit;
