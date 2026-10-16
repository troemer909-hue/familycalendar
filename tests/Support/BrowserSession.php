<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Support;

/**
 * Sehr schlanker Cookie-Jar-HTTP-Client für Integration-Tests gegen die
 * session-basierten Client-Seiten (calendar.php, login.php, ...) - im
 * Gegensatz zu \ApiClient, der auf die JSON-API zugeschnitten ist. Hält
 * die Session-Cookie über mehrere Requests hinweg und kann das in jedem
 * Formular eingebettete CSRF-Token selbst auslesen (siehe csrfField() in
 * src/Helpers.php).
 */
final class BrowserSession
{
    private string $cookieJar;

    public function __construct(private readonly string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'fk-browser-session-');
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    /** @return array{status: int, body: string, location: ?string} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, body: string, location: ?string}
     */
    public function post(string $path, array $fields): array
    {
        return $this->request('POST', $path, $fields);
    }

    /** Liest das csrf_token-Hidden-Field aus einer zuvor geladenen Seite. */
    public function extractCsrfToken(string $html): string
    {
        if (!preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches)) {
            throw new \RuntimeException('Kein CSRF-Token in der Seite gefunden.');
        }
        return $matches[1];
    }

    /** @param array<string, string> $fields */
    private function request(string $method, string $path, array $fields = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('HTTP-Request fehlgeschlagen: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $matches)) {
            $location = trim($matches[1]);
        }

        return ['status' => $status, 'body' => $body, 'location' => $location];
    }
}
