<?php
declare(strict_types=1);

namespace MembersPortal\Repository;

use MembersPortal\Support\HttpException;

/**
 * Low-level HTTP client for the platform's /api/v1 endpoints.
 *
 * src/Repository/ is the ONLY layer allowed to call the platform API.
 */
final class ApiClient
{
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(string $baseUrl, int $timeoutSeconds = 15)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $accessToken = null,
        ?string $dpopProof = null
    ): array {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
        ];

        if ($accessToken !== null && $accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }
        if ($dpopProof !== null && $dpopProof !== '') {
            $headers[] = 'DPOP: ' . $dpopProof;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException(500, 'Unable to initialize API client.');
        }

        $retryAfter = null;
        $options = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$retryAfter): int {
                if (stripos($headerLine, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($headerLine, strlen('Retry-After:')));
                }
                return strlen($headerLine);
            },
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                curl_close($ch);
                throw new HttpException(400, 'Invalid request payload.');
            }
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpStatus === 0) {
            throw new HttpException(503, 'Platform API is currently unreachable.', [
                'code' => 'AUTH_SERVICE_UNAVAILABLE',
                'detail' => $curlErr !== '' ? 'transport_error' : 'unknown',
            ]);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || !isset($decoded['status'])) {
            throw new HttpException(502, 'Unexpected response from platform API.');
        }

        if ($decoded['status'] === 'error') {
            $message = is_string($decoded['message'] ?? null)
                ? $decoded['message']
                : 'Request failed.';
            $payload = [];
            if (is_string($decoded['code'] ?? null) && $decoded['code'] !== '') {
                $payload['code'] = $decoded['code'];
            }
            if ($httpStatus === 429 && $retryAfter !== null && $retryAfter !== '') {
                $payload['retry_after'] = $retryAfter;
            }
            throw new HttpException($httpStatus >= 400 ? $httpStatus : 500, $message, $payload);
        }

        $data = $decoded['data'] ?? [];
        return is_array($data) ? $data : [];
    }
}
