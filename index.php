<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Support/bootstrap.php';

use MembersPortal\Auth\AuthGuard;
use MembersPortal\Auth\SessionManager;
use MembersPortal\Http\Router;
use MembersPortal\Repository\ApiClient;
use MembersPortal\Repository\AuthRepository;
use MembersPortal\Support\Config;
use MembersPortal\Support\HttpException;
use MembersPortal\Support\Logger;
use MembersPortal\View\Template;

$router = new Router();
$template = new Template(__DIR__ . '/templates');
$session = new SessionManager();
$logger = new Logger(__DIR__ . '/storage/logs/auth.log');
$authGuard = new AuthGuard($session, $logger);
$config = new Config(__DIR__ . '/.fkss_env.php');

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    $route = $router->resolve($requestUri);
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

    if ($path === '/logout' && $method === 'POST') {
        $authGuard->requireRole(['member']);
        $csrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
        if (!$session->verifyCsrfToken($csrf)) {
            throw new HttpException(403, 'Invalid or expired form submission.');
        }

        $refreshToken = $session->refreshToken();
        $accessToken = $session->accessToken();
        if ($refreshToken !== null) {
            try {
                $authRepo = new AuthRepository(
                    new ApiClient($config->apiBaseUrl()),
                    __DIR__ . '/storage/locks'
                );
                $authRepo->logout($refreshToken, $accessToken);
            } catch (\Throwable $ignored) {
                // Best-effort remote revocation; always clear local session.
            }
        }

        $userId = $session->user()['id'] ?? null;
        $session->clear();
        $logger->log('logout', ['user_id' => $userId]);

        header('Location: /sign-in', true, 303);
        exit;
    }

    $viewData = [
        'title'       => $route['title'],
        'activeRoute' => $path,
        'user'        => $session->isAuthenticated() ? $session->user() : [],
        'csrfToken'   => $session->csrfToken(),
    ];

    if (($path === '/' || $path === '/sign-in') && $method === 'POST') {
        $csrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
        if (!$session->verifyCsrfToken($csrf)) {
            throw new HttpException(403, 'Invalid or expired form submission.');
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $viewData['username'] = $username;

        if ($username === '' || $password === '') {
            $viewData['error'] = 'Please enter both username and password.';
        } else {
            try {
                $authRepo = new AuthRepository(
                    new ApiClient($config->apiBaseUrl()),
                    __DIR__ . '/storage/locks'
                );
                $authData = $authRepo->login($username, $password);
                $session->setAuth($authData, true);
                $logger->log('login_success', ['user_id' => $authData['user']['id'] ?? null]);

                header('Location: /profile', true, 303);
                exit;
            } catch (HttpException $ex) {
                $payload = $ex->publicPayload();
                $code = $payload['code'] ?? '';

                if ($ex->status() === 403 && $code === 'activation_pending') {
                    $logger->log('activation_pending', ['username' => $username]);
                    header('Location: /activation', true, 303);
                    exit;
                }

                $logger->log('login_failed', [
                    'username' => $username,
                    'status'   => $ex->status(),
                    'code'     => $code,
                ]);

                if ($ex->status() === 429 && !empty($payload['retry_after'])) {
                    $viewData['error'] = 'Too many sign-in attempts. Please wait ' . $payload['retry_after'] . ' seconds.';
                } else {
                    $viewData['error'] = $ex->getMessage();
                }
            }
        }
    }

    if ($route['protected']) {
        $viewData['user'] = $authGuard->requireRole(['member']);
    }

    if ($route['noStore'] || !empty($viewData['error'])) {
        header('Cache-Control: no-store');
    }

    echo $template->render($route['template'], $viewData);
} catch (HttpException $ex) {
    http_response_code($ex->status());
    header('Cache-Control: no-store');
    echo $template->render('error', [
        'title'   => 'Error ' . $ex->status() . ' — Felege Kidusan',
        'status'  => $ex->status(),
        'message' => $ex->getMessage(),
    ]);
} catch (\Throwable $ex) {
    http_response_code(500);
    header('Cache-Control: no-store');
    echo $template->render('error', [
        'title'   => 'Error 500 — Felege Kidusan',
        'status'  => 500,
        'message' => 'An unexpected error occurred.',
    ]);
}
