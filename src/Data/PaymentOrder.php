<?php

declare(strict_types=1);

namespace Ctpl\Payments\Data;

use Ctpl\Payments\Enums\PaymentStatus;
use DateTimeImmutable;

/**
 * The invoice: one amount, one customer, one thing being paid for.
 *
 * `status` is the order's, and the order stays open while a retry is
 * genuinely possible — an attempt failing is not the invoice failing,
 * which is the whole reason orders and attempts are separate. When you
 * want "what happened to this payment" rather than "is this invoice still
 * open", `outcome()` answers that.
 */
final readonly class PaymentOrder
{
    /**
     * @param list<PaymentAttempt> $attempts
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $externalReference,
        public PaymentStatus $status,
        public Money $amount,
        public Money $amountPaid,
        public Money $amountRefunded,
        public bool $canRetry,
        public int $attemptCount,
        public int $maxAttempts,
        public ?string $invoiceReference = null,
        public ?string $purpose = null,
        public ?string $description = null,
        public ?Customer $customer = null,
        public array $attempts = [],
        public array $metadata = [],
        public ?Checkout $checkout = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $capturedAt = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data     The `data` member of the response.
     * @param array<string, mixed>|null $topLevelCheckout The response's own `checkout`, if any.
     */
    public static function fromApi(array $data, ?array $topLevelCheckout = null): self
    {
        $currency = (string) $data['currency'];

        $attempts = array_map(
            static fn (array $a): PaymentAttempt => PaymentAttempt::fromApi($a),
            is_array($data['attempts'] ?? null) ? $data['attempts'] : [],
        );

        return new self(
            id: (string) $data['payment_order_id'],
            externalReference: (string) ($data['external_reference'] ?? ''),
            status: PaymentStatus::from((string) $data['status']),
            amount: Money::minor((int) $data['amount'], $currency),
            amountPaid: Money::minor((int) ($data['amount_paid'] ?? 0), $currency),
            amountRefunded: Money::minor((int) ($data['amount_refunded'] ?? 0), $currency),
            canRetry: (bool) ($data['can_retry'] ?? false),
            attemptCount: (int) ($data['attempt_count'] ?? 0),
            maxAttempts: (int) ($data['max_attempts'] ?? 0),
            invoiceReference: isset($data['invoice_reference']) ? (string) $data['invoice_reference'] : null,
            purpose: isset($data['purpose']) ? (string) $data['purpose'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            customer: is_array($data['customer'] ?? null) ? Customer::fromApi($data['customer']) : null,
            attempts: $attempts,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            checkout: $topLevelCheckout !== null ? Checkout::fromApi($topLevelCheckout) : null,
            expiresAt: isset($data['expires_at']) ? new DateTimeImmutable((string) $data['expires_at']) : null,
            capturedAt: isset($data['captured_at']) ? new DateTimeImmutable((string) $data['captured_at']) : null,
            createdAt: isset($data['created_at']) ? new DateTimeImmutable((string) $data['created_at']) : null,
        );
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    public function latestAttempt(): ?PaymentAttempt
    {
        return $this->attempts === [] ? null : $this->attempts[array_key_last($this->attempts)];
    }

    /**
     * What happened to this payment, as opposed to what the invoice is doing.
     *
     * A settled or closed order speaks for itself. An OPEN one is
     * described by its attempts: an order whose last attempt the bank
     * refused reads `INITIATED` — correctly, because another gateway may
     * still be tried — and telling a customer "initiated" when their card
     * was declined is the wrong half of the truth.
     */
    public function outcome(): PaymentStatus
    {
        if ($this->status->isSettled()) {
            return $this->status;
        }

        $live = array_filter(
            $this->attempts,
            static fn (PaymentAttempt $a): bool => ! $a->status->isSettled(),
        );

        if ($live !== [] || $this->attempts === []) {
            return $this->status;
        }

        return $this->latestAttempt()?->status ?? $this->status;
    }

    /** The reason the last attempt failed, for a message to the customer. */
    public function failureMessage(): ?string
    {
        return $this->latestAttempt()?->failureMessage;
    }
}
