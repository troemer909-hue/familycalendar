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

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';

        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $this->query);

        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $this->body = is_array($decoded) ? $decoded : [];

        $this->bearerToken = $this->extractBearerToken();
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
