<?php

declare(strict_types=1);

/**
 * Schlanker HTTP-Client für das Familienkalender-Backend – reines PHP,
 * nur die cURL-Extension (in praktisch jeder PHP-Installation dabei).
 * Keine Composer-Abhängigkeit (kein Guzzle o.ä.).
 */
final class ApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token
    ) {
    }

    /**
     * @return array{ok: bool, status: int, data: array|null}
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->baseUrl === '') {
            return ['ok' => false, 'status' => 0, 'data' => ['error' => 'Keine API-Adresse konfiguriert.']];
        }

        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);

        $headers = ['Content-Type: application/json'];
        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'data' => ['error' => 'Netzwerkfehler: ' . $error]];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : null,
        ];
    }
}
