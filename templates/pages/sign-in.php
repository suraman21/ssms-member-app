<?php
/**
 * Sign-in page.
 *
 * @var callable(mixed): string $e
 * @var string|null $csrfToken
 * @var string|null $error
 * @var string|null $notice
 * @var string|null $username
 */
?>
<section class="fk-card fk-auth-card" aria-labelledby="sign-in-heading">
    <h1 id="sign-in-heading" class="fk-card-title">Member Sign In</h1>
    <p class="fk-card-subtitle">Sign in with your Sunday School account.</p>

    <?php if (!empty($error)): ?>
        <div class="fk-alert fk-alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($notice)): ?>
        <div class="fk-notice" role="status"><?= $e($notice) ?></div>
    <?php endif; ?>

    <form class="fk-form" method="post" action="/sign-in" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken ?? '') ?>">

        <div class="fk-field">
            <label for="username" class="fk-label">Username</label>
            <input id="username" name="username" type="text" class="fk-input" autocomplete="username" value="<?= $e($username ?? '') ?>" required>
        </div>

        <div class="fk-field">
            <label for="password" class="fk-label">Password</label>
            <input id="password" name="password" type="password" class="fk-input" autocomplete="current-password" required>
        </div>

        <div class="fk-actions">
            <button type="submit" class="fk-btn fk-btn-primary">Sign In</button>
        </div>
    </form>

    <p class="fk-help-text">
        First time here? <a href="/activation">I have an activation code</a>.
    </p>
</section>
