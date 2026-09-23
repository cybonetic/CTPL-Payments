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
    /**
     * Where the orchestrator is. Not a setting.
     *
     * ------------------------------------------------------------------
     *  AN APPLICATION DOES NOT GET TO CHOOSE WHERE PAYMENTS GO
     * ------------------------------------------------------------------
     *
     * There is one orchestrator, and every application that takes a
     * payment through this package takes it there. Making that a config
     * key would be asking four SaaS teams to type the same string into
     * four `.env` files, where it can be typed wrongly — and a payment
     * base URL that is wrong by one character does not fail loudly, it
     * fails as "client authentication failed" against somebody else's
     * host.
     *
     * The security half matters more. A configurable base URL means
     * anything that can write a line into `.env` — a leaked deploy
     * credential, a compromised CI job, a misapplied config template —
     * can silently point every payment, every customer's card details and
     * every access token at a host of its choosing, and nothing about the
     * application would look wrong. A constant in a Composer package
     * cannot be changed without changing code that is reviewed and
     * deployed.
     *
     * Staging is NOT a different host. Which gateway accounts a payment
     * may use is decided by the environment of the APPLICATION the
     * credential belongs to, on the platform side — so a staging
     * credential against this URL cannot reach production money, and a
     * production credential is the only thing that can.
     *
     * See `resolveBaseUrl()` for the one exception, which exists for this
     * package's own tests and refuses to work anywhere real.
     */
    public const PLATFORM_URL = 'https://pay.cybonetic.com';

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

    /**
     * @param array<string, mixed> $config
     * @param string $environment The Laravel application's environment,
     *        which decides whether the local-only URL override applies.
     */
    public static function fromArray(array $config, string $environment = 'production'): self
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
            baseUrl: self::resolveBaseUrl($environment),
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

    /**
     * The fixed URL, unless this is the SDK's own test suite.
     *
     * ------------------------------------------------------------------
     *  THE ONE ESCAPE HATCH, AND WHY IT CANNOT BE USED IN ANGER
     * ------------------------------------------------------------------
     *
     * This package's integration suite has to point at a local
     * orchestrator — that is the whole reason it is worth having, because
     * a suite that can only run against production is a suite nobody
     * runs. So `CTPL_PAYMENTS_BASE_URL_OVERRIDE` exists.
     *
     * It is honoured ONLY in `local` and `testing`. In every other
     * environment the constant wins and the override is ignored in
     * silence — not with an error, because an error would be a signal to
     * an attacker that the variable is read at all, and because a
     * production application that somehow has one set should keep taking
     * payments at the right host rather than stopping.
     *
     * The name is deliberately not `CTPL_PAYMENTS_URL`: nothing an
     * integrator would set by accident, or find in a config template, or
     * copy from a colleague's `.env`.
     */
    private static function resolveBaseUrl(string $environment): string
    {
        if (! in_array($environment, ['local', 'testing'], true)) {
            return self::PLATFORM_URL;
        }

        $override = getenv('CTPL_PAYMENTS_BASE_URL_OVERRIDE');

        return is_string($override) && trim($override) !== ''
            ? rtrim(trim($override), '/')
            : self::PLATFORM_URL;
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
