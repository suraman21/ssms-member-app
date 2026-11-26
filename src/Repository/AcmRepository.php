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
     * Step 1: Verify office-issued activation code.
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
     * Step 2: Set account password.
     *
     * @return array<string, mixed>
     */
    public function setCredentials(string $grant, string $password, string $idempotencyKey): array
    {
        return $this->client->request('POST', '/acm/activate/credentials', [
            'grant'           => $grant,
            'password'        => $password,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Step 3: Begin P-256 device registration.
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
     * Step 4: Complete device registration with DER-encoded ECDSA signature.
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
