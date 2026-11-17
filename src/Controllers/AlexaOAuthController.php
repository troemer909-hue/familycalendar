<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Env;
use Familienkalender\Core\Request;
use Familienkalender\Core\RateLimiter;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;
use Familienkalender\Core\Token;

/** Alexa Account Linking OAuth 2 authorization-code flow. */
final class AlexaOAuthController
{
    public const ACCESS_TOKEN_SECONDS = 3600;
    private const CODE_SECONDS = 300;
    private const REFRESH_SECONDS = 60 * 60 * 24 * 90;
    private const AMAZON_REDIRECT_HOSTS = [
        'layla.amazon.com', 'pitangui.amazon.com', 'alexa.amazon.com',
        'alexa.amazon.co.uk', 'alexa.amazon.de', 'alexa.amazon.fr',
        'alexa.amazon.it', 'alexa.amazon.es', 'alexa.amazon.ca',
        'alexa.amazon.com.au', 'alexa.amazon.in', 'alexa.amazon.co.jp',
    ];

    /** GET /oauth/authorize and browser form POSTs. */
    public function authorize(Request $request): never
    {
        $params = $request->method === 'POST'
            ? ($_SESSION['alexa_oauth_params'] ?? [])
            : $request->query;
        $error = $this->validateAuthorization($params);
        if ($error !== null) {
            $this->htmlError($error, 400);
        }
        $_SESSION['alexa_oauth_params'] = $params;

        $userId = $this->authenticatedUserId();
        if ($request->method === 'POST') {
            if (!hash_equals((string) ($_SESSION['alexa_oauth_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
                $this->htmlError('Sicherheitsprüfung fehlgeschlagen. Bitte erneut starten.', 400);
            }
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'login') {
                $email = trim((string) ($_POST['email'] ?? ''));
                $ip = RateLimiter::clientIp();
                if (RateLimiter::tooManyLoginAttempts($email, $ip)) {
                    $this->renderLogin('Zu viele Anmeldeversuche. Bitte warte ein paar Minuten und versuche es erneut.');
                }
                $stmt = Database::connection()->prepare('SELECT id, password_hash FROM users WHERE email = :email');
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch();
                if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
                    RateLimiter::recordFailedLogin($email, $ip);
                    $this->renderLogin('E-Mail-Adresse oder Passwort ist falsch.');
                }
                RateLimiter::clearFailedLogins($email);
                $userId = (int) $user['id'];
                session_regenerate_id(true);
                $_SESSION['alexa_oauth_user_id'] = $userId;
                $_SESSION['alexa_oauth_csrf'] = bin2hex(random_bytes(32));
                $_SESSION['alexa_oauth_params'] = $params;
            } elseif ($action === 'approve') {
                if (!$userId) {
                    $this->renderLogin('Bitte melde dich zuerst an.');
                }
                if (!$this->isPlusUser($userId)) {
                    $this->htmlError('Alexa Account Linking ist nur mit dem Plus-Plan verfügbar.', 403);
                }
                $code = bin2hex(random_bytes(32));
                $db = Database::connection();
                $db->prepare('DELETE FROM alexa_authorization_codes WHERE expires_at < NOW() OR used_at IS NOT NULL')
                    ->execute();
                $db->prepare(
                    'INSERT INTO alexa_authorization_codes (user_id, code_hash, client_id, redirect_uri, expires_at)
                     VALUES (:user_id, :hash, :client_id, :redirect_uri, DATE_ADD(NOW(), INTERVAL ' . self::CODE_SECONDS . ' SECOND))'
                )->execute([
                    'user_id' => $userId,
                    'hash' => hash('sha256', $code),
                    'client_id' => $params['client_id'],
                    'redirect_uri' => $params['redirect_uri'],
                ]);
                $this->redirectWith((string) $params['redirect_uri'], ['code' => $code, 'state' => (string) $params['state']]);
            } else {
                $this->htmlError('Die Verknüpfung wurde nicht bestätigt.', 403);
            }
        }

        if (!$userId) {
            $this->renderLogin();
        }
        if (!$this->isPlusUser($userId)) {
            $this->htmlError('Alexa Account Linking ist nur mit dem Plus-Plan verfügbar.', 403);
        }
        $this->renderConsent();
    }

    /** POST /oauth/token (application/x-www-form-urlencoded). */
    public function token(Request $request): never
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Content-Type: application/json; charset=utf-8');
        $body = array_merge($_POST, $request->body);
        $clientId = (string) ($body['client_id'] ?? '');
        $clientSecret = (string) ($body['client_secret'] ?? '');
        $expectedId = (string) Env::get('ALEXA_CLIENT_ID', '');
        $expectedSecret = (string) Env::get('ALEXA_CLIENT_SECRET', '');
        $basic = $_SERVER['PHP_AUTH_USER'] ?? '';
        $basicSecret = $_SERVER['PHP_AUTH_PW'] ?? '';
        if ($basic === '' && preg_match('/^Basic\s+(.+)$/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $matches)) {
            $decoded = base64_decode($matches[1], true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$basic, $basicSecret] = explode(':', $decoded, 2);
            }
        }
        if ($basic !== '') {
            $clientId = $basic;
            $clientSecret = $basicSecret;
        }
        if ($expectedId === '' || !hash_equals($expectedId, $clientId)
            || ($expectedSecret !== '' && !hash_equals($expectedSecret, $clientSecret))) {
            $this->oauthError('invalid_client', 401);
        }

        $grantType = (string) ($body['grant_type'] ?? '');
        $db = Database::connection();
        if ($grantType === 'authorization_code') {
            $code = (string) ($body['code'] ?? '');
            $redirectUri = (string) ($body['redirect_uri'] ?? '');
            $stmt = $db->prepare(
                'SELECT id, user_id, redirect_uri FROM alexa_authorization_codes
                 WHERE code_hash = :hash AND client_id = :client_id AND used_at IS NULL AND expires_at > NOW()'
            );
            $stmt->execute(['hash' => hash('sha256', $code), 'client_id' => $clientId]);
            $row = $stmt->fetch();
            if (!$row || !hash_equals((string) $row['redirect_uri'], $redirectUri)
                || !$this->isPlusUser((int) $row['user_id'])) {
                $this->oauthError('invalid_grant', 400);
            }
            $db->beginTransaction();
            try {
                $consume = $db->prepare('UPDATE alexa_authorization_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL AND expires_at > NOW()');
                $consume->execute(['id' => $row['id']]);
                if ($consume->rowCount() !== 1) {
                    $db->rollBack();
                    $this->oauthError('invalid_grant', 400);
                }
                $db->prepare('DELETE FROM alexa_links WHERE user_id = :user_id')->execute(['user_id' => $row['user_id']]);
                $tokens = $this->newTokenPair((int) $row['user_id']);
                $db->prepare(
                    'INSERT INTO alexa_links (user_id, access_token_hash, refresh_token_hash, expires_at, refresh_expires_at)
                     VALUES (:user_id, :access_hash, :refresh_hash, DATE_ADD(NOW(), INTERVAL ' . self::ACCESS_TOKEN_SECONDS . ' SECOND), DATE_ADD(NOW(), INTERVAL ' . self::REFRESH_SECONDS . ' SECOND))'
                )->execute([
                    'user_id' => $row['user_id'],
                    'access_hash' => hash('sha256', $tokens['access_token']),
                    'refresh_hash' => hash('sha256', $tokens['refresh_token']),
                ]);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            $this->sendTokenResponse($tokens);
        }

        if ($grantType === 'refresh_token') {
            $refresh = (string) ($body['refresh_token'] ?? '');
            $stmt = $db->prepare('SELECT id, user_id FROM alexa_links WHERE refresh_token_hash = :hash AND refresh_expires_at > NOW()');
            $stmt->execute(['hash' => hash('sha256', $refresh)]);
            $link = $stmt->fetch();
            if (!$link || !$this->isPlusUser((int) $link['user_id'])) {
                $this->oauthError('invalid_grant', 400);
            }
            $tokens = $this->newTokenPair((int) $link['user_id']);
            $update = $db->prepare(
                'UPDATE alexa_links SET access_token_hash = :access_hash, refresh_token_hash = :refresh_hash,
                 expires_at = DATE_ADD(NOW(), INTERVAL ' . self::ACCESS_TOKEN_SECONDS . ' SECOND), created_at = NOW()
                 WHERE id = :id AND refresh_token_hash = :old_hash AND refresh_expires_at > NOW()'
            );
            $update->execute([
                'access_hash' => hash('sha256', $tokens['access_token']),
                'refresh_hash' => hash('sha256', $tokens['refresh_token']),
                'id' => $link['id'],
                'old_hash' => hash('sha256', $refresh),
            ]);
            if ($update->rowCount() !== 1) {
                $this->oauthError('invalid_grant', 400);
            }
            $this->sendTokenResponse($tokens);
        }
        $this->oauthError('unsupported_grant_type', 400);
    }

    /** GET/DELETE /api/alexa/link (authenticated). */
    public function link(Request $request): never
    {
        $db = Database::connection();
        $userId = TenantContext::userId();
        if ($request->method === 'DELETE') {
            $db->prepare('DELETE FROM alexa_links WHERE user_id = :user_id')->execute(['user_id' => $userId]);
            Response::json(['linked' => false]);
        }
        $stmt = $db->prepare('SELECT created_at, expires_at FROM alexa_links WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $link = $stmt->fetch();
        Response::json(['linked' => (bool) $link, 'created_at' => $link['created_at'] ?? null]);
    }

    private function validateAuthorization(array $p): ?string
    {
        foreach (['response_type', 'client_id', 'redirect_uri', 'state'] as $required) {
            if (!isset($p[$required]) || !is_string($p[$required]) || $p[$required] === '') {
                return 'Ungültige OAuth-Anfrage.';
            }
        }
        if ($p['response_type'] !== 'code') {
            return 'Ungültige OAuth-Anfrage.';
        }
        $expectedId = (string) Env::get('ALEXA_CLIENT_ID', '');
        if ($expectedId === '' || !hash_equals($expectedId, (string) $p['client_id'])) {
            return 'Unbekannte Alexa-Skill-ID oder ALEXA_CLIENT_ID ist nicht konfiguriert.';
        }
        $uri = parse_url((string) $p['redirect_uri']);
        if (!$uri || ($uri['scheme'] ?? '') !== 'https'
            || !in_array(strtolower((string) ($uri['host'] ?? '')), self::AMAZON_REDIRECT_HOSTS, true)
            || isset($uri['user']) || isset($uri['pass'])) {
            return 'Die Redirect-Adresse ist ungültig.';
        }
        return null;
    }

    private function authenticatedUserId(): ?int
    {
        $raw = $_COOKIE['fk_auth'] ?? '';
        $config = is_string($raw) ? json_decode($raw, true) : null;
        $claims = is_array($config) && is_string($config['token'] ?? null) ? Token::verify($config['token']) : null;
        if (isset($claims['user_id'])) {
            return (int) $claims['user_id'];
        }
        return isset($_SESSION['alexa_oauth_user_id']) ? (int) $_SESSION['alexa_oauth_user_id'] : null;
    }

    private function isPlusUser(int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM family_members fm JOIN families f ON f.id = fm.family_id
             JOIN plans p ON p.id = f.plan_id WHERE fm.user_id = :user_id AND p.code = 'plus' LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    private function newTokenPair(int $userId): array
    {
        return [
            'access_token' => Token::issue(['user_id' => $userId, 'type' => 'alexa_access', 'exp' => time() + self::ACCESS_TOKEN_SECONDS]),
            'refresh_token' => bin2hex(random_bytes(48)),
        ];
    }

    private function sendTokenResponse(array $tokens): never
    {
        echo json_encode([
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_SECONDS,
            'refresh_token' => $tokens['refresh_token'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function redirectWith(string $uri, array $params): never
    {
        $separator = str_contains($uri, '?') ? '&' : '?';
        header('Location: ' . $uri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986), true, 302);
        exit;
    }

    private function renderLogin(?string $error = null): never
    {
        $this->renderPage('Bei Family-Kalender anmelden', '<p>Bitte melde dich an, um dein Konto mit Alexa zu verknüpfen.</p>'
            . ($error ? '<p class="error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>' : '')
            . '<form method="post"><input type="hidden" name="csrf_token" value="' . $this->csrf() . '">'
            . '<input type="hidden" name="action" value="login"><label>E-Mail<input type="email" name="email" required></label>'
            . '<label>Passwort<input type="password" name="password" required></label><button>Anmelden</button></form>');
    }

    private function renderConsent(): never
    {
        $this->renderPage('Mit Alexa verknüpfen', '<p>Alexa erhält Zugriff auf die Daten deines eigenen Family-Kalender-Kontos.</p>'
            . '<form method="post"><input type="hidden" name="csrf_token" value="' . $this->csrf() . '">'
            . '<input type="hidden" name="action" value="approve"><button>Mit Alexa verknüpfen</button></form>');
    }

    private function csrf(): string
    {
        if (empty($_SESSION['alexa_oauth_csrf'])) {
            $_SESSION['alexa_oauth_csrf'] = bin2hex(random_bytes(32));
        }
        return htmlspecialchars($_SESSION['alexa_oauth_csrf'], ENT_QUOTES, 'UTF-8');
    }

    private function renderPage(string $title, string $body): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><link rel="stylesheet" href="/styles.css">'
            . '<main class="auth-content"><section class="login-card"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $body . '</section></main></html>';
        exit;
    }

    private function htmlError(string $message, int $status): never
    {
        http_response_code($status);
        $this->renderPage('Alexa-Verknüpfung nicht möglich', '<p class="error-box">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>');
    }

    private function oauthError(string $error, int $status): never
    {
        http_response_code($status);
        echo json_encode(['error' => $error]);
        exit;
    }
}
