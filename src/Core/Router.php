<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Bewusst minimalistischer, selbst geschriebener Router (kein FastRoute/
 * Framework) – unterstützt einfache Pfadparameter wie /events/{id}.
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:callable, middlewares: callable[]}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => rtrim($pattern, '/') ?: '/',
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function get(string $pattern, callable $handler, array $middlewares = []): void
    {
        $this->add('GET', $pattern, $handler, $middlewares);
    }

    public function post(string $pattern, callable $handler, array $middlewares = []): void
    {
        $this->add('POST', $pattern, $handler, $middlewares);
    }

    public function put(string $pattern, callable $handler, array $middlewares = []): void
    {
        $this->add('PUT', $pattern, $handler, $middlewares);
    }

    public function delete(string $pattern, callable $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middlewares);
    }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }

            $request->params = $params;

            foreach ($route['middlewares'] as $middleware) {
                $middleware($request);
            }

            ($route['handler'])($request);
            return;
        }

        Response::error('Route nicht gefunden.', 404);
    }

    /**
     * @return array<string,string>|null null = kein Match
     */
    private function match(string $pattern, string $path): ?array
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        foreach ($patternParts as $i => $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $matches)) {
                $params[$matches[1]] = $pathParts[$i];
                continue;
            }
            if ($part !== $pathParts[$i]) {
                return null;
            }
        }

        return $params;
    }
}
