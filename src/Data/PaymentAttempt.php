<?php

declare(strict_types=1);

namespace Ctpl\Payments\Data;

use Ctpl\Payments\Enums\PaymentStatus;
use DateTimeImmutable;

/** One conversation with one gateway about one order. */
final readonly class PaymentAttempt
{
    public function __construct(
        public string $id,
        public int $number,
        public PaymentStatus $status,
        public Money $amount,
        public ?string $paymentMethod = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public bool $isBeingVerified = false,
        public ?Checkout $checkout = null,
        public ?DateTimeImmutable $initiatedAt = null,
        public ?DateTimeImmutable $capturedAt = null,
        public ?DateTimeImmutable $failedAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromApi(array $data): self
    {
        return new self(
            id: (string) ($data['payment_attempt_id'] ?? ''),
            number: (int) ($data['attempt_number'] ?? 0),
            status: PaymentStatus::from((string) $data['status']),
            amount: Money::minor((int) $data['amount'], (string) $data['currency']),
            paymentMethod: isset($data['payment_method']) ? (string) $data['payment_method'] : null,
            failureCode: isset($data['failure_code']) ? (string) $data['failure_code'] : null,
            failureMessage: isset($data['failure_message']) ? (string) $data['failure_message'] : null,
            isBeingVerified: (bool) ($data['is_being_verified'] ?? false),
            checkout: is_array($data['checkout'] ?? null) ? Checkout::fromApi($data['checkout']) : null,
            initiatedAt: isset($data['initiated_at']) ? new DateTimeImmutable((string) $data['initiated_at']) : null,
            capturedAt: isset($data['captured_at']) ? new DateTimeImmutable((string) $data['captured_at']) : null,
            failedAt: isset($data['failed_at']) ? new DateTimeImmutable((string) $data['failed_at']) : null,
        );
    }
}
