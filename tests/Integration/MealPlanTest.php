<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class MealPlanTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
    }

    private function client(): \ApiClient
    {
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $register = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie', 'admin_name' => 'Admin',
            'email' => 'meal@example.test', 'password' => 'sicheres-passwort',
        ]);
        return new \ApiClient(ApiServer::baseUrl(), $register['data']['token']);
    }

    public function testCrudWeekFilterUniqueSlotAndShoppingListFlow(): void
    {
        $client = $this->client();
        $entry = ['entry_date' => '2026-11-18', 'meal_slot' => 'dinner', 'label' => 'Pasta', 'notes' => 'Mit Tomatensauce'];
        $created = $client->request('POST', '/api/meal-plan', $entry);
        self::assertTrue($created['ok'], json_encode($created));
        $id = $created['data']['id'];

        self::assertCount(1, $client->request('GET', '/api/meal-plan?from=2026-11-16&to=2026-11-22')['data']);
        self::assertCount(0, $client->request('GET', '/api/meal-plan?from=2026-11-23&to=2026-11-29')['data']);
        self::assertSame(409, $client->request('POST', '/api/meal-plan', $entry)['status']);

        $updated = $client->request('PUT', "/api/meal-plan/{$id}", array_replace($entry, ['label' => 'Pasta al forno']));
        self::assertTrue($updated['ok']);
        $shopping = $client->request('POST', "/api/meal-plan/{$id}/add-to-shopping-list", ['label' => 'Tomaten']);
        self::assertTrue($shopping['ok']);
        self::assertSame('Tomaten', $client->request('GET', '/api/shopping-list')['data'][0]['label']);

        self::assertTrue($client->request('DELETE', "/api/meal-plan/{$id}")['ok']);
        self::assertCount(0, $client->request('GET', '/api/meal-plan?from=2026-11-16&to=2026-11-22')['data']);
    }
}
