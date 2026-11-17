<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Controllers\AlexaController;
use Familienkalender\Core\Database;
use Familienkalender\Core\Response;
use Familienkalender\Core\ResponseCaptured;
use Familienkalender\Core\TenantContext;
use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class AlexaStockIntentTest extends TestCase
{
    private \ApiClient $client;

    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $registered = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Alexa-Testfamilie', 'admin_name' => 'Admin',
            'email' => 'alexa-stock@example.test', 'password' => 'sicheres-passwort',
        ]);
        $this->client = new \ApiClient(ApiServer::baseUrl(), $registered['data']['token']);
        $stmt = Database::connection()->prepare(
            'SELECT u.id AS user_id, fm.id AS member_id, fm.family_id, fm.role
             FROM users u JOIN family_members fm ON fm.user_id = u.id WHERE u.email = :email'
        );
        $stmt->execute(['email' => 'alexa-stock@example.test']);
        $account = $stmt->fetch();
        TenantContext::set((int) $account['user_id'], (int) $account['family_id'], (int) $account['member_id'], (string) $account['role']);
    }

    public function testFlagLowStockIntentMatchesAndIsIdempotent(): void
    {
        $id = $this->createStock('Toilettenpapier');
        $first = $this->dispatch('FlagLowStockIntent', ['item' => ['value' => 'Toilettenpapier']]);
        self::assertStringContainsString('als knapp markiert', $first['response']['outputSpeech']['text']);
        $again = $this->dispatch('FlagLowStockIntent', ['item' => ['value' => 'Toilettenpapier']]);
        self::assertStringContainsString('schon als knapp markiert', $again['response']['outputSpeech']['text']);
        self::assertCount(1, $this->client->request('GET', '/api/shopping-list')['data']);
        self::assertTrue($this->stockItem($id)['low_stock']);
    }

    public function testFlagLowStockIntentAsksWhenSeveralItemsMatch(): void
    {
        $this->createStock('Toilettenpapier');
        $this->createStock('Toilettenpapier Premium');
        $response = $this->dispatch('FlagLowStockIntent', ['item' => ['value' => 'Toilettenpapier']]);
        self::assertStringContainsString('Meinst du', $response['response']['outputSpeech']['text']);
        self::assertCount(0, $this->client->request('GET', '/api/shopping-list')['data']);
    }

    public function testFlagLowStockIntentReportsUnknownItem(): void
    {
        $this->createStock('Toilettenpapier');
        $response = $this->dispatch('FlagLowStockIntent', ['item' => ['value' => 'Milch']]);
        self::assertStringContainsString('Ich kenne keinen Vorratsartikel', $response['response']['outputSpeech']['text']);
        self::assertCount(0, $this->client->request('GET', '/api/shopping-list')['data']);
    }

    public function testReadLowStockItemsIntentHandlesEmptyAndListsOnlyFlaggedItems(): void
    {
        $empty = $this->dispatch('ReadLowStockItemsIntent');
        self::assertStringContainsString('kein Vorratsartikel knapp', $empty['response']['outputSpeech']['text']);
        $lowId = $this->createStock('Windeln');
        $this->createStock('Seife');
        $this->client->request('POST', "/api/household-stock/{$lowId}/flag-low");
        $response = $this->dispatch('ReadLowStockItemsIntent');
        self::assertSame('Knapp werden: Windeln.', $response['response']['outputSpeech']['text']);
    }

    private function dispatch(string $intent, array $slots = []): array
    {
        Response::enableCapture();
        try {
            (new AlexaController())->dispatchVerifiedStockIntent($intent, $slots);
        } catch (ResponseCaptured $response) {
            return $response->data;
        } finally {
            Response::disableCapture();
        }
        self::fail('Alexa-Intent hat keine Antwort erzeugt.');
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
}
