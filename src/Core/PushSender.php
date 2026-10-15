<?php

declare(strict_types=1);

namespace Familienkalender\Core;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Dünner Wrapper um minishlink/web-push. Push ist bewusst eine OPTIONALE
 * Zusatzfunktion: die eingebundenen Librarys (vendor/) werden nur über
 * `composer install` verfügbar, nicht über den schlanken Haus-Autoloader
 * (siehe public/api.php / bin/*.php). isConfigured() prüft genau das, damit
 * der Rest der App (inkl. E-Mail-Erinnerungen) auch ohne composer weiter
 * funktioniert.
 */
final class PushSender
{
    public static function isConfigured(): bool
    {
        return class_exists(WebPush::class)
            && (bool) Env::get('VAPID_PUBLIC_KEY')
            && (bool) Env::get('VAPID_PRIVATE_KEY');
    }

    /**
     * Schickt eine Push-Benachrichtigung an ALLE Geräte eines Nutzers.
     * Abgelaufene/ungültige Abonnements (HTTP 404/410 von der Push-
     * Plattform) werden dabei automatisch aus push_subscriptions entfernt.
     *
     * @return int Anzahl der Geräte, an die ein Versand VERSUCHT wurde (0
     *             bedeutet: Push nicht konfiguriert ODER keine Abos - der
     *             Aufrufer kann das von einem tatsächlichen Zustellfehler
     *             pro Gerät unterscheiden, siehe bin/send-reminders.php).
     */
    public static function sendToUser(int $userId, string $title, string $body, string $url = '/calendar.php'): int
    {
        if (!self::isConfigured()) {
            return 0;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT endpoint, p256dh_key, auth_key FROM push_subscriptions WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $subscriptions = $stmt->fetchAll();

        if (empty($subscriptions)) {
            return 0;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => Env::get('VAPID_SUBJECT', 'mailto:no-reply@localhost'),
                'publicKey' => Env::get('VAPID_PUBLIC_KEY'),
                'privateKey' => Env::get('VAPID_PRIVATE_KEY'),
            ],
        ]);

        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $row) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'keys' => ['p256dh' => $row['p256dh_key'], 'auth' => $row['auth_key']],
                ]),
                $payload
            );
        }

        $deleteExpired = $db->prepare(
            'DELETE FROM push_subscriptions WHERE user_id = :user_id AND endpoint_hash = :hash'
        );

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }
            if ($report->isSubscriptionExpired()) {
                $deleteExpired->execute([
                    'user_id' => $userId,
                    'hash' => hash('sha256', $report->getEndpoint()),
                ]);
                continue;
            }
            error_log('Push-Versand fehlgeschlagen (user ' . $userId . '): ' . $report->getReason());
        }

        return count($subscriptions);
    }
}
