<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class AuthFlowTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
    }

    private function client(): \ApiClient
    {
        return new \ApiClient(ApiServer::baseUrl(), null);
    }

    private function registerTestFamily(\ApiClient $client, string $password = 'sicheres-passwort'): array
    {
        return $client->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie',
            'admin_name' => 'Anna',
            'email' => 'anna@example.test',
            'password' => $password,
        ]);
    }

    public function testRegisterThenLoginSucceeds(): void
    {
        $client = $this->client();

        $register = $this->registerTestFamily($client);
        self::assertTrue($register['ok'], 'Registrierung sollte erfolgreich sein: ' . json_encode($register));
        self::assertArrayHasKey('token', $register['data']);

        $login = $client->request('POST', '/api/auth/login', [
            'email' => 'anna@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($login['ok']);
        self::assertArrayHasKey('token', $login['data']);
    }

    public function testRegisteringTheSameEmailTwiceIsRejected(): void
    {
        $client = $this->client();
        $this->registerTestFamily($client);

        $second = $this->registerTestFamily($client);

        self::assertFalse($second['ok']);
        self::assertSame(409, $second['status']);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $client = $this->client();
        $this->registerTestFamily($client);

        $login = $client->request('POST', '/api/auth/login', [
            'email' => 'anna@example.test',
            'password' => 'falsches-passwort',
        ]);

        self::assertFalse($login['ok']);
        self::assertSame(401, $login['status']);
    }

    public function testLoginWithUnknownEmailGivesSameErrorAsWrongPassword(): void
    {
        // Kein User-Enumeration-Leak: beide Fälle müssen identisch aussehen.
        $client = $this->client();
        $this->registerTestFamily($client);

        $wrongPassword = $client->request('POST', '/api/auth/login', [
            'email' => 'anna@example.test', 'password' => 'falsch',
        ]);
        $unknownEmail = $client->request('POST', '/api/auth/login', [
            'email' => 'niemand@example.test', 'password' => 'egal',
        ]);

        self::assertSame($wrongPassword['status'], $unknownEmail['status']);
        self::assertSame($wrongPassword['data']['error'], $unknownEmail['data']['error']);
    }

    public function testRepeatedFailedLoginsAreRateLimitedPerEmail(): void
    {
        $client = $this->client();
        $this->registerTestFamily($client);

        for ($i = 0; $i < 5; $i++) {
            $client->request('POST', '/api/auth/login', ['email' => 'anna@example.test', 'password' => 'falsch']);
        }

        // Selbst das KORREKTE Passwort darf jetzt nicht mehr durchkommen -
        // die Sperre gilt pro E-Mail, unabhängig vom eingegebenen Passwort.
        $sixthAttempt = $client->request('POST', '/api/auth/login', [
            'email' => 'anna@example.test', 'password' => 'sicheres-passwort',
        ]);

        self::assertSame(429, $sixthAttempt['status'], 'Nach 5 Fehlversuchen sollte auch der korrekte Login blockiert sein.');
    }

    public function testSuccessfulLoginClearsPreviousFailedAttempts(): void
    {
        $client = $this->client();
        $this->registerTestFamily($client);

        for ($i = 0; $i < 3; $i++) {
            $client->request('POST', '/api/auth/login', ['email' => 'anna@example.test', 'password' => 'falsch']);
        }

        $success = $client->request('POST', '/api/auth/login', [
            'email' => 'anna@example.test', 'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($success['ok'], 'Noch unter dem Limit (3 von 5 Fehlversuchen) - sollte durchgehen.');

        // Nach dem Erfolg zählt der Zähler wieder von null.
        for ($i = 0; $i < 3; $i++) {
            $client->request('POST', '/api/auth/login', ['email' => 'anna@example.test', 'password' => 'falsch']);
        }
        $stillOk = $client->request('POST', '/api/auth/login', [
            'email' => 'anna@example.test', 'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($stillOk['ok'], 'Zähler sollte nach erfolgreichem Login zurückgesetzt worden sein.');
    }
}
