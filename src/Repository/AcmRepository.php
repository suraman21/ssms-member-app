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
     * Step 1: Verify the 6-digit OTP against a request_ref / ticket_no.
     *
     * @return array<string, mixed>
     */
    public function verifyOtp(
        string $otp,
        string $reference,
        ?string $installationId = null
    ): array {
        $payload = [
            'otp'       => $otp,
            'reference' => $reference,
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
