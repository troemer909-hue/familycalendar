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

final class AlexaStockIntentTest extends TestCase
{
    private PDO $db;
    private \ApiClient $client;
    private string $accessToken = 'alexa-stock-test-token';

    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        $this->db = IntegrationDb::freshDatabase();

        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $registered = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Alexa-Vorratstest',
            'admin_name' => 'Admin',
            'email' => 'alexa-stock-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($registered['ok'], json_encode($registered));
        $this->client = new \ApiClient(ApiServer::baseUrl(), $registered['data']['token']);

        $this->db->prepare(
            'INSERT INTO alexa_links (user_id, access_token_hash, refresh_token_hash, expires_at, refresh_expires_at)
             SELECT u.id, :access_hash, :refresh_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 90 DAY)
             FROM users u WHERE u.email LIKE \'alexa-stock-%\''
        )->execute([
            'access_hash' => hash('sha256', $this->accessToken),
            'refresh_hash' => hash('sha256', 'alexa-stock-test-refresh'),
        ]);
        putenv('ALEXA_SKILL_ID=test-skill');
    }

    public function testFlagLowStockIntentMatchesAndIsIdempotent(): void
    {
        $id = $this->createStock('Toilettenpapier');
        $first = $this->invoke('FlagLowStockIntent', ['item' => ['name' => 'item', 'value' => 'Toilettenpapier']]);
        self::assertStringContainsString('als knapp markiert', $first->data['response']['outputSpeech']['text']);
        $again = $this->invoke('FlagLowStockIntent', ['item' => ['name' => 'item', 'value' => 'Toilettenpapier']]);
        self::assertStringContainsString('schon als knapp markiert', $again->data['response']['outputSpeech']['text']);
        self::assertCount(1, $this->client->request('GET', '/api/shopping-list')['data']);
        self::assertTrue($this->stockItem($id)['low_stock']);
    }

    public function testFlagLowStockIntentAsksWhenSeveralItemsMatch(): void
    {
        $this->createStock('Toilettenpapier');
        $this->createStock('Toilettenpapier Premium');
        $response = $this->invoke('FlagLowStockIntent', ['item' => ['name' => 'item', 'value' => 'Toilettenpapier']]);
        self::assertStringContainsString('Meinst du', $response->data['response']['outputSpeech']['text']);
        self::assertCount(0, $this->client->request('GET', '/api/shopping-list')['data']);
    }

    public function testFlagLowStockIntentReportsUnknownItem(): void
    {
        $this->createStock('Toilettenpapier');
        $response = $this->invoke('FlagLowStockIntent', ['item' => ['name' => 'item', 'value' => 'Milch']]);
        self::assertStringContainsString('Ich kenne keinen Vorratsartikel', $response->data['response']['outputSpeech']['text']);
        self::assertCount(0, $this->client->request('GET', '/api/shopping-list')['data']);
    }

    public function testFlagLowStockIntentWithoutItemElicitsSlot(): void
    {
        $response = $this->invoke('FlagLowStockIntent', []);
        self::assertSame('Dialog.ElicitSlot', $response->data['response']['directives'][0]['type']);
        self::assertSame('item', $response->data['response']['directives'][0]['slotToElicit']);
    }

    public function testReadLowStockItemsIntentHandlesEmptyAndListsOnlyFlaggedItems(): void
    {
        $empty = $this->invoke('ReadLowStockItemsIntent', []);
        self::assertStringContainsString('kein Vorratsartikel knapp', $empty->data['response']['outputSpeech']['text']);

        $lowId = $this->createStock('Windeln');
        $this->createStock('Seife');
        $this->client->request('POST', "/api/household-stock/{$lowId}/flag-low");
        $response = $this->invoke('ReadLowStockItemsIntent', []);
        self::assertSame('Knapp werden: Windeln.', $response->data['response']['outputSpeech']['text']);
    }

    private function createStock(string $label): int
    {
        $result = $this->client->request('POST', '/api/household-stock', ['label' => $label]);
        self::assertTrue($result['ok'], json_encode($result));
        return (int) $result['data']['id'];
    }

    private function stockItem(int $id): array
    {
        foreach ($this->client->request('GET', '/api/household-stock')['data'] as $item) {
            if ($item['id'] === $id) return $item;
        }
        self::fail('Vorratsartikel nicht gefunden.');
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
