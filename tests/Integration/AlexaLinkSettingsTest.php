<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class AlexaLinkSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
    }

    public function testUserCanViewAndRevokeTheirAlexaLink(): void
    {
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $register = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Alexa-Testfamilie',
            'admin_name' => 'Admin',
            'email' => 'alexa-settings@example.test',
            'password' => 'sicheres-passwort',
        ]);
        $client = new \ApiClient(ApiServer::baseUrl(), $register['data']['token']);
        // Eine vorhandene Verknüpfung simulieren und dann ihren eigenen
        // Status sowie den Widerruf über die authentifizierte API prüfen.
        $members = $client->request('GET', '/api/members');
        self::assertTrue($members['ok']);
        $db = \Familienkalender\Core\Database::connection();
        $lookup = $db->prepare('SELECT user_id FROM family_members WHERE id = :id');
        $lookup->execute(['id' => $members['data'][0]['id']]);
        $userId = (int) $lookup->fetchColumn();
        $db->prepare(
            'INSERT INTO alexa_links (user_id, access_token_hash, refresh_token_hash, expires_at, refresh_expires_at)
             VALUES (:user_id, :access_hash, :refresh_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 90 DAY))'
        )->execute([
            'user_id' => $userId,
            'access_hash' => hash('sha256', 'access-token'),
            'refresh_hash' => hash('sha256', 'refresh-token'),
        ]);

        $link = $client->request('GET', '/api/alexa/link');
        self::assertTrue($link['ok']);
        self::assertTrue($link['data']['linked']);

        $revoked = $client->request('DELETE', '/api/alexa/link');
        self::assertTrue($revoked['ok']);
        self::assertFalse($revoked['data']['linked']);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM alexa_links WHERE user_id = ' . $userId)->fetchColumn());
    }
}
