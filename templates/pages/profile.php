<?php
/**
 * Member profile page.
 *
 * @var callable(mixed): string $e
 * @var array<string, mixed> $profile
 * @var string|null $csrfToken
 * @var string|null $error
 * @var string|null $notice
 */
$p = $profile ?? [];
?>
<section class="fk-card" aria-labelledby="profile-heading">
    <h1 id="profile-heading" class="fk-card-title">My Profile</h1>
    <p class="fk-card-subtitle">Manage your account details and password.</p>

    <?php if (!empty($error)): ?>
        <div class="fk-alert fk-alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($notice)): ?>
        <div class="fk-notice" role="status"><?= $e($notice) ?></div>
    <?php endif; ?>

    <form class="fk-form" method="post" action="/profile" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken ?? '') ?>">
        <input type="hidden" name="action" value="update_profile">
        <input type="hidden" name="profile_version" value="<?= $e((string) ($p['profile_version'] ?? '')) ?>">

        <div class="fk-field">
            <label for="username" class="fk-label">Username</label>
            <input id="username" name="username" type="text" class="fk-input" value="<?= $e((string) ($p['username'] ?? '')) ?>" required>
        </div>

        <div class="fk-field">
            <label for="full_name" class="fk-label">Full Name</label>
            <input id="full_name" name="full_name" type="text" class="fk-input" value="<?= $e((string) ($p['full_name'] ?? '')) ?>" required>
        </div>

        <div class="fk-field">
            <label for="email" class="fk-label">Email</label>
            <input id="email" name="email" type="email" class="fk-input" value="<?= $e((string) ($p['email'] ?? '')) ?>">
        </div>

        <div class="fk-actions">
            <button type="submit" class="fk-btn fk-btn-primary">Save Changes</button>
        </div>
    </form>
</section>

<section class="fk-card" aria-labelledby="password-heading">
    <h2 id="password-heading" class="fk-card-title">Change Password</h2>

    <form class="fk-form" method="post" action="/profile" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken ?? '') ?>">
        <input type="hidden" name="action" value="change_password">

        <div class="fk-field">
            <label for="current_password" class="fk-label">Current Password</label>
            <input id="current_password" name="current_password" type="password" class="fk-input" autocomplete="current-password" required>
        </div>

        <div class="fk-field">
            <label for="new_password" class="fk-label">New Password</label>
            <input id="new_password" name="new_password" type="password" class="fk-input" autocomplete="new-password" required>
        </div>

        <div class="fk-field">
            <label for="confirm_password" class="fk-label">Confirm New Password</label>
            <input id="confirm_password" name="confirm_password" type="password" class="fk-input" autocomplete="new-password" required>
        </div>

        <div class="fk-actions">
            <button type="submit" class="fk-btn fk-btn-primary">Update Password</button>
        </div>
    </form>
</section>
