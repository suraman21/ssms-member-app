<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Support/bootstrap.php';

use MembersPortal\Auth\AuthGuard;
use MembersPortal\Auth\SessionManager;
use MembersPortal\Http\Router;
use MembersPortal\Repository\AcmRepository;
use MembersPortal\Repository\ApiClient;
use MembersPortal\Repository\AuthRepository;
use MembersPortal\Repository\ProfileRepository;
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
            // S12: primary path is OTP 6-digit + ticket/request reference.
            // Legacy selector+secret pair is kept for codes issued before Nov 2026
            // and for device_add/reset purposes. Detect which the user supplied.
            $otpRaw = (string) ($_POST['otp'] ?? '');
            $otp = preg_replace('/[^0-9]/', '', $otpRaw) ?? '';
            $reference = trim((string) ($_POST['reference'] ?? $_POST['ticket_no'] ?? ''));
            $selector = trim((string) ($_POST['selector'] ?? ''));
            $secret = trim((string) ($_POST['secret'] ?? ''));

            $isLegacy = $selector !== '' && $secret !== '';
            $isOtp = !$isLegacy && $otp !== '' && $reference !== '';

            if (!$isLegacy && !$isOtp) {
                $viewData['step'] = 'verify';
                if ($selector !== '' || $secret !== '') {
                    $viewData['error'] = 'For older codes please enter both selector and secret. For new codes enter your ticket number and 6-digit code.';
                } elseif ($otp !== '' && $reference === '') {
                    $viewData['error'] = 'Please enter your ticket number (e.g. ACM-20251127-0001) together with the 6-digit code.';
                } elseif ($otp === '' && $reference !== '') {
                    $viewData['error'] = 'Please enter the 6-digit activation code you received from the office.';
                } else {
                    $viewData['error'] = 'Please enter your ticket number and the 6-digit activation code.';
                }
            } else {
                try {
                    if ($isLegacy) {
                        $res = $acmRepo->verifyCode($selector, $secret);
                        $logger->log('activation_verify_legacy', ['selector' => $selector]);
                    } else {
                        // OTP path: normalize reference uppercased server-side, otp digits only
                        if (!preg_match('/^\d{6}$/', $otp)) {
                            throw new HttpException(400, 'The 6-digit code must be exactly 6 numbers (e.g. 123 456).');
                        }
                        if (strlen($reference) > 24 || $reference === '') {
                            throw new HttpException(400, 'Please enter a valid ticket number or request reference.');
                        }
                        $res = $acmRepo->verifyOtp($otp, $reference);
                        $logger->log('activation_verify_otp', ['reference' => $reference]);
                    }
                    $viewData['step'] = 'credentials';
                    $viewData['grant'] = (string) ($res['grant'] ?? '');
                    $viewData['idempotencyKey'] = bin2hex(random_bytes(16));
                } catch (HttpException $ex) {
                    $viewData['step'] = 'verify';
                    $payload = $ex->publicPayload();
                    $code = $payload['code'] ?? '';
                    // Map wire 503 temporarily_unavailable to a friendly retry message
                    if ($ex->status() === 503 || $code === 'temporarily_unavailable') {
                        $viewData['error'] = 'The Accounts Office system is briefly unavailable. Please wait a minute and try MX7CVJNR again. If this persists, contact the office with your ticket number.';
                    } elseif ($code === 'rate_limited') {
                        $retry = $payload['retry_after'] ?? '';
                        $viewData['error'] = $retry !== '' ? 'Too many attempts. Please wait ' . $retry . ' seconds and try again.' : $ex->getMessage();
                    } else {
                        $viewData['error'] = $ex->getMessage();
                    }
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

    if ($path === '/profile') {
        $accessToken = $session->accessToken() ?? '';
        $profileRepo = new ProfileRepository(new ApiClient($config->apiBaseUrl()));

        if ($method === 'POST') {
            $csrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
            if (!$session->verifyCsrfToken($csrf)) {
                throw new HttpException(403, 'Invalid or expired form submission.');
            }

            $action = (string) ($_POST['action'] ?? '');
            try {
                if ($action === 'update_profile') {
                    $profileRepo->updateMe($accessToken, [
                        'profile_version' => (string) ($_POST['profile_version'] ?? ''),
                        'username'        => trim((string) ($_POST['username'] ?? '')),
                        'full_name'       => trim((string) ($_POST['full_name'] ?? '')),
                        'email'           => trim((string) ($_POST['email'] ?? '')),
                    ]);
                    $viewData['notice'] = 'Profile updated.';
                } elseif ($action === 'change_password') {
                    $profileRepo->changePassword(
                        $accessToken,
                        (string) ($_POST['current_password'] ?? ''),
                        (string) ($_POST['new_password'] ?? ''),
                        (string) ($_POST['confirm_password'] ?? '')
                    );
                    $viewData['notice'] = 'Password updated.';
                }
            } catch (HttpException $ex) {
                $viewData['error'] = $ex->getMessage();
            }
        }

        try {
            $viewData['profile'] = $profileRepo->getMe($accessToken);
        } catch (HttpException $ex) {
            $viewData['profile'] = $viewData['user'];
            $viewData['error'] = $viewData['error'] ?? $ex->getMessage();
        }
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
