<?php
declare(strict_types=1);

namespace MembersPortal\Http;

use MembersPortal\Support\HttpException;

final class Router
{
    /**
     * Finite route table: normalized path => [template, title, noStore].
     *
     * @var array<string, array{template: string, title: string, noStore: bool}>
     */
    private const ROUTES = [
        '/'           => ['template' => 'sign-in',    'title' => 'Sign In — Felege Kidusan', 'noStore' => false],
        '/sign-in'    => ['template' => 'sign-in',    'title' => 'Sign In — Felege Kidusan', 'noStore' => false],
        '/activation' => ['template' => 'activation', 'title' => 'Account Activation — Felege Kidusan', 'noStore' => true],
        '/profile'    => ['template' => 'profile',    'title' => 'My Profile — Felege Kidusan', 'noStore' => true],
    ];

    /**
     * Resolve a request URI path to a finite route definition.
     *
     * @return array{template: string, title: string, noStore: bool}
     */
    public function resolve(string $requestUri): array
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }

        if (!isset(self::ROUTES[$path])) {
            throw new HttpException(404, 'The requested page could not be found.');
        }

        return self::ROUTES[$path];
    }
}
