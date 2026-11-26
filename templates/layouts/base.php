<?php
/**
 * Base layout shell.
 *
 * @var callable(mixed): string $e
 * @var string $content
 * @var string|null $title
 * @var string|null $activeRoute
 */
$pageTitle = $title ?? 'Felege Kidusan — Members Portal';
$currentRoute = $activeRoute ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/css/fk-theme.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="fk-body">
    <header class="fk-header">
        <div class="fk-header-inner">
            <a href="/" class="fk-brand">
                <span class="fk-brand-title">Felege Kidusan</span>
                <span class="fk-brand-sub">Members Portal</span>
            </a>
            <nav class="fk-nav" aria-label="Primary">
                <a href="/sign-in" class="fk-nav-link<?= $currentRoute === '/sign-in' || $currentRoute === '/' ? ' is-active' : '' ?>">Sign In</a>
                <a href="/activation" class="fk-nav-link<?= $currentRoute === '/activation' ? ' is-active' : '' ?>">Activate</a>
                <a href="/profile" class="fk-nav-link<?= $currentRoute === '/profile' ? ' is-active' : '' ?>">Profile</a>
            </nav>
        </div>
    </header>

    <main class="fk-main">
        <?= $content ?>
    </main>

    <footer class="fk-footer">
        <div class="fk-footer-inner">
            <p>&copy; <?= date('Y') ?> Felege Kidusan Sunday School</p>
        </div>
    </footer>

    <script src="/assets/js/fk-ui.js" defer></script>
</body>
</html>
