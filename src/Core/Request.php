<?php

declare(strict_types=1);

namespace Familienkalender\Core;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    public array $query = [];
    public array $body = [];
    public array $params = [];   // wird vom Router mit {id} etc. befüllt
    public ?string $bearerToken;

    /**
     * Ohne Argumente verhält sich das wie bisher: Methode/Pfad/Query/Body/
     * Token kommen aus den Superglobals des echten eingehenden HTTP-
     * Requests (public/api.php). Mit expliziten Argumenten baut
     * LocalApiClient hieraus stattdessen einen simulierten "inneren"
     * Request für einen In-Process-Routenaufruf - ohne dafür echte
     * $_SERVER-Werte vortäuschen zu müssen.
     *
     * @param array<string,mixed>|null $query
     * @param array<string,mixed>|null $body
     */
    public function __construct(
        ?string $method = null,
        ?string $path = null,
        ?array $query = null,
        ?array $body = null,
        ?string $bearerToken = null
    ) {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        $this->method = $method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = $path !== null
            ? (rtrim($path, '/') ?: '/')
            : (rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/');

        if ($query !== null) {
            $this->query = $query;
        } else {
            parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $this->query);
        }

        if ($body !== null) {
            $this->body = $body;
        } else {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $this->body = is_array($decoded) ? $decoded : [];
        }

        $this->bearerToken = $bearerToken ?? $this->extractBearerToken();
    }

    private function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($header && preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }
}
