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

final class AlexaWriteIntentsTest extends TestCase
{
    private PDO $db;
    private int $familyId;
    private int $memberId;
    private string $accessToken = 'alexa-write-test-token';

    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        $this->db = IntegrationDb::freshDatabase();

        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $registered = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Alexa-Schreibtest',
            'admin_name' => 'Admin',
            'email' => 'alexa-write-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($registered['ok'], json_encode($registered));
        $client = new \ApiClient(ApiServer::baseUrl(), $registered['data']['token']);
        $members = $client->request('GET', '/api/members');
        self::assertTrue($members['ok'], json_encode($members));
        $this->memberId = (int) $members['data'][0]['id'];
        $lookup = $this->db->prepare('SELECT family_id FROM family_members WHERE id = :id');
        $lookup->execute(['id' => $this->memberId]);
        $this->familyId = (int) $lookup->fetchColumn();

        $this->db->prepare(
            'INSERT INTO alexa_links (user_id, access_token_hash, refresh_token_hash, expires_at, refresh_expires_at)
             SELECT user_id, :access_hash, :refresh_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 90 DAY)
             FROM family_members WHERE id = :member_id'
        )->execute([
            'access_hash' => hash('sha256', $this->accessToken),
            'refresh_hash' => hash('sha256', 'alexa-write-test-refresh'),
            'member_id' => $this->memberId,
        ]);
        putenv('ALEXA_SKILL_ID=test-skill');
    }

    public function testAddEventCreatesOneHourPersonalEvent(): void
    {
        $response = $this->invoke('AddEventIntent', [
            'title' => ['name' => 'title', 'value' => 'Zahnarzt'],
            'date' => ['name' => 'date', 'value' => '2026-12-20'],
            'time' => ['name' => 'time', 'value' => 'T19:00'],
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('eingetragen', $response->data['response']['outputSpeech']['text']);
        $stmt = $this->db->prepare('SELECT starts_at, ends_at, scope, created_by FROM events WHERE family_id = :family_id AND title = :title');
        $stmt->execute(['family_id' => $this->familyId, 'title' => 'Zahnarzt']);
        $event = $stmt->fetch();
        self::assertSame('2026-12-20 19:00:00', $event['starts_at']);
        self::assertSame('2026-12-20 20:00:00', $event['ends_at']);
        self::assertSame('personal', $event['scope']);
        self::assertSame($this->memberId, (int) $event['created_by']);
    }

    public function testMissingTimeUsesAlexaSlotElicitationAndDoesNotCreateEvent(): void
    {
        $response = $this->invoke('AddEventIntent', [
            'title' => ['name' => 'title', 'value' => 'Zahnarzt'],
            'date' => ['name' => 'date', 'value' => '2026-12-20'],
        ]);

        self::assertSame(200, $response->status);
        self::assertSame('Dialog.ElicitSlot', $response->data['response']['directives'][0]['type']);
        self::assertSame('time', $response->data['response']['directives'][0]['slotToElicit']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    public function testDeleteRemovesSingleMatchingEvent(): void
    {
        $eventId = $this->insertEvent('Zahnarzt', '2026-12-20 19:00:00');
        $response = $this->invoke('DeleteEventIntent', ['title' => ['name' => 'title', 'value' => 'Zahnarzt']]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('gelöscht', $response->data['response']['outputSpeech']['text']);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testAmbiguousDeleteAsksForDateAndOnlyDeletesAfterFollowUp(): void
    {
        $first = $this->insertEvent('Zahnarzt', '2026-12-20 19:00:00');
        $second = $this->insertEvent('Zahnarzt', '2026-12-25 14:00:00');

        $prompt = $this->invoke('DeleteEventIntent', ['title' => ['name' => 'title', 'value' => 'Zahnarzt']]);
        self::assertSame('date', $prompt->data['response']['directives'][0]['slotToElicit']);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM events')->fetchColumn());

        $deleted = $this->invoke('DeleteEventIntent', [
            'title' => ['name' => 'title', 'value' => 'Zahnarzt'],
            'date' => ['name' => 'date', 'value' => '2026-12-25'],
        ]);
        self::assertStringContainsString('gelöscht', $deleted->data['response']['outputSpeech']['text']);
        $stmt = $this->db->prepare('SELECT id FROM events WHERE family_id = :family_id');
        $stmt->execute(['family_id' => $this->familyId]);
        self::assertSame([$first], array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        self::assertNotSame($first, $second);
    }

    public function testDeleteWithoutMatchAndInvalidSlotsReturnFriendlySpeech(): void
    {
        $missing = $this->invoke('DeleteEventIntent', ['title' => ['name' => 'title', 'value' => 'Nirgendwo']]);
        self::assertStringContainsString('keinen Termin', $missing->data['response']['outputSpeech']['text']);

        $invalidTime = $this->invoke('AddEventIntent', [
            'title' => ['name' => 'title', 'value' => 'Termin'],
            'date' => ['name' => 'date', 'value' => '2026-12-20'],
            'time' => ['name' => 'time', 'value' => 'T29:90'],
        ]);
        self::assertSame('time', $invalidTime->data['response']['directives'][0]['slotToElicit']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    private function insertEvent(string $title, string $startsAt): int
    {
        $end = (new \DateTimeImmutable($startsAt))->modify('+1 hour')->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO events (family_id, created_by, title, starts_at, ends_at, scope)
             VALUES (:family_id, :created_by, :title, :starts_at, :ends_at, \'personal\')'
        );
        $stmt->execute([
            'family_id' => $this->familyId,
            'created_by' => $this->memberId,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $end,
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function invoke(string $intentName, array $slots): ResponseCaptured
    {
        $raw = json_encode([
            'version' => '1.0',
            'context' => [
                'System' => [
                    'application' => ['applicationId' => 'test-skill'],
                    'user' => ['accessToken' => $this->accessToken],
                ],
            ],
            'request' => [
                'type' => 'IntentRequest',
                'timestamp' => gmdate(DATE_ATOM),
                'intent' => ['name' => $intentName, 'slots' => $slots],
            ],
        ], JSON_THROW_ON_ERROR);
        $request = new Request('POST', '/api/alexa/webhook', [], [], null, $raw, ['signature' => 'test', 'signaturecertchainurl' => 'test']);
        $verifier = new class implements AlexaRequestVerifier {
            public function verify(string $rawBody, array $headers, ?int $now = null): bool { return true; }
        };

        Response::enableCapture();
        try {
            (new AlexaController($verifier))->handle($request);
        } catch (ResponseCaptured $response) {
            return $response;
        } finally {
            Response::disableCapture();
        }
        self::fail('Alexa controller did not return a response.');
    }
}
