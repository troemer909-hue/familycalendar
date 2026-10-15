<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Env;
use Familienkalender\Core\PushSender;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

/**
 * Verwaltet Push-Abonnements (ein Browser/Gerät = ein Abo) für den
 * eingeloggten Nutzer. Das eigentliche Abonnieren passiert im Browser
 * (Service Worker + PushManager, siehe public/push_subscribe.php) - hier
 * wird nur das Ergebnis (endpoint + Schlüssel) gespeichert.
 */
final class PushSubscriptionController
{
    /**
     * GET /api/push/vapid-public-key
     * Liefert den öffentlichen VAPID-Schlüssel für den Browser
     * (PushManager.subscribe benötigt ihn als applicationServerKey) - und
     * dient dem Client zugleich als "ist Push auf diesem Server überhaupt
     * eingerichtet"-Check (503, falls nicht).
     */
    public function vapidPublicKey(Request $request): void
    {
        if (!PushSender::isConfigured()) {
            Response::error('Push ist auf diesem Server nicht eingerichtet (composer install + VAPID-Keys fehlen).', 503);
        }

        Response::json(['publicKey' => Env::get('VAPID_PUBLIC_KEY')]);
    }

    /**
     * POST /api/push/subscribe
     * Body: { endpoint, keys: { p256dh, auth }, device_info? }
     */
    public function subscribe(Request $request): void
    {
        if (!PushSender::isConfigured()) {
            Response::error('Push ist auf diesem Server nicht eingerichtet (composer install + VAPID-Keys fehlen).', 503);
        }

        $endpoint = trim((string) $request->input('endpoint', ''));
        $keys = $request->input('keys', []);
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            Response::error('endpoint und keys.p256dh/keys.auth sind erforderlich.', 422);
        }

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh_key, auth_key, device_info)
             VALUES (:user_id, :endpoint, :hash, :p256dh, :auth, :device_info)
             ON DUPLICATE KEY UPDATE p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key),
                device_info = VALUES(device_info)'
        )->execute([
            'user_id' => TenantContext::userId(),
            'endpoint' => $endpoint,
            'hash' => hash('sha256', $endpoint),
            'p256dh' => $p256dh,
            'auth' => $auth,
            'device_info' => trim((string) $request->input('device_info', '')) ?: null,
        ]);

        Response::json(['status' => 'subscribed'], 201);
    }

    /**
     * DELETE /api/push/subscribe
     * Body: { endpoint }
     */
    public function unsubscribe(Request $request): void
    {
        $endpoint = trim((string) $request->input('endpoint', ''));
        if ($endpoint === '') {
            Response::error('endpoint ist erforderlich.', 422);
        }

        Database::connection()
            ->prepare('DELETE FROM push_subscriptions WHERE user_id = :user_id AND endpoint_hash = :hash')
            ->execute(['user_id' => TenantContext::userId(), 'hash' => hash('sha256', $endpoint)]);

        Response::json(['status' => 'unsubscribed']);
    }
}
