<?php
declare(strict_types=1);

namespace MembersPortal\Repository;

/**
 * Activation Ceremony & Device Registration repository (/api/v1/acm/*).
 */
final class AcmRepository
{
    private ApiClient $client;

    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Step 0: Request an activation code.
     * Strict body: member_code, full_name, account_type, and optional phone.
     *
     * @return array<string, mixed>
     */
    public function requestCode(
        string $memberCode,
        string $fullName,
        ?string $phone = null,
        string $accountType = 'member'
    ): array {
        $payload = [
            'member_code'  => $memberCode,
            'full_name'    => $fullName,
            'account_type' => $accountType,
        ];

        if ($phone !== null && $phone !== '') {
            $payload['phone'] = $phone;
        }

        return $this->client->request('POST', '/acm/activate/request', $payload);
    }

    /**
     * Step 1: Verify 6-digit OTP activation code (S12).
     * The platform now issues a numeric OTP bound to a ticket/request
     * reference (ticket_no or request_ref). The old selector+secret pair
     * remains valid only for device_add / reset purposes.
     *
     * @return array<string, mixed>
     */
    public function verifyOtp(
        string $otp,
        string $reference,
        ?string $installationId = null
    ): array {
        // Strict OTP shape: 6 digits, reference is ticket_no (ACM-…) or request_ref.
        // Send BOTH `reference` and `ticket_no` so the payload is accepted by every
        // platform revision that handles either alias (see api/v1/routes/acm.php).
        $payload = [
            'otp'       => $otp,
            'reference' => $reference,
            'ticket_no' => $reference,
        ];

        if ($installationId !== null && $installationId !== '') {
            $payload['installation_id'] = $installationId;
        }

        return $this->client->request('POST', '/acm/activate/verify', $payload);
    }

    /**
     * Legacy: Verify office-issued selector+secret pair (pre-S12 activation
     * and still-valid device_add / reset purposes).
     * Kept for backward compatibility; activation should use verifyOtp().
     *
     * Strict body: only selector, secret, purpose, and optional installation_id.
     *
     * @return array<string, mixed>
     */
    public function verifyCode(
        string $selector,
        string $secret,
        string $purpose = 'activate',
        ?string $installationId = null
    ): array {
        $payload = [
            'selector' => $selector,
            'secret'   => $secret,
            'purpose'  => $purpose,
        ];

        if ($installationId !== null && $installationId !== '') {
            $payload['installation_id'] = $installationId;
        }

        return $this->client->request('POST', '/acm/activate/verify', $payload);
    }

    /**
     * Step 2: Set account credentials (username optional, password required).
     *
     * @return array<string, mixed>
     */
    public function setCredentials(
        string $grant,
        string $password,
        string $idempotencyKey,
        ?string $username = null
    ): array {
        $payload = [
            'grant'           => $grant,
            'password'        => $password,
            'idempotency_key' => $idempotencyKey,
        ];

        if ($username !== null && $username !== '') {
            $payload['username'] = $username;
        }

        return $this->client->request('POST', '/acm/activate/credentials', $payload);
    }

    /**
     * Step 3a: Begin P-256 device registration.
     *
     * @return array<string, mixed>
     */
    public function beginDeviceRegistration(
        string $grant,
        string $installationId,
        string $pubkeySpkiBase64,
        string $deviceName
    ): array {
        return $this->client->request('POST', '/acm/devices/register/begin', [
            'grant'           => $grant,
            'installation_id' => $installationId,
            'pubkey'          => $pubkeySpkiBase64,
            'name'            => $deviceName,
        ]);
    }

    /**
     * Step 3b: Complete device registration with DER-encoded ECDSA signature.
     *
     * @return array<string, mixed>
     */
    public function completeDeviceRegistration(
        string $challengeId,
        string $challenge,
        string $signatureDerBase64
    ): array {
        return $this->client->request('POST', '/acm/devices/register/complete', [
            'challenge_id' => $challengeId,
            'challenge'    => $challenge,
            'signature'    => $signatureDerBase64,
        ]);
    }
}
