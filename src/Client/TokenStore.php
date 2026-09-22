<?php

declare(strict_types=1);

namespace Ctpl\Payments\Client;

use Ctpl\Payments\Exceptions\InvalidClient;
use Ctpl\Payments\Exceptions\PaymentsException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * One token, shared, refreshed before it expires.
 *
 * ------------------------------------------------------------------
 *  WHY THE CACHE STORE MATTERS
 * ------------------------------------------------------------------
 *
 * Tokens last fifteen minutes and the token endpoint is throttled at
 * twenty a minute BY IP — it is the only endpoint reachable without a
 * token, so it is the one that gets guessed at, and the limit is tight
 * for that reason.
 *
 * An application caching the token in an array driver holds one per PHP
 * process: twelve workers behind one address is twelve tokens every
 * fifteen minutes, which is fine, until a deploy restarts them all at
 * once and it is twelve in one second. Use a shared store.
 *
 * Refreshed `skew` seconds early so a token never expires between this
 * check and the request that uses it.
 */
final class TokenStore
{
    private ?string $token = null;

    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Config $config,
        private readonly ?CacheFactory $cache = null,
    ) {
    }

    public function token(): string
    {
        $now = time();

        if ($this->token !== null && $now < $this->expiresAt) {
            return $this->token;
        }

        $cached = $this->fromCache();

        if ($cached !== null && $now < $cached['expires_at']) {
            $this->token = $cached['token'];
            $this->expiresAt = $cached['expires_at'];

            return $this->token;
        }

        return $this->fetch();
    }

    /** Drop the token. The next call fetches a new one. */
    public function forget(): void
    {
        $this->token = null;
        $this->expiresAt = 0;

        $this->store()?->forget($this->config->tokenKey);
    }

    private function fetch(): string
    {
        try {
            $response = $this->http
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => $this->config->userAgent(),
                ])
                ->timeout($this->config->timeout)
                ->connectTimeout($this->config->connectTimeout)
                ->withOptions(['http_errors' => false])
                ->post($this->config->tokenUrl(), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config->clientId,
                    'client_secret' => $this->config->clientSecret,
                ]);
        } catch (ConnectionException $e) {
            throw new PaymentsException(
                'Could not reach the payment orchestrator to get a token: ' . $e->getMessage(),
                type: 'connection_failed',
            );
        }

        if (! $response->successful()) {
            /*
             * Every credential failure is the same failure, by design.
             *
             * An unknown client id, a wrong secret, a revoked credential
             * and a call from a disallowed address all return the same
             * `401 invalid_client` with the same message — an endpoint
             * that distinguished them would let anyone enumerate valid
             * client ids. So the hint about WHICH is added here, where it
             * is a guess offered to a developer rather than a fact given
             * to a caller.
             */
            throw new InvalidClient(
                'The orchestrator refused these credentials. It answers identically for an unknown client '
                . 'id, a wrong secret, a revoked credential and a call from an address the credential does '
                . 'not allow — check all four. The specific reason is in the platform\'s audit log.',
                type: 'invalid_client',
                status: $response->status(),
                correlationId: $response->header('X-Correlation-Id') ?: null,
            );
        }

        $body = $response->json();

        if (! is_array($body) || ! is_string($body['access_token'] ?? null)) {
            throw new PaymentsException(
                'The token endpoint answered without an access token.',
                type: 'malformed_response',
                status: $response->status(),
            );
        }

        $lifetime = (int) ($body['expires_in'] ?? 900);

        $this->token = $body['access_token'];
        $this->expiresAt = time() + max(1, $lifetime - $this->config->tokenSkewSeconds);

        $this->store()?->put(
            $this->config->tokenKey,
            ['token' => $this->token, 'expires_at' => $this->expiresAt],
            $this->expiresAt - time(),
        );

        return $this->token;
    }

    /** @return array{token: string, expires_at: int}|null */
    private function fromCache(): ?array
    {
        $cached = $this->store()?->get($this->config->tokenKey);

        if (! is_array($cached) || ! is_string($cached['token'] ?? null)) {
            return null;
        }

        return ['token' => $cached['token'], 'expires_at' => (int) ($cached['expires_at'] ?? 0)];
    }

    private function store(): ?\Illuminate\Contracts\Cache\Repository
    {
        return $this->cache?->store($this->config->tokenStore);
    }
}
