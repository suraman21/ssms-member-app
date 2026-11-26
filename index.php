<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Support/bootstrap.php';

use MembersPortal\Http\Router;
use MembersPortal\Support\HttpException;
use MembersPortal\View\Template;

$router = new Router();
$template = new Template(__DIR__ . '/templates');

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

try {
    $route = $router->resolve($requestUri);
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

    if ($route['noStore']) {
        header('Cache-Control: no-store');
    }

    echo $template->render($route['template'], [
        'title' => $route['title'],
        'activeRoute' => $path,
    ]);
} catch (HttpException $ex) {
    http_response_code($ex->status());
    header('Cache-Control: no-store');
    echo $template->render('error', [
        'title' => 'Error ' . $ex->status() . ' — Felege Kidusan',
        'status' => $ex->status(),
        'message' => $ex->getMessage(),
    ]);
} catch (\Throwable $ex) {
    http_response_code(500);
    header('Cache-Control: no-store');
    echo $template->render('error', [
        'title' => 'Error 500 — Felege Kidusan',
        'status' => 500,
        'message' => 'An unexpected error occurred.',
    ]);
}
