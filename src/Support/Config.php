<?php
declare(strict_types=1);

namespace MembersPortal\Support;

/**
 * Loads environment configuration from .fkss_env.php (outside git) or env vars.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    public function __construct(string $envFile)
    {
        $loaded = [];
        if (is_file($envFile)) {
            $data = require $envFile;
            if (is_array($data)) {
                $loaded = $data;
            }
        }
        $this->values = $loaded;
    }

    public function apiBaseUrl(): string
    {
        $url = $this->values['FKSS_API_BASE_URL'] ?? getenv('FKSS_API_BASE_URL');
        if (!is_string($url) || trim($url) === '') {
            throw new HttpException(500, 'Application configuration is incomplete.');
        }
        return rtrim(trim($url), '/');
    }
}
