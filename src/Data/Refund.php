<?php

declare(strict_types=1);

namespace Ctpl\Payments\Data;

use Ctpl\Payments\Enums\RefundState;
use DateTimeImmutable;

/**
 * A refund request.
 *
 * Raising one does not move money; the gateway does, on its own schedule.
 * `state->isComplete()` is the only safe basis for telling a customer
 * their money is back.
 */
final readonly class Refund
{
    public function __construct(
        public string $id,
        public ?string $paymentOrderId,
        public Money $amount,
        public RefundState $state,
        public ?string $stateDescription = null,
        public ?string $reason = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?DateTimeImmutable $requestedAt = null,
        public ?DateTimeImmutable $completedAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromApi(array $data): self
    {
        return new self(
            id: (string) ($data['refund_id'] ?? ''),
            paymentOrderId: isset($data['payment_order_id']) ? (string) $data['payment_order_id'] : null,
            amount: Money::minor((int) $data['amount'], (string) $data['currency']),
            state: RefundState::fromApi(isset($data['state']) ? (string) $data['state'] : null),
            stateDescription: isset($data['state_description']) ? (string) $data['state_description'] : null,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            failureCode: isset($data['failure_code']) ? (string) $data['failure_code'] : null,
            failureMessage: isset($data['failure_message']) ? (string) $data['failure_message'] : null,
            requestedAt: isset($data['requested_at']) ? new DateTimeImmutable((string) $data['requested_at']) : null,
            completedAt: isset($data['completed_at']) ? new DateTimeImmutable((string) $data['completed_at']) : null,
        );
    }
}
