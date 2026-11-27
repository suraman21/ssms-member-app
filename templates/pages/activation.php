<?php
/**
 * Account activation wizard.
 *
 * S12: activation now uses a 6-digit OTP bound to a ticket/request
 * reference (ticket_no like ACM-20251127-0001 or request_ref). The old
 * selector+secret pair remains for device_add/reset but is no longer
 * accepted for activation. This template accepts both so old codes in
 * circulation still work while new OTP codes are primary.
 *
 * @var callable(mixed): string $e
 * @var string|null $csrfToken
 * @var string|null $error
 * @var string|null $step
 * @var string|null $grant
 * @var string|null $idempotencyKey
 */
$currentStep = $step ?? 'verify';
?>
<section class="fk-card fk-auth-card" aria-labelledby="activation-heading">
    <h1 id="activation-heading" class="fk-card-title">Activate Your Account</h1>

    <?php if (!empty($error)): ?>
        <div class="fk-alert fk-alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if ($currentStep === 'verify'): ?>
        <p class="fk-card-subtitle">Step 1 of 3: Enter the activation code issued by the Sunday School office.</p>
        <div class="fk-notice" role="status" style="margin-bottom:1rem">
            New codes are <strong>6 digits</strong> (e.g. <code>123 456</code>) together with your ticket number (e.g. <code>ACM-20251127-0001</code>). If you received an older code with a selector and secret, use the link below.
        </div>
        <form class="fk-form" method="post" action="/activation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken ?? '') ?>">
            <input type="hidden" name="step" value="verify">

            <div class="fk-field">
                <label for="reference" class="fk-label">Ticket number or request reference</label>
                <input id="reference" name="reference" type="text" class="fk-input" autocomplete="off" placeholder="ACM-20251127-0001" required>
                <p class="fk-help-text" style="margin:4px 0 0">Found on the slip the office gave you. For self-service requests this is your request reference.</p>
            </div>

            <div class="fk-field">
                <label for="otp" class="fk-label">6-digit activation code</label>
                <input id="otp" name="otp" type="text" class="fk-input" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" placeholder="123 456" required>
                <p class="fk-help-text" style="margin:4px 0 0">Spaces are ignored. The code expires after 10 minutes.</p>
            </div>

            <div class="fk-actions">
                <button type="submit" class="fk-btn fk-btn-primary">Verify Code</button>
            </div>

            <details style="margin-top:1rem">
                <summary class="fk-help-text" style="cursor:pointer">I have an older code with a selector and secret</summary>
                <div style="margin-top:.75rem;border:1px solid var(--fk-gold-light);border-radius:12px;padding:12px;background:var(--fk-ivory)">
                    <p class="fk-help-text">Only for codes issued before November 2026 with a separate selector (6 chars) and secret (8 chars). New 6-digit codes use the fields above.</p>
                    <div class="fk-field">
                        <label for="selector" class="fk-label">Selector (6 chars)</label>
                        <input id="selector" name="selector" type="text" class="fk-input" autocomplete="off" placeholder="e.g. A7K4P2">
                    </div>
                    <div class="fk-field">
                        <label for="secret" class="fk-label">Verification Secret (8 chars)</label>
                        <input id="secret" name="secret" type="password" class="fk-input" autocomplete="off" placeholder="e.g. 9X2Q...">
                    </div>
                    <p class="fk-help-text">If you fill both fields above, the 6-digit code is ignored and the older verification path is used.</p>
                </div>
            </details>
        </form>
    <?php elseif ($currentStep === 'credentials'): ?>
        <p class="fk-card-subtitle">Step 2 of 3: Choose a strong password for your account.</p>
        <form class="fk-form" method="post" action="/activation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken ?? '') ?>">
            <input type="hidden" name="step" value="credentials">
            <input type="hidden" name="grant" value="<?= $e($grant ?? '') ?>">
            <input type="hidden" name="idempotency_key" value="<?= $e($idempotencyKey ?? '') ?>">

            <div class="fk-field">
                <label for="password" class="fk-label">New Password</label>
                <input id="password" name="password" type="password" class="fk-input" autocomplete="new-password" required>
            </div>

            <div class="fk-actions">
                <button type="submit" class="fk-btn fk-btn-primary">Set Password</button>
            </div>
        </form>
    <?php elseif ($currentStep === 'device'): ?>
        <p class="fk-card-subtitle">Step 3 of 3: Register this browser as your trusted device.</p>
        <div class="fk-notice" role="status">
            Your device key is stored securely in this browser. Clearing browser data will require a new activation code from the office.
        </div>
        <div id="device-status" class="fk-help-text">Preparing device registration...</div>
        <div class="fk-actions">
            <button id="register-device-btn" type="button" class="fk-btn fk-btn-primary">Register This Device</button>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const btn = document.getElementById('register-device-btn');
            const statusEl = document.getElementById('device-status');
            const csrfToken = <?= json_encode($csrfToken ?? '') ?>;
            const grant = <?= json_encode($grant ?? '') ?>;

            btn.addEventListener('click', async function () {
                btn.disabled = true;
                statusEl.textContent = 'Generating device key...';
                try {
                    const identity = await window.FKSession.getOrCreateDeviceIdentity();
                    const pubkey = await window.FKSession.exportSpkiBase64(identity.keyPair.publicKey);

                    statusEl.textContent = 'Requesting challenge...';
                    const beginRes = await fetch('/activation', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            csrf_token: csrfToken,
                            step: 'device_begin',
                            grant: grant,
                            installation_id: identity.installationId,
                            pubkey: pubkey,
                            name: navigator.userAgent.slice(0, 64)
                        })
                    });
                    const beginData = await beginRes.json();
                    if (!beginRes.ok || beginData.error) {
                        throw new Error(beginData.error || 'Failed to start device registration.');
                    }

                    statusEl.textContent = 'Signing challenge...';
                    const signature = await window.FKSession.signChallenge(beginData.challenge);

                    const completeRes = await fetch('/activation', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            csrf_token: csrfToken,
                            step: 'device_complete',
                            challenge_id: beginData.challenge_id,
                            challenge: beginData.challenge,
                            signature: signature
                        })
                    });
                    const completeData = await completeRes.json();
                    if (!completeRes.ok || completeData.error) {
                        throw new Error(completeData.error || 'Failed to complete device registration.');
                    }

                    statusEl.textContent = 'Device registered! Redirecting to Sign In...';
                    window.location.href = '/sign-in';
                } catch (err) {
                    statusEl.textContent = err.message || 'Device registration failed.';
                    btn.disabled = false;
                }
            });
        });
        </script>
    <?php endif; ?>

    <p class="fk-help-text">
        Already activated? <a href="/sign-in">Back to Sign In</a>.
    </p>
</section>
