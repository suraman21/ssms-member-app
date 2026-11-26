<?php
declare(strict_types=1);

namespace MembersPortal\Auth;

use MembersPortal\Support\HttpException;
use MembersPortal\Support\Logger;

/**
 * Route-level access control guard.
 *
 * Verifies that the PHP session holds a valid authenticated user
 * with an allowed role, and logs access denials.
 */
final class AuthGuard
{
    private SessionManager $session;
    private Logger $logger;

    public function __construct(SessionManager $session, Logger $logger)
    {
        $this->session = $session;
        $this->logger = $logger;
    }

    /**
     * Require an authenticated user with one of the allowed roles.
     *
     * @param list<string> $allowedRoles
     * @return array<string, mixed> User claims from session
     */
    public function requireRole(array $allowedRoles = ['member']): array
    {
        if (!$this->session->isAuthenticated()) {
            $this->logger->log('access_denied', ['reason' => 'unauthenticated']);
            throw new HttpException(401, 'Please sign in to continue.');
        }

        $user = $this->session->user();
        $role = (string) ($user['role'] ?? '');

        if (!in_array($role, $allowedRoles, true)) {
            $this->logger->log('access_denied', [
                'reason'  => 'role_not_allowed',
                'user_id' => $user['id'] ?? null,
                'role'    => $role,
            ]);
            throw new HttpException(403, 'You do not have permission to access this page.');
        }

        return $user;
    }
}
