<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Support/bootstrap.php';

use MembersPortal\Auth\AuthGuard;
use MembersPortal\Auth\SessionManager;
use MembersPortal\Http\Router;
use MembersPortal\Repository\AcmRepository;
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

    if ($path === '/activation' && $method === 'POST') {
        $csrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
        if (!$session->verifyCsrfToken($csrf)) {
            throw new HttpException(403, 'Invalid or expired form submission.');
        }

        $step = (string) ($_POST['step'] ?? 'verify');
        $acmRepo = new AcmRepository(new ApiClient($config->apiBaseUrl()));

        if ($step === 'verify') {
            $selector = trim((string) ($_POST['selector'] ?? ''));
            $secret = trim((string) ($_POST['secret'] ?? ''));
            if ($selector === '' || $secret === '') {
                $viewData['step'] = 'verify';
                $viewData['error'] = 'Please enter both the activation code and verification secret.';
            } else {
                try {
                    $res = $acmRepo->verifyCode($selector, $secret);
                    $logger->log('activation_verify', ['selector' => $selector]);
                    $viewData['step'] = 'credentials';
                    $viewData['grant'] = (string) ($res['grant'] ?? '');
                    $viewData['idempotencyKey'] = bin2hex(random_bytes(16));
                } catch (HttpException $ex) {
                    $viewData['step'] = 'verify';
                    $viewData['error'] = $ex->getMessage();
                }
            }
        } elseif ($step === 'credentials') {
            $grant = (string) ($_POST['grant'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $idempotencyKey = (string) ($_POST['idempotency_key'] ?? '');
            try {
                $res = $acmRepo->setCredentials($grant, $password, $idempotencyKey);
                $logger->log('activation_credentials', ['user_id' => $res['user_id'] ?? null]);
                $viewData['step'] = 'device';
                $viewData['grant'] = (string) ($res['device_grant'] ?? $grant);
            } catch (HttpException $ex) {
                $viewData['step'] = 'credentials';
                $viewData['grant'] = $grant;
                $viewData['idempotencyKey'] = $idempotencyKey;
                $viewData['error'] = $ex->getMessage();
            }
        } elseif ($step === 'device_begin' || $step === 'device_complete') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            try {
                if ($step === 'device_begin') {
                    $res = $acmRepo->beginDeviceRegistration(
                        (string) ($_POST['grant'] ?? ''),
                        (string) ($_POST['installation_id'] ?? ''),
                        (string) ($_POST['pubkey'] ?? ''),
                        (string) ($_POST['name'] ?? 'Web Browser')
                    );
                } else {
                    $res = $acmRepo->completeDeviceRegistration(
                        (string) ($_POST['challenge_id'] ?? ''),
                        (string) ($_POST['challenge'] ?? ''),
                        (string) ($_POST['signature'] ?? '')
                    );
                    $logger->log('activation_complete', ['device_uuid' => $res['device_uuid'] ?? null]);
                }
                echo json_encode($res);
            } catch (HttpException $ex) {
                http_response_code($ex->status());
                echo json_encode(['error' => $ex->getMessage()]);
            }
            exit;
        }
    }

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
