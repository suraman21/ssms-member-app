<?php
/**
 * Account activation wizard scaffold.
 *
 * @var callable(mixed): string $e
 */
?>
<section class="fk-card fk-auth-card" aria-labelledby="activation-heading">
    <h1 id="activation-heading" class="fk-card-title">Activate Your Account</h1>
    <p class="fk-card-subtitle">Enter the activation code issued by the Sunday School office.</p>

    <form class="fk-form" method="post" action="/activation" novalidate>
        <div class="fk-field">
            <label for="selector" class="fk-label">Activation Code (Selector)</label>
            <input id="selector" name="selector" type="text" class="fk-input" autocomplete="off" required>
        </div>

        <div class="fk-field">
            <label for="secret" class="fk-label">Verification Secret</label>
            <input id="secret" name="secret" type="password" class="fk-input" autocomplete="off" required>
        </div>

        <div class="fk-actions">
            <button type="submit" class="fk-btn fk-btn-primary">Continue</button>
        </div>
    </form>

    <p class="fk-help-text">
        Already activated? <a href="/sign-in">Back to Sign In</a>.
    </p>
</section>
