<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class MealPlanTest extends TestCase
{
    protected function setUp(): void { IntegrationDb::skipUnlessConfigured(); IntegrationDb::freshDatabase(); }

    private function client(string $email = 'meal@example.test'): \ApiClient
    {
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $result = $anonymous->request('POST', '/api/auth/register', ['family_name'=>'Testfamilie','admin_name'=>'Admin','email'=>$email,'password'=>'sicheres-passwort']);
        return new \ApiClient(ApiServer::baseUrl(), $result['data']['token']);
    }

    public function testCrudFiltersWeekAndAddsExplicitShoppingItem(): void
    {
        $client=$this->client();
        $created=$client->request('POST','/api/meal-plan',['entry_date'=>'2026-11-16','meal_slot'=>'dinner','label'=>'Pasta','notes'=>'mit Salat']);
        self::assertTrue($created['ok'],json_encode($created)); $id=$created['data']['id'];
        self::assertCount(1,$client->request('GET','/api/meal-plan?from=2026-11-16&to=2026-11-22')['data']);
        self::assertCount(0,$client->request('GET','/api/meal-plan?from=2026-11-23&to=2026-11-29')['data']);
        $duplicate=$client->request('POST','/api/meal-plan',['entry_date'=>'2026-11-16','meal_slot'=>'dinner','label'=>'Andere Pasta']);
        self::assertFalse($duplicate['ok']); self::assertSame(409,$duplicate['status']);
        $update=$client->request('PUT',"/api/meal-plan/{$id}",['entry_date'=>'2026-11-17','meal_slot'=>'dinner','label'=>'Pasta al Limone','notes'=>'']);
        self::assertTrue($update['ok']);
        $shopping=$client->request('POST',"/api/meal-plan/{$id}/add-to-shopping-list",['label'=>'Zitronen']);
        self::assertTrue($shopping['ok']);
        self::assertSame('Zitronen',$client->request('GET','/api/shopping-list')['data'][0]['label']);
        self::assertTrue($client->request('DELETE',"/api/meal-plan/{$id}")['ok']);
    }
}
