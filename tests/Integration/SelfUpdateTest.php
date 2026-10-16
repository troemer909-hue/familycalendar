<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Bewusst OHNE einen Testlauf von /api/self-update/run bei aktiviertem
 * ENABLE_SELF_UPDATE: das würde echte git-Kommandos gegen DEN CHECKOUT
 * auslösen, in dem der Testserver selbst läuft (siehe GitUpdaterTest für
 * isolierte Git-Tests gegen eigene Wegwerf-Repos). Hier wird nur die
 * Zugriffskontrolle und das sichere Standardverhalten (deaktiviert)
 * geprüft.
 */
final class SelfUpdateTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        $this->db = IntegrationDb::freshDatabase();
    }

    private function client(?string $token = null): \ApiClient
    {
        return new \ApiClient(ApiServer::baseUrl(), $token);
    }

    private function registerAndPromoteToPlatformAdmin(): string
    {
        $register = $this->client()->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie',
            'admin_name' => 'Anna',
            'email' => 'anna@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($register['ok'], json_encode($register));

        $this->db->prepare('UPDATE users SET is_platform_admin = 1 WHERE email = :email')
            ->execute(['email' => 'anna@example.test']);

        return $register['data']['token'];
    }

    public function testStatusIsForbiddenForNonPlatformAdmins(): void
    {
        $register = $this->client()->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie',
            'admin_name' => 'Bruno',
            'email' => 'bruno@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($register['ok']);

        $status = $this->client($register['data']['token'])->request('GET', '/api/self-update/status');

        self::assertSame(403, $status['status']);
    }

    public function testRunIsForbiddenForNonPlatformAdmins(): void
    {
        $register = $this->client()->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie',
            'admin_name' => 'Bruno',
            'email' => 'bruno2@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($register['ok']);

        $run = $this->client($register['data']['token'])->request('POST', '/api/self-update/run');

        self::assertSame(403, $run['status']);
    }

    public function testStatusForPlatformAdminReportsDisabledByDefault(): void
    {
        $token = $this->registerAndPromoteToPlatformAdmin();

        $status = $this->client($token)->request('GET', '/api/self-update/status');

        self::assertTrue($status['ok']);
        self::assertFalse($status['data']['enabled'], 'ENABLE_SELF_UPDATE muss ohne explizite Konfiguration AUS sein.');
    }

    public function testRunRefusesWhenNotEnabled(): void
    {
        $token = $this->registerAndPromoteToPlatformAdmin();

        $run = $this->client($token)->request('POST', '/api/self-update/run');

        self::assertFalse($run['ok']);
        self::assertSame(403, $run['status']);

        $logged = $this->db->query('SELECT COUNT(*) FROM self_update_runs')->fetchColumn();
        self::assertSame('0', (string) $logged, 'Ein abgelehnter Aufruf (Feature deaktiviert) wird nicht protokolliert.');
    }
}
