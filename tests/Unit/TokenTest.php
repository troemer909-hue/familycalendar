<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Unit;

use Familienkalender\Core\Token;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    public function testIssuedTokenVerifiesBackToTheSameClaims(): void
    {
        $token = Token::issue(['user_id' => 7, 'exp' => time() + 3600]);

        $claims = Token::verify($token);

        self::assertNotNull($claims);
        self::assertSame(7, $claims['user_id']);
    }

    public function testTokenHasPayloadAndSignatureSeparatedByDot(): void
    {
        $token = Token::issue(['user_id' => 1, 'exp' => time() + 3600]);

        self::assertSame(2, count(explode('.', $token)));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = Token::issue(['user_id' => 1, 'exp' => time() - 10]);

        self::assertNull(Token::verify($token));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $token = Token::issue(['user_id' => 1, 'exp' => time() + 3600]);
        [$payload, $signature] = explode('.', $token);

        $tampered = $payload . '.' . strrev($signature);

        self::assertNull(Token::verify($tampered));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        // Payload verändern (z.B. user_id hochsetzen), ohne die Signatur neu
        // zu berechnen - muss als ungültig erkannt werden.
        $token = Token::issue(['user_id' => 1, 'exp' => time() + 3600]);
        [, $signature] = explode('.', $token);

        $forgedPayload = rtrim(strtr(base64_encode(json_encode(['user_id' => 999, 'exp' => time() + 3600])), '+/', '-_'), '=');

        self::assertNull(Token::verify($forgedPayload . '.' . $signature));
    }

    public function testMalformedTokenWithoutDotIsRejected(): void
    {
        self::assertNull(Token::verify('not-a-valid-token'));
    }

    public function testTokenWithoutExpClaimNeverExpires(): void
    {
        $token = Token::issue(['user_id' => 1]);

        self::assertNotNull(Token::verify($token));
    }
}
