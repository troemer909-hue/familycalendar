<?php

declare(strict_types=1);

/**
 * Einmaliges Hilfsskript: erzeugt ein VAPID-Schlüsselpaar für Web Push und
 * gibt es zum Eintragen in .env aus. Benötigt vorher `composer install`
 * (siehe README, Abschnitt "Push-Benachrichtigungen").
 *
 *   php bin/generate-vapid-keys.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php fehlt. Bitte zuerst `composer install` ausführen (siehe README).\n");
    exit(1);
}
require $autoload;

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "Neue VAPID-Schlüssel erzeugt. In .env eintragen:\n\n";
echo "VAPID_PUBLIC_KEY={$keys['publicKey']}\n";
echo "VAPID_PRIVATE_KEY={$keys['privateKey']}\n";
echo "\n(VAPID_SUBJECT ist bereits in .env.example vorbelegt, ggf. eigene Mail-Adresse eintragen.)\n";
