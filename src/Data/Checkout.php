<?php

declare(strict_types=1);

namespace Ctpl\Payments\Data;

use Ctpl\Payments\Enums\CheckoutType;
use DateTimeImmutable;

/**
 * What to put in front of the customer.
 *
 * The one-time `token` is a credential for this checkout session and is
 * safe for a browser to hold — it is scoped to one payment, expires with
 * the session, and cannot be used to read anything else. It is not your
 * API token and must never be confused with it.
 *
 * Read `type` and branch, or hand the whole thing to `CheckoutResponder`
 * and let it decide.
 */
final readonly class Checkout
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $sessionId,
        public CheckoutType $type,

        /**
         * Which gateway this payment was routed to, as the platform
         * names it — `RAZORPAY`, `CASHFREE`, `PAYU`, `PHONEPE`.
         *
         * Null from an orchestrator that predates it, in which case
         * anything rendering a checkout is back to guessing from the
         * payload's shape. It is worth not guessing: a payload that
         * gains a field, or a gateway whose shape overlaps another's,
         * silently loads the wrong script in front of a paying customer.
         */
        public ?string $provider = null,
        public string $token,
        public ?string $redirectUrl = null,
        public ?string $publicKey = null,
        public array $payload = [],
        public ?DateTimeImmutable $expiresAt = null,
        public ?int $expiresIn = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromApi(array $data): self
    {
        return new self(
            sessionId: (string) ($data['payment_session_id'] ?? ''),
            // An unrecognised type is a redirect rather than a crash: a
            // platform that adds one should not take an integration down,
            // and `redirect_url` is present on every type that has ever
            // shipped.
            type: CheckoutType::tryFrom((string) ($data['type'] ?? '')) ?? CheckoutType::Redirect,
            provider: isset($data['provider']) ? (string) $data['provider'] : null,
            token: (string) ($data['token'] ?? ''),
            redirectUrl: isset($data['redirect_url']) ? (string) $data['redirect_url'] : null,
            publicKey: isset($data['public_key']) ? (string) $data['public_key'] : null,
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            expiresAt: isset($data['expires_at']) ? new DateTimeImmutable((string) $data['expires_at']) : null,
            expiresIn: isset($data['expires_in']) ? (int) $data['expires_in'] : null,
        );
    }

    public function hasExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new DateTimeImmutable();
    }
}
