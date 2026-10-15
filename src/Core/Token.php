<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Eigenständige, signierte Token-Implementierung (Payload.Signatur, Base64URL,
 * HMAC-SHA256) – funktional vergleichbar mit einem JWT, aber ohne Composer-
 * Abhängigkeit lauffähig. Bei Bedarf 1:1 durch firebase/php-jwt ersetzbar,
 * ohne den Rest der Anwendung anzufassen (siehe composer.json).
 */
final class Token
{
    private static function secret(): string
    {
        $secret = Env::get('APP_SECRET');
        if (!$secret) {
            throw new \RuntimeException('APP_SECRET ist nicht gesetzt (.env prüfen).');
        }
        return $secret;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * @param array $claims z.B. ['user_id' => 1, 'exp' => time() + 3600]
     */
    public static function issue(array $claims): string
    {
        $payload = self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_UNICODE));
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payload, self::secret(), true));
        return $payload . '.' . $signature;
    }

    /**
     * Gibt die Claims zurück oder null, wenn Signatur ungültig / Token abgelaufen.
     */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;

        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, self::secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $claims = json_decode(self::base64UrlDecode($payload), true);
        if (!is_array($claims)) {
            return null;
        }

        if (isset($claims['exp']) && time() > (int) $claims['exp']) {
            return null;
        }

        return $claims;
    }
}
