<?php

declare(strict_types=1);

namespace Ctpl\Payments\Data;

/**
 * Who is paying.
 *
 * `externalId` is YOUR id for the person and is what ties their payments
 * together on the platform. Keep it stable: a new value for the same
 * person creates a second customer record, and support then cannot see
 * that the two payments were the same human.
 */
final readonly class Customer
{
    public function __construct(
        public string $externalId,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'external_customer_id' => $this->externalId,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
    }

    /** @param array<string, mixed> $data */
    public static function fromApi(array $data): self
    {
        return new self(
            externalId: (string) ($data['external_customer_id'] ?? ''),
            name: isset($data['name']) ? (string) $data['name'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
        );
    }
}
