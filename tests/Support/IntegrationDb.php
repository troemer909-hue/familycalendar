<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Support;

use Familienkalender\Core\Database;
use PDO;
use PHPUnit\Framework\Assert;

/**
 * Hilfsklasse für Integration-Tests, die wirklich gegen MySQL schreiben
 * (im Gegensatz zu tests/Unit/, das komplett ohne DB läuft). Angelehnt an
 * ein ähnliches Muster in einem Schwesterprojekt:
 *
 * - Ohne konfigurierte Test-DB werden Integration-Tests LOKAL einfach
 *   übersprungen (skipUnlessConfigured()) - niemand braucht MySQL, um
 *   `composer test` (nur Unit) laufen zu lassen.
 * - In CI (Umgebungsvariable CI=1) schlagen sie dagegen FEHL statt leise
 *   übersprungen zu werden, falls die DB fehlt - eine kaputte CI-DB-
 *   Konfiguration soll nie unbemerkt "grün" durchlaufen.
 * - Sicherheitsgurt gegen versehentliches Leerräumen einer echten DB:
 *   DB_NAME muss auf "_test" enden UND ALLOW_TEST_DB_WIPE=1 gesetzt sein,
 *   bevor freshDatabase() irgendetwas löscht.
 */
final class IntegrationDb
{
    public static function skipUnlessConfigured(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');

        if ($host !== false && $host !== '' && $name !== false && $name !== '') {
            return;
        }

        if (getenv('CI') === '1' || getenv('CI') === 'true') {
            Assert::fail(
                'Integration-Tests benötigen DB_HOST/DB_NAME (etc.) in CI - '
                . 'ein leises Überspringen würde eine kaputte Testdatenbank-Konfiguration verstecken.'
            );
        }

        Assert::markTestSkipped(
            'Keine Test-Datenbank konfiguriert (DB_HOST/DB_NAME) - siehe README, Abschnitt "Tests".'
        );
    }

    /**
     * Leert alle Tabellen (FK-Checks währenddessen aus) und baut sie aus
     * database/schema.sql neu auf - läuft vor JEDEM Integrationstest, damit
     * Tests sich nicht gegenseitig beeinflussen (siehe setUp()-Aufrufe in
     * den jeweiligen Testklassen).
     */
    public static function freshDatabase(): PDO
    {
        $name = (string) getenv('DB_NAME');
        if (!str_ends_with($name, '_test')) {
            Assert::fail("DB_NAME ('{$name}') muss auf '_test' enden - Sicherheitsgurt gegen das versehentliche Leerräumen einer echten Datenbank.");
        }
        if (getenv('ALLOW_TEST_DB_WIPE') !== '1') {
            Assert::fail('ALLOW_TEST_DB_WIPE=1 muss explizit gesetzt sein, bevor Integration-Tests die Testdatenbank leeren dürfen.');
        }

        $db = Database::connection();

        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $db->exec("TRUNCATE TABLE `{$table}`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            // Jede Tabelle in schema.sql hat einen "-- Abschnitt"-Kommentar-
            // Header direkt VOR sich, ohne eigenes Semikolon dazwischen -
            // ein Statement kann also mit Kommentarzeilen BEGINNEN und
            // trotzdem echtes SQL enthalten. Nur Statements überspringen,
            // bei denen nach Entfernen ALLER Kommentarzeilen nichts übrig
            // bleibt (reine Kommentarblöcke).
            $withoutComments = trim((string) preg_replace('/^\s*--.*$/m', '', $statement));
            if ($withoutComments === '') {
                continue;
            }
            $db->exec($statement);
        }

        return $db;
    }
}
