<?php

declare(strict_types=1);

namespace Familienkalender\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME', 'familienkalender');
        $user = Env::get('DB_USER', 'root');
        $pass = Env::get('DB_PASSWORD', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Absichtlich keine Rohdaten der Exception nach außen geben (Sicherheit).
            error_log('DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
            throw new \RuntimeException('Datenbankverbindung fehlgeschlagen.');
        }

        return self::$instance;
    }
}
