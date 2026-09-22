<?php

declare(strict_types=1);

namespace Ctpl\Payments\Client;

use Ctpl\Payments\Exceptions\PaymentsException;
use Ctpl\Payments\Exceptions\RateLimited;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * The one place this package speaks HTTP.
 *
 * ------------------------------------------------------------------
 *  WHAT IT TAKES OFF THE CALLER
 * ------------------------------------------------------------------
 *
 *   the token          — fetched on demand, cached, refreshed before it
 *                        expires. The token endpoint is throttled by IP
 *                        because it is the only one reachable without a
 *                        token, so an application fetching one per
 *                        request gets limited and takes everything
 *                        sharing its egress address down with it.
 *   idempotency        — required, not optional. See `mutate()`.
 *   correlation        — one id per request, echoed in every log line and
 *                        in the platform's, which is what makes a support
 *                        conversation about a specific payment possible.
 *   errors             — the API's `error.type` becomes a typed exception
 *                        that knows whether it may be retried.
 *   retries            — only where a repeat is provably safe.
 *
 * What it deliberately does NOT do is interpret payments. Deciding that a
 * payment succeeded is `Payments::confirm()`, which polls; nothing here
 * infers an outcome from a status code.
 */
final class Client
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Config $config,
        private readonly TokenStore $tokens,
    ) {
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * A read.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, query: $query, safeToRepeat: true);
    }

    /**
     * A write, and the idempotency key is not optional.
     *
     * ------------------------------------------------------------------
     *  STRICTER THAN THE API ON PURPOSE
     * ------------------------------------------------------------------
     *
     * The platform refuses a mutating request without an `Idempotency-Key`
     * and returns `400 idempotency_key_required`. This package makes the
     * key a required argument instead, so the refusal happens in your
     * editor rather than in production.
     *
     * The key should be derived from the OPERATION — your booking
     * reference plus the word PAYMENT — not from a random generator. A
     * random key makes every retry a new payment, which is the exact
     * failure the header exists to prevent; the platform cannot tell the
     * difference, and neither can this method, so it is said here as
     * loudly as a docblock can say it.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function mutate(string $method, string $path, string $idempotencyKey, array $body = []): array
    {
        if (trim($idempotencyKey) === '') {
            throw new PaymentsException(
                'An idempotency key is required for every write. Derive it from the operation — your own '
                . 'reference plus what you are doing — so that a retry after a timeout returns the original '
                . 'payment instead of creating a second one.',
                type: 'idempotency_key_required',
            );
        }

        return $this->send(
            $method,
            $path,
            body: $body,
            headers: ['Idempotency-Key' => $idempotencyKey],
            // A repeat carrying the same key returns the original
            // response rather than acting twice. That is what makes a
            // network-level retry safe here and unsafe without a key.
            safeToRepeat: true,
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = [],
        bool $safeToRepeat = false,
    ): array {
        $attempts = $safeToRepeat ? max(1, $this->config->retries + 1) : 1;
        $lastNetworkError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $correlationId = $headers['X-Correlation-Id'] ?? (string) Str::uuid();

            try {
                $response = $this->request($correlationId, $headers)
                    ->send($method, $this->config->url($path), array_filter([
                        'query' => $query,
                        'json' => $body === [] ? null : $body,
                    ], static fn (mixed $v): bool => $v !== null && $v !== []));
            } catch (ConnectionException $e) {
                /*
                 * The answer may exist and we may not have heard it.
                 *
                 * A connection that dropped mid-flight is NOT a request
                 * that did not happen — the payment may have been created.
                 * Retrying is safe only because the idempotency key makes
                 * the repeat return the original, which is why this branch
                 * is reachable only when `safeToRepeat` is true.
                 */
                $lastNetworkError = $e;

                if ($attempt < $attempts) {
                    $this->backOff($attempt);

                    continue;
                }

                throw new PaymentsException(
                    'Could not reach the payment orchestrator: ' . $e->getMessage()
                    . ' The request may still have been processed — resend it with the SAME idempotency key '
                    . 'rather than creating a new payment.',
                    type: 'connection_failed',
                    correlationId: $correlationId,
                );
            }

            if ($response->successful()) {
                return $this->decode($response, $correlationId);
            }

            $error = ErrorMapper::from($response, $correlationId);

            // Only what the exception itself says may be repeated, and
            // only while there is a repeat left. A 409 unresolved_attempt
            // answers false here, which is the whole reason the hierarchy
            // exists.
            if ($error->retryable() && $attempt < $attempts) {
                $this->backOff($attempt, $error instanceof RateLimited ? $error->retryAfter() : null);

                continue;
            }

            throw $error;
        }

        // Unreachable: the loop either returns or throws.
        throw new PaymentsException(
            'The request was not sent: ' . ($lastNetworkError?->getMessage() ?? 'no attempts were made.'),
            type: 'connection_failed',
        );
    }

    /** @param array<string, string> $headers */
    private function request(string $correlationId, array $headers): PendingRequest
    {
        return $this->http
            ->withToken($this->tokens->token())
            ->withHeaders($headers + [
                'Accept' => 'application/json',
                // Sent by us so that OUR log line and the platform's carry
                // the same id. The platform honours an inbound one.
                'X-Correlation-Id' => $correlationId,
                'User-Agent' => $this->config->userAgent(),
            ])
            ->timeout($this->config->timeout)
            ->connectTimeout($this->config->connectTimeout)
            // Never Laravel's own throw-on-error: every failure here goes
            // through ErrorMapper so the caller gets a type it can branch
            // on rather than a RequestException carrying a status code.
            ->withOptions(['http_errors' => false]);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response, string $correlationId): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new PaymentsException(
                'The orchestrator returned a body that is not JSON. This is usually a proxy or an error '
                . 'page in front of it rather than the platform itself.',
                type: 'malformed_response',
                status: $response->status(),
                correlationId: $response->header('X-Correlation-Id') ?: $correlationId,
            );
        }

        return $decoded;
    }

    /**
     * Exponential, with jitter, and it honours Retry-After when given one.
     *
     * The jitter matters more than it looks: without it, every worker that
     * hit the same limit at the same moment retries at the same moment,
     * and the retry storm is indistinguishable from the burst that caused
     * the limit.
     */
    private function backOff(int $attempt, ?int $retryAfterSeconds = null): void
    {
        $milliseconds = $retryAfterSeconds !== null
            ? $retryAfterSeconds * 1000
            : (int) ($this->config->retryBaseMs * (2 ** ($attempt - 1)) + random_int(0, 100));

        usleep(min($milliseconds, 10_000) * 1000);
    }
}
