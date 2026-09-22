<?php

declare(strict_types=1);

namespace Ctpl\Payments\Webhooks;

use Ctpl\Payments\Data\Money;
use DateTimeImmutable;

/**
 * One event from the platform, verified.
 *
 * The envelope is flat and identical on every event type — `event`,
 * `event_id`, `occurred_at`, the ids and the amount — with the type's own
 * fields alongside it. `get()` reaches those without a consumer having to
 * know which are which.
 *
 * Nullable envelope fields are present-and-null rather than absent, and
 * that distinction is deliberate on the platform's side: a null says
 * "there is none", an absent key would say "the producer forgot", and a
 * consumer cannot tell those apart unless the platform is consistent.
 */
final readonly class PlatformEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $type,
        public string $id,
        public int $version,
        public DateTimeImmutable $occurredAt,
        public array $payload,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            type: (string) ($payload['event'] ?? 'unknown'),
            id: (string) ($payload['event_id'] ?? ''),
            version: (int) ($payload['event_version'] ?? 1),
            occurredAt: new DateTimeImmutable((string) ($payload['occurred_at'] ?? 'now')),
            payload: $payload,
        );
    }

    public function paymentOrderId(): ?string
    {
        return $this->string('payment_order_id');
    }

    public function paymentAttemptId(): ?string
    {
        return $this->string('payment_attempt_id');
    }

    /** Your own reference for the payment — usually what you look up by. */
    public function externalReference(): ?string
    {
        return $this->string('external_reference');
    }

    public function application(): ?string
    {
        return $this->string('application');
    }

    public function amount(): ?Money
    {
        $amount = $this->payload['amount'] ?? null;
        $currency = $this->string('currency');

        return is_int($amount) && $currency !== null ? Money::minor($amount, $currency) : null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * Is this event about money having arrived?
     *
     * Even so: an event is a prompt to go and look, not the answer. The
     * platform's own rule is that capture is confirmed by a server-side
     * status query and never by a message — so on `payment.captured`, call
     * `Payments::order()` and act on what that says. A forged event cannot
     * survive the signature check, but a stale one can still arrive after
     * a refund, and the order is the only thing that is current.
     */
    public function isCapture(): bool
    {
        return $this->type === 'payment.captured';
    }

    private function string(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
