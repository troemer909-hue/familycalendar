<?php

declare(strict_types=1);

/**
 * Schlanker HTTP-Client für das Familienkalender-Backend – reines PHP,
 * nur die cURL-Extension (in praktisch jeder PHP-Installation dabei).
 * Keine Composer-Abhängigkeit (kein Guzzle o.ä.).
 */
final class ApiClient
{
    /**
     * @var \CurlHandle|resource|null
     */
    private $ch = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token
    ) {
    }

    public function __destruct()
    {
        if ($this->ch !== null) {
            curl_close($this->ch);
        }
    }

    /**
     * @return array{ok: bool, status: int, data: array|null}
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->baseUrl === '') {
            return ['ok' => false, 'status' => 0, 'data' => ['error' => 'Keine API-Adresse konfiguriert.']];
        }

        // EIN cURL-Handle pro ApiClient-Instanz wird über mehrere request()-
        // Aufrufe hinweg wiederverwendet (statt pro Aufruf neu zu öffnen und
        // zu schließen) - curl_reset() setzt nur die Optionen zurück, NICHT
        // den internen DNS-/Verbindungs-Cache des Handles. Seiten wie
        // calendar.php rufen mehrere Endpunkte auf demselben Host
        // nacheinander auf (/api/members, /api/events); ohne Wiederverwendung
        // zahlt jeder einzelne Aufruf eine komplette DNS-Auflösung (und ggf.
        // einen neuen TLS-Handshake) erneut - auf Hosts mit langsamer
        // DNS-Auflösung (z.B. ein nicht erreichbarer erster Nameserver mit
        // Timeout-Fallback) macht das den Unterschied zwischen "sofort" und
        // mehreren Sekunden PRO Aufruf.
        if ($this->ch === null) {
            $this->ch = curl_init();
        } else {
            curl_reset($this->ch);
        }
        $ch = $this->ch;

        curl_setopt($ch, CURLOPT_URL, rtrim($this->baseUrl, '/') . $path);

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

        // Bei POST/PUT/PATCH/DELETE IMMER CURLOPT_POSTFIELDS setzen, auch
        // ohne Body (leerer String statt null) - cURL schickt sonst einen
        // Request ganz ohne Content-Length-Header, was manche WAFs
        // (z.B. ModSecurity/COMODO-Regelsätze auf Shared-Hosting) als
        // Protokollverstoß werten und mit 403 blocken, bevor der Request
        // überhaupt bei PHP ankommt (betraf z.B. den bodylosen POST von
        // Selbst-Update).
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : '');
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            // Handle NICHT schließen (curl_close) - bleibt für den nächsten
            // request()-Aufruf auf derselben Instanz erhalten, wird in
            // __destruct() final geschlossen.
            return ['ok' => false, 'status' => 0, 'data' => ['error' => 'Netzwerkfehler: ' . curl_error($ch)]];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $data = json_decode($raw, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : null,
        ];
    }
}
