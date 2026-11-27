<?php
/**
 * Sign-in page (matches prototype login.html).
 *
 * @var callable(mixed): string $e
 * @var string|null $csrfToken
 * @var string|null $error
 * @var string|null $notice
 * @var string|null $username
 */
?>
<div class="login-container">
    <section class="login-card" aria-labelledby="sign-in-heading">
        <div class="login-header">
            <h1 id="sign-in-heading" class="login-title">Member Sign In</h1>
            <p class="login-subtitle">Sign in with your Sunday School account.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="fk-alert fk-alert-error" role="alert"><?= $e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($notice)): ?>
            <div class="fk-notice" role="status"><?= $e($notice) ?></div>
        <?php endif; ?>

        <form class="login-form" method="post" action="/sign-in" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken ?? '') ?>">

            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <input id="username" name="username" type="text" class="form-input" autocomplete="username" value="<?= $e($username ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input id="password" name="password" type="password" class="form-input" autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn-primary">Sign In</button>
        </form>

        <p class="fk-help-text">
            First time here? <a href="/activation">I have an activation code</a>.
        </p>
    </section>
</div>
