<?php
declare(strict_types=1);

namespace MembersPortal\Support;

/**
 * Structured JSON-lines audit logger for authentication and access control.
 *
 * Never logs secrets, passwords, access tokens, refresh tokens, or DPoP proofs.
 */
final class Logger
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $event, array $context = []): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $entry = [
            'ts'    => gmdate('c'),
            'event' => $event,
            'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'ctx'   => $this->sanitize($context),
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }

        @file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Strip any sensitive keys if accidentally passed.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        $blocked = ['password', 'token', 'access_token', 'refresh_token', 'dpop', 'secret', 'authorization'];
        $clean = [];

        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }
}
