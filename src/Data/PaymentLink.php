<?php

declare(strict_types=1);

namespace Ctpl\Payments\Data;

use Ctpl\Payments\Enums\PaymentLinkStatus;
use DateTimeImmutable;

/**
 * A page a customer can be sent, that takes a payment.
 *
 * `url` is the payable address and is returned ONLY when the link is
 * created. Store it then if you need it; a listing deliberately does not
 * carry it, because a response containing twenty payable addresses is one
 * that ends up in a log. Treat it like a key: whoever holds it can start
 * a payment.
 */
final readonly class PaymentLink
{
    public function __construct(
        public string $id,
        public string $title,
        public PaymentLinkStatus $status,
        public string $currency,
        public bool $singleUse,
        public int $payments,
        public ?string $url = null,
        public ?Money $amount = null,
        public ?Money $minimumAmount = null,
        public ?Money $maximumAmount = null,
        public ?int $maxUses = null,
        public ?string $description = null,
        public ?string $reference = null,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerPhone = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromApi(array $data): self
    {
        $currency = (string) ($data['currency'] ?? 'INR');
        $money = static fn (string $key): ?Money => isset($data[$key])
            ? Money::minor((int) $data[$key], $currency)
            : null;

        return new self(
            id: (string) $data['payment_link_id'],
            title: (string) ($data['title'] ?? ''),
            status: PaymentLinkStatus::from((string) $data['status']),
            currency: $currency,
            singleUse: (bool) ($data['single_use'] ?? false),
            payments: (int) ($data['payments'] ?? 0),
            url: isset($data['url']) ? (string) $data['url'] : null,
            amount: $money('amount'),
            minimumAmount: $money('minimum_amount'),
            maximumAmount: $money('maximum_amount'),
            maxUses: isset($data['max_uses']) ? (int) $data['max_uses'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            customerName: isset($data['customer_name']) ? (string) $data['customer_name'] : null,
            customerEmail: isset($data['customer_email']) ? (string) $data['customer_email'] : null,
            customerPhone: isset($data['customer_phone']) ? (string) $data['customer_phone'] : null,
            expiresAt: isset($data['expires_at']) ? new DateTimeImmutable((string) $data['expires_at']) : null,
            createdAt: isset($data['created_at']) ? new DateTimeImmutable((string) $data['created_at']) : null,
        );
    }
}
