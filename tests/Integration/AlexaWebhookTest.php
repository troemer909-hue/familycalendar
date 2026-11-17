<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class AlexaWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
    }

    public function testWebhookRejectsMissingSignatureBeforeIntentProcessing(): void
    {
        $body = json_encode([
            'version' => '1.0',
            'context' => ['System' => ['application' => ['applicationId' => 'test-skill'], 'user' => ['accessToken' => 'unknown']]],
            'request' => ['type' => 'IntentRequest', 'timestamp' => gmdate('c'), 'intent' => ['name' => 'GetTodayEventsIntent']],
        ], JSON_THROW_ON_ERROR);
        $curl = curl_init(ApiServer::baseUrl() . '/api/alexa/webhook');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        self::assertIsString($response);
        self::assertSame(401, $status);
    }
}
