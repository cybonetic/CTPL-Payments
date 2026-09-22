<?php

declare(strict_types=1);

namespace Ctpl\Payments\Client;

use Ctpl\Payments\Exceptions\PaymentsException;

/**
 * The package's settings, validated once rather than at every call site.
 *
 * A missing credential is the commonest setup mistake and the one whose
 * default failure is worst: without this it surfaces as a `401` from the
 * platform, halfway through somebody's checkout, reading "client
 * authentication failed" — which sends them looking at their credential
 * rather than at the `.env` line they never added.
 */
final readonly class Config
{
    public function __construct(
        public string $baseUrl,
        public string $clientId,
        public string $clientSecret,
        public int $timeout = 15,
        public int $connectTimeout = 5,
        public int $retries = 2,
        public int $retryBaseMs = 200,
        public ?string $tokenStore = null,
        public string $tokenKey = 'ctpl-payments:token',
        public int $tokenSkewSeconds = 30,
        public int $confirmTimeoutSeconds = 90,
        public int $confirmInitialDelayMs = 500,
        public int $confirmMaxDelayMs = 4000,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $required = static function (string $key, mixed $value) : string {
            if (! is_string($value) || trim($value) === '') {
                throw new PaymentsException(sprintf(
                    'ctpl-payments.%s is not set. Add CTPL_PAYMENTS_%s to your .env — the credential is '
                    . 'issued per application from the operator portal, and its secret is shown once.',
                    $key,
                    strtoupper(str_replace('.', '_', $key)),
                ), type: 'not_configured');
            }

            return trim($value);
        };

        $token = is_array($config['token'] ?? null) ? $config['token'] : [];
        $confirm = is_array($config['confirm'] ?? null) ? $config['confirm'] : [];

        return new self(
            baseUrl: rtrim($required('base_url', $config['base_url'] ?? null), '/'),
            clientId: $required('client_id', $config['client_id'] ?? null),
            clientSecret: $required('client_secret', $config['client_secret'] ?? null),
            timeout: (int) ($config['timeout'] ?? 15),
            connectTimeout: (int) ($config['connect_timeout'] ?? 5),
            retries: max(0, (int) ($config['retries'] ?? 2)),
            retryBaseMs: (int) ($config['retry_base_ms'] ?? 200),
            tokenStore: is_string($token['store'] ?? null) && $token['store'] !== '' ? $token['store'] : null,
            tokenKey: (string) ($token['key'] ?? 'ctpl-payments:token'),
            tokenSkewSeconds: (int) ($token['skew_seconds'] ?? 30),
            confirmTimeoutSeconds: (int) ($confirm['timeout_seconds'] ?? 90),
            confirmInitialDelayMs: (int) ($confirm['initial_delay_ms'] ?? 500),
            confirmMaxDelayMs: (int) ($confirm['max_delay_ms'] ?? 4000),
        );
    }

    /** `/api/v1` is the SDK's business, not the caller's. */
    public function url(string $path): string
    {
        return $this->baseUrl . '/api/v1/' . ltrim($path, '/');
    }

    public function tokenUrl(): string
    {
        return $this->url('auth/token');
    }

    /**
     * Named, and carrying the client id.
     *
     * When an operator is looking at a burst of traffic, "which of the
     * four applications is this" is the first question, and a User-Agent
     * that answers it saves a correlation-id lookup.
     */
    public function userAgent(): string
    {
        return 'ctpl-payments-laravel/1.0 (' . $this->clientId . ')';
    }
}
