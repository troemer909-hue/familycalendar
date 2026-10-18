<?php

declare(strict_types=1);

// Schlanker PSR-4-Autoloader - bewusst ohne "composer install", damit das
// Grundgerüst ohne Internetzugriff sofort lauffähig ist. Sobald composer
// verfügbar ist, kann dies 1:1 durch vendor/autoload.php ersetzt werden
// (siehe composer.json).
//
// Gemeinsam genutzt von public/api.php (echte HTTP-Requests an /api/...)
// und src/bootstrap.php (In-Process-Aufrufe über LocalApiClient, siehe
// dort) - beide brauchen dieselben Familienkalender\*-Klassen (Router,
// Controller, ...) verfügbar, unabhängig davon, über welchen der beiden
// Wege ein Request am Ende bei ihnen landet.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Familienkalender\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Composer-Autoloader NUR für optionale Zusatzfunktionen (aktuell: Push-
// Benachrichtigungen über minishlink/web-push) - fehlt vendor/, läuft die
// App unverändert weiter, die betroffenen Funktionen sind dann einfach
// nicht verfügbar (siehe PushSender::isConfigured()).
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}
