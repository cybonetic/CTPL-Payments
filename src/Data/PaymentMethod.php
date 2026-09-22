<?php

declare(strict_types=1);

namespace Ctpl\Payments\Data;

final readonly class PaymentMethod
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromApi(array $data): self
    {
        return new self(
            code: (string) $data['code'],
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
        );
    }
}
