<?php

declare(strict_types=1);

use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\ResponseCaptured;

/**
 * Ersetzt ApiClient für den Normalfall, dass Client-Seite und API im
 * SELBEN PHP-Prozess laufen (public/calendar.php & Co. und public/api.php
 * liegen auf demselben Server) - ein Termin-/Mitglieder-Abruf per echtem
 * HTTP-Self-Call auf die eigene Domain (DNS, TLS-Handshake, WAF, zweiter
 * kompletter PHP-Bootstrap, siehe ApiClient-Klassendoc) war dafür ein
 * unnötiger, auf Shared-Hosting spürbar langsamer Umweg.
 *
 * Implementiert exakt dieselbe request()-Signatur wie ApiClient, damit
 * beide austauschbar sind (siehe apiClientFor()/currentApiClient() in
 * bootstrap.php). Dispatcht stattdessen direkt in denselben Router, den
 * auch public/api.php verwendet (siehe src/apiRoutes.php) - Controller
 * und Middlewares bleiben dabei komplett unverändert, sie "sehen" über
 * Request/Response weiterhin denselben Vertrag wie bei einem echten
 * HTTP-Request.
 *
 * ApiClient bleibt für den Fall erhalten, dass Client und API bewusst auf
 * getrennten Prozessen/Hosts laufen - aktuell ausschließlich die
 * Integrationstest-Infrastruktur (siehe tests/Support/FrontendServer.php),
 * die aus genau diesem Grund ein explizites api_base_url-Override kennt.
 */
final class LocalApiClient
{
    public function __construct(private readonly ?string $token)
    {
    }

    /**
     * @return array{ok: bool, status: int, data: array|null}
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $pathOnly = parse_url($path, PHP_URL_PATH) ?: '/';
        $query = [];
        parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);

        $request = new Request($method, $pathOnly, $query, $body ?? [], $this->token);

        Response::enableCapture();
        try {
            apiRouter()->dispatch($request);

            // Jede Route antwortet am Ende über Response::json()/error()
            // (löst den catch-Zweig unten aus) - dieser Pfad wird nur
            // erreicht, falls eine Route das wider Erwarten NICHT tut.
            return ['ok' => false, 'status' => 500, 'data' => ['error' => 'Keine Antwort vom Router.']];
        } catch (ResponseCaptured $response) {
            return [
                'ok' => $response->status >= 200 && $response->status < 300,
                'status' => $response->status,
                'data' => is_array($response->data) ? $response->data : null,
            ];
        } catch (\Throwable $e) {
            // Spiegelt den set_exception_handler() aus public/api.php - ein
            // unerwarteter Fehler in einem Controller darf die aufrufende
            // Client-Seite nicht mitreißen, sondern soll sich wie ein
            // regulärer 500er API-Fehler verhalten.
            error_log($e->getMessage() . "\n" . $e->getTraceAsString());
            return ['ok' => false, 'status' => 500, 'data' => ['error' => 'Interner Fehler.']];
        } finally {
            Response::disableCapture();
        }
    }
}
