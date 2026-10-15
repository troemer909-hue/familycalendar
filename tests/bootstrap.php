<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Helpers.php und ApiClient.php sind globale Funktionen/Klassen ohne
// Namespace und werden in Produktion direkt per require_once geladen, nicht
// über den PSR-4-Composer-Autoloader - für Tests einmalig hier nachholen.
require dirname(__DIR__) . '/src/Helpers.php';
require dirname(__DIR__) . '/src/ApiClient.php';

// Token::issue()/verify() benötigen APP_SECRET (siehe src/Core/Token.php).
// Für Unit-Tests reicht ein fester Test-Wert; Integration-Tests gegen eine
// echte DB überschreiben das bei Bedarf über eine echte .env/Umgebung.
if (getenv('APP_SECRET') === false) {
    putenv('APP_SECRET=test-secret-only-for-phpunit-not-for-production-use');
}
