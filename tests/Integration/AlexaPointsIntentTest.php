<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Controllers\AlexaController;
use Familienkalender\Core\AlexaRequestVerifier;
use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\ResponseCaptured;
use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PDO;
use PHPUnit\Framework\TestCase;

final class AlexaPointsIntentTest extends TestCase
{
    private PDO $db;
    private int $userId;
    private int $memberId;

    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        $this->db = IntegrationDb::freshDatabase();
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $registered = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Alexa-Punktetest',
            'admin_name' => 'Admin',
            'email' => 'alexa-points-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($registered['ok'], json_encode($registered));
        $client = new \ApiClient(ApiServer::baseUrl(), $registered['data']['token']);
        $members = $client->request('GET', '/api/members');
        self::assertTrue($members['ok'], json_encode($members));
        $this->memberId = (int) $members['data'][0]['id'];
        $stmt = $this->db->prepare('SELECT user_id FROM family_members WHERE id = :id');
        $stmt->execute(['id' => $this->memberId]);
        $this->userId = (int) $stmt->fetchColumn();
        putenv('ALEXA_SKILL_ID=test-skill');
    }

    public function testLinkedIntentReadsOnlyTheLinkedMembersBalance(): void
    {
        $this->linkAccount();
        $stmt = $this->db->prepare(
            "INSERT INTO member_points_ledger (family_member_id, points, reason, created_by_member_id)
             VALUES (:member_id, 15, 'manual_adjustment', :creator_id)"
        );
        $stmt->execute(['member_id' => $this->memberId, 'creator_id' => $this->memberId]);

        $response = $this->invoke('linked-token');

        self::assertSame('Du hast aktuell 15 Punkte.', $response['response']['outputSpeech']['text']);
    }

    public function testZeroBalanceIsSpokenNormally(): void
    {
        $this->linkAccount();

        $response = $this->invoke('linked-token');

        self::assertSame('Du hast aktuell 0 Punkte.', $response['response']['outputSpeech']['text']);
    }

    public function testUnlinkedAccountReceivesLinkAccountCard(): void
    {
        $response = $this->invoke('unlinked-token');

        self::assertSame(['type' => 'LinkAccount'], $response['response']['card']);
    }

    private function linkAccount(): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO alexa_links (user_id, access_token_hash, refresh_token_hash, expires_at, refresh_expires_at)
             VALUES (:user_id, :access_hash, :refresh_hash, DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY))'
        );
        $stmt->execute([
            'user_id' => $this->userId,
            'access_hash' => hash('sha256', 'linked-token'),
            'refresh_hash' => hash('sha256', 'refresh-token'),
        ]);
    }

    /** @return array<string,mixed> */
    private function invoke(string $token): array
    {
        $body = json_encode([
            'version' => '1.0',
            'context' => ['System' => [
                'application' => ['applicationId' => 'test-skill'],
                'user' => ['accessToken' => $token],
            ]],
            'request' => ['type' => 'IntentRequest', 'timestamp' => gmdate('c'), 'intent' => ['name' => 'GetPointsBalanceIntent', 'slots' => new \stdClass()]],
        ], JSON_THROW_ON_ERROR);
        $verifier = new class implements AlexaRequestVerifier {
            public function verify(string $rawBody, array $headers, ?int $now = null): bool { return true; }
        };

        Response::enableCapture();
        try {
            (new AlexaController($verifier))->handle(new Request('POST', '/api/alexa/webhook', [], [], null, $body));
        } catch (ResponseCaptured $response) {
            return $response->data;
        } finally {
            Response::disableCapture();
        }
        self::fail('Alexa webhook returned without a response.');
    }
}
