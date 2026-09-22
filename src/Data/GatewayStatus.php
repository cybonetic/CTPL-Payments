<?php

declare(strict_types=1);

namespace Ctpl\Payments\Data;

/**
 * Whether one gateway account can take a payment right now.
 *
 * Deliberately no success rates: the platform publishes a verdict and a
 * reason, never the arithmetic behind them, because an integration that
 * built its own routing on those numbers would be a second system
 * choosing gateways. Use this for a status page or to explain an outage —
 * not to pick a gateway.
 */
final readonly class GatewayStatus
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $environment,
        public string $status,
        public string $health,
        public bool $acceptingPayments,
        public ?string $reason = null,
        public bool $measured = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromApi(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            environment: (string) ($data['environment'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            health: (string) ($data['health'] ?? ''),
            acceptingPayments: (bool) ($data['accepting_payments'] ?? false),
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            measured: (bool) ($data['measured'] ?? false),
        );
    }
}
