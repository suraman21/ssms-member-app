<?php
/**
 * Self-service account activation wizard.
 *
 * S12: activation now uses a 6-digit OTP bound to a ticket/request
 * reference (ticket_no like ACM-20251127-0001 or request_ref like MX7CVJNR).
 * The old selector+secret pair remains for device_add/reset but is no longer
 * accepted for activation. This template accepts both so old codes in
 * circulation still work while new OTP codes are primary. Self-service
 * flow is 4 steps: request -> verify -> credentials -> device.
 *
 * @var callable(mixed): string $e
 * @var string|null $csrfToken
 * @var string|null $error
 * @var string|null $notice
 * @var string|null $step
 * @var string|null $reference
 * @var string|null $grant
 * @var string|null $idempotencyKey
 * @var string|null $username
 */
$currentStep = $step ?? 'request';
?>
<section class="fk-card fk-auth-card" aria-labelledby="activation-heading">
    <h1 id="activation-heading" class="fk-card-title">Activate Your Account</h1>

    <?php if (!empty($error)): ?>
        <div class="fk-alert fk-alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($notice)): ?>
        <div class="fk-notice" role="status"><?= $e($notice) ?></div>
    <?php endif; ?>

    <?php if ($currentStep === 'request'): ?>
        <p class="fk-card-subtitle">Step 1 of 4: Request an activation code using your Member ID.</p>
        <form class="fk-form" method="post" action="/activation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken ?? '') ?>">
            <input type="hidden" name="step" value="request">
            <input type="hidden" name="account_type" value="member">

            <div class="fk-field">
                <label for="member_code" class="fk-label">Member ID</label>
                <input id="member_code" name="member_code" type="text" class="fk-input" autocomplete="off" required>
            </div>

            <div class="fk-field">
                <label for="full_name" class="fk-label">Full Name</label>
                <input id="full_name" name="full_name" type="text" class="fk-input" autocomplete="name" required>
            </div>

            <div class="fk-field">
                <label for="phone" class="fk-label">Phone Number (optional)</label>
                <input id="phone" name="phone" type="tel" class="fk-input" autocomplete="tel">
            </div>

            <div class="fk-actions">
                <button type="submit" class="fk-btn fk-btn-primary">Request Activation Code</button>
            </div>
        </form>
        <p class="fk-help-text">
            Already have a reference and 6-digit code? <a href="/activation?step=verify">Enter your code</a>.
        </p>

    <?php elseif ($currentStep === 'verify'): ?>
        <p class="fk-card-subtitle">Step 2 of 4: Enter your reference number and the 6-digit code.</p>
        <div class="fk-notice" role="status" style="margin-bottom:1rem">
            New codes are <strong>6 digits</strong> (e.g. <code>123 456</code>) together with your reference (e.g. <code>MX7CVJNR</code> or <code>ACM-20251127-0001</code>). For self-service requests this is your request reference; for office-issued tickets it is the ticket number.
        </div>
        <form class="fk-form" method="post" action="/activation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken ?? '') ?>">
            <input type="hidden" name="step" value="verify">

            <div class="fk-field">
                <label for="reference" class="fk-label">Request Reference / Ticket No.</label>
                <input id="reference" name="reference" type="text" class="fk-input" value="<?= $e($reference ?? '') ?>" autocomplete="off" placeholder="MX7CVJNR or ACM-20251127-0001" required>
                <p class="fk-help-text" style="margin:4px 0 0">Found on the slip the office gave you. For self-service requests this is your request reference (e.g. MX7CVJNR).</p>
            </div>

            <div class="fk-field">
                <label for="otp" class="fk-label">6-Digit Code</label>
                <input id="otp" name="otp" type="text" inputmode="numeric" class="fk-input" placeholder="123 456" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required>
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
        <p class="fk-help-text">
            Need a new code? <a href="/activation">Request again</a>.
        </p>

    <?php elseif ($currentStep === 'credentials'): ?>
        <p class="fk-card-subtitle">Step 3 of 4: Choose your username and password.</p>
        <form class="fk-form" method="post" action="/activation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken ?? '') ?>">
            <input type="hidden" name="step" value="credentials">
            <input type="hidden" name="grant" value="<?= $e($grant ?? '') ?>">
            <input type="hidden" name="idempotency_key" value="<?= $e($idempotencyKey ?? '') ?>">

            <div class="fk-field">
                <label for="username" class="fk-label">Username (optional)</label>
                <input id="username" name="username" type="text" class="fk-input" value="<?= $e($username ?? '') ?>" autocomplete="username">
            </div>

            <div class="fk-field">
                <label for="password" class="fk-label">Password</label>
                <input id="password" name="password" type="password" class="fk-input" autocomplete="new-password" required>
            </div>

            <div class="fk-actions">
                <button type="submit" class="fk-btn fk-btn-primary">Set Credentials</button>
            </div>
        </form>

    <?php elseif ($currentStep === 'device'): ?>
        <p class="fk-card-subtitle">Step 4 of 4: Register this browser as your trusted device.</p>
        <div class="fk-notice" role="status">
            Your browser stores a non-extractable device key in this profile. Unlike the mobile app's hardware keystore, clearing site data or switching browsers will require an office re-issue.
        </div>
        <div id="device-status" class="fk-help-text">Ready to register this browser.</div>
        <div class="fk-actions">
            <button id="register-device-btn" type="button" class="fk-btn fk-btn-primary">Register This Device</button>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const btn = document.getElementById('register-device-btn');
            const statusEl = document.getElementById('device-status');
            const csrfToken = <?= json_encode($csrfToken ?? '') ?>;
            const grant = <?= json_encode($grant ?? '') ?>;

            if (!window.isSecureContext || !window.crypto || !window.crypto.subtle) {
                statusEl.textContent = 'Device registration requires HTTPS (WebCrypto is unavailable on this origin). Please open this site over HTTPS.';
                btn.disabled = true;
                return;
            }

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
                    statusEl.textContent = err.message || 'Device registration failed. Please try again.';
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
