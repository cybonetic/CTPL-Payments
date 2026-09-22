<?php

declare(strict_types=1);

namespace Ctpl\Payments\Client;

use Ctpl\Payments\Exceptions;
use Illuminate\Http\Client\Response;

/**
 * The API's `error.type` becomes a class the caller can catch.
 *
 * ------------------------------------------------------------------
 *  ON `type`, NOT ON THE STATUS CODE
 * ------------------------------------------------------------------
 *
 * Three different `409`s live on this API and they want three different
 * reactions: `request_in_progress` should be retried, `idempotency_key_conflict`
 * is a bug in the caller, and `unresolved_attempt` must NOT be retried
 * because doing so may charge the customer twice. Mapping by status code
 * would collapse all three into one, and the one that gets retried is the
 * one that costs money.
 *
 * The platform states plainly that `type` is stable and `message` is not,
 * so `type` is what this switches on, and the message is carried through
 * untouched for the log.
 *
 * An unrecognised type becomes the base `PaymentsException`, whose
 * `retryable()` is `false`. A failure this package has not been taught
 * about is one to stop on, not one to hammer.
 */
final class ErrorMapper
{
    public static function from(Response $response, string $correlationId): Exceptions\PaymentsException
    {
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        $type = is_string($error['type'] ?? null) ? $error['type'] : self::typeForStatus($response->status());
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : self::messageForStatus($response->status());
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        $correlation = $response->header('X-Correlation-Id')
            ?: (is_string($error['correlation_id'] ?? null) ? $error['correlation_id'] : $correlationId);

        if ($type === 'rate_limited') {
            $retryAfter = $response->header('Retry-After');

            return new Exceptions\RateLimited(
                $message,
                $type,
                $response->status(),
                $details,
                $correlation,
                is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        /** @var class-string<Exceptions\PaymentsException> $class */
        $class = match ($type) {
            // The one that matters most. See its docblock.
            'unresolved_attempt' => Exceptions\UnresolvedAttempt::class,

            'request_in_progress' => Exceptions\RequestInProgress::class,
            'idempotency_key_conflict' => Exceptions\IdempotencyKeyConflict::class,
            'idempotency_key_required' => Exceptions\IdempotencyKeyRequired::class,

            'invalid_client' => Exceptions\InvalidClient::class,
            'insufficient_scope' => Exceptions\InsufficientScope::class,
            'not_found' => Exceptions\NotFound::class,
            'validation_failed' => Exceptions\ValidationFailed::class,

            'order_not_payable' => Exceptions\OrderNotPayable::class,
            'illegal_state_transition' => Exceptions\IllegalStateTransition::class,
            'amount_mismatch' => Exceptions\AmountMismatch::class,
            'unsupported_capability' => Exceptions\UnsupportedCapability::class,
            'session_not_usable' => Exceptions\SessionNotUsable::class,

            'refund_not_permitted' => Exceptions\RefundNotPermitted::class,
            'payment_link_invalid' => Exceptions\PaymentLinkInvalid::class,

            'no_eligible_gateway',
            'gateway_unavailable',
            'routing_misconfigured' => Exceptions\ServiceUnavailable::class,

            default => Exceptions\PaymentsException::class,
        };

        return new $class($message, $type, $response->status(), $details, $correlation);
    }

    /**
     * A failure that did not come from the platform at all.
     *
     * A proxy, a load balancer or a maintenance page in front of it
     * answers with a status and no `error` member. Naming that as its own
     * shape matters: "the orchestrator refused this" and "something
     * between you and it answered instead" send an integrator to
     * completely different places.
     */
    private static function typeForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'invalid_client',
            $status === 403 => 'insufficient_scope',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'upstream_error',
            default => 'unexpected_response',
        };
    }

    private static function messageForStatus(int $status): string
    {
        return $status >= 500
            ? sprintf(
                'The orchestrator, or something in front of it, answered %d with no error body. If this '
                . 'persists it is the platform\'s to fix, not yours to retry around.',
                $status,
            )
            : sprintf('The orchestrator answered %d with no error body.', $status);
    }
}
