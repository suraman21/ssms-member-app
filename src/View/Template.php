<?php
declare(strict_types=1);

namespace MembersPortal\View;

use MembersPortal\Support\HttpException;

final class Template
{
    private string $templatesDir;

    public function __construct(string $templatesDir)
    {
        $this->templatesDir = rtrim($templatesDir, '/\\');
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render a page template inside the base layout.
     *
     * @param string $page Relative page name (e.g. 'sign-in')
     * @param array<string, mixed> $data Prepared view data
     * @param string $layout Layout name (default: 'base')
     */
    public function render(string $page, array $data = [], string $layout = 'base'): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $page) || !preg_match('/^[a-z0-9_-]+$/', $layout)) {
            throw new HttpException(404, 'Page not found.');
        }

        $pageFile = $this->templatesDir . '/pages/' . $page . '.php';
        $layoutFile = $this->templatesDir . '/layouts/' . $layout . '.php';

        if (!is_file($pageFile) || !is_file($layoutFile)) {
            throw new HttpException(404, 'Page not found.');
        }

        $e = static fn(mixed $val): string => self::escape($val);

        $content = $this->renderFile($pageFile, array_merge($data, ['e' => $e]));

        return $this->renderFile($layoutFile, array_merge($data, [
            'e' => $e,
            'content' => $content,
        ]));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function renderFile(string $file, array $vars): string
    {
        ob_start();
        try {
            extract($vars, EXTR_SKIP);
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
    }
}
