<?php
declare(strict_types=1);

namespace MembersPortal\Repository;

use MembersPortal\Support\HttpException;

/**
 * Authentication repository calling /api/v1/auth/*.
 */
final class AuthRepository
{
    private ApiClient $client;
    private string $lockDir;

    public function __construct(ApiClient $client, string $lockDir)
    {
        $this->client = $client;
        $this->lockDir = rtrim($lockDir, '/\\');
    }

    /**
     * @return array<string, mixed>
     */
    public function login(
        string $username,
        string $password,
        ?string $installationId = null,
        ?string $deviceFingerprint = null
    ): array {
        $payload = [
            'username' => $username,
            'password' => $password,
        ];

        if ($installationId !== null && $installationId !== '') {
            $payload['installation_id'] = $installationId;
        }
        if ($deviceFingerprint !== null && $deviceFingerprint !== '') {
            $payload['device_fingerprint'] = $deviceFingerprint;
        }

        return $this->client->request('POST', '/auth/login', $payload);
    }

    /**
     * Single-flight refresh guarded by flock() to prevent REFRESH_REUSED family revocation.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $sessionKey, string $refreshToken, ?string $dpopProof = null): array
    {
        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0750, true);
        }

        $lockFile = $this->lockDir . '/refresh_' . hash('sha256', $sessionKey) . '.lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            throw new HttpException(500, 'Unable to coordinate token refresh.');
        }

        try {
            flock($handle, LOCK_EX);
            return $this->client->request(
                'POST',
                '/auth/refresh-token',
                ['refresh_token' => $refreshToken],
                null,
                $dpopProof
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $accessToken, ?string $dpopProof = null): array
    {
        return $this->client->request('GET', '/auth/verify', null, $accessToken, $dpopProof);
    }

    public function logout(string $refreshToken, ?string $accessToken = null): void
    {
        $this->client->request(
            'POST',
            '/auth/logout',
            ['refresh_token' => $refreshToken],
            $accessToken
        );
    }
}
