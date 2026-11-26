<?php
declare(strict_types=1);

namespace MembersPortal\Repository;

/**
 * Self-service profile repository (/api/v1/users/me* and /users/change-password).
 */
final class ProfileRepository
{
    private ApiClient $client;

    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMe(string $accessToken, ?string $dpopProof = null): array
    {
        return $this->client->request('GET', '/users/me', null, $accessToken, $dpopProof);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateMe(string $accessToken, array $fields, ?string $dpopProof = null): array
    {
        return $this->client->request('PATCH', '/users/me', $fields, $accessToken, $dpopProof);
    }

    /**
     * @return array<string, mixed>
     */
    public function changePassword(
        string $accessToken,
        string $currentPassword,
        string $newPassword,
        string $confirmPassword,
        ?string $dpopProof = null
    ): array {
        return $this->client->request('POST', '/users/change-password', [
            'current_password' => $currentPassword,
            'new_password'     => $newPassword,
            'confirm_password' => $confirmPassword,
        ], $accessToken, $dpopProof);
    }
}
