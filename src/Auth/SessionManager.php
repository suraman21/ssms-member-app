<?php
declare(strict_types=1);

namespace MembersPortal\Auth;

/**
 * Manages the opaque PHP session cookie and server-side token storage.
 *
 * The browser never receives the access_token or refresh_token.
 * Only an opaque HttpOnly/Secure/SameSite session cookie is sent to the browser.
 */
final class SessionManager
{
    private const SESSION_KEY = 'fk_auth';
    private const CSRF_KEY = 'fk_csrf';

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('FKSS_MEMBER_SID');
        session_start();
    }

    /**
     * Store authentication payload after login or refresh.
     *
     * @param array<string, mixed> $authData
     */
    public function setAuth(array $authData, bool $regenerateId = false): void
    {
        $this->start();

        if ($regenerateId) {
            session_regenerate_id(true);
        }

        $expiresIn = (int) ($authData['expires_in'] ?? 900);

        $_SESSION[self::SESSION_KEY] = [
            'access_token'  => (string) ($authData['token'] ?? ''),
            'refresh_token' => (string) ($authData['refresh_token'] ?? ''),
            'expires_at'    => time() + $expiresIn,
            'device_bound'  => (bool) ($authData['device_bound'] ?? false),
            'user'          => is_array($authData['user'] ?? null) ? $authData['user'] : [],
        ];
    }

    public function isAuthenticated(): bool
    {
        $this->start();
        $auth = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($auth)
            && !empty($auth['access_token'])
            && !empty($auth['user']['id']);
    }

    public function accessToken(): ?string
    {
        $this->start();
        $token = $_SESSION[self::SESSION_KEY]['access_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    public function refreshToken(): ?string
    {
        $this->start();
        $token = $_SESSION[self::SESSION_KEY]['refresh_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    public function isAccessTokenExpired(int $leewaySeconds = 30): bool
    {
        $this->start();
        $expiresAt = (int) ($_SESSION[self::SESSION_KEY]['expires_at'] ?? 0);
        return $expiresAt <= (time() + $leewaySeconds);
    }

    public function isDeviceBound(): bool
    {
        $this->start();
        return (bool) ($_SESSION[self::SESSION_KEY]['device_bound'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function user(): array
    {
        $this->start();
        $user = $_SESSION[self::SESSION_KEY]['user'] ?? [];
        return is_array($user) ? $user : [];
    }

    /**
     * Compute DPoP `ath` (base64url(sha256(access_token))) without exposing the token.
     */
    public function accessTokenHash(): ?string
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }

        $hash = hash('sha256', $token, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    public function csrfToken(): string
    {
        $this->start();
        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    public function verifyCsrfToken(?string $token): bool
    {
        $this->start();
        $expected = $_SESSION[self::CSRF_KEY] ?? '';
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    public function clear(): void
    {
        $this->start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();
    }
}
