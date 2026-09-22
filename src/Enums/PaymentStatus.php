<?php

declare(strict_types=1);

namespace Ctpl\Payments\Enums;

/**
 * Where a payment is. The three questions worth asking of it are methods,
 * not string comparisons a caller writes for itself.
 *
 * `Unknown` is the one that catches people out: it is NOT a failure. It
 * means the gateway never said what happened, the customer may well have
 * been charged, and the platform is resolving it by asking the gateway
 * directly. Treating it as a failure is how a paid customer gets told
 * their payment did not work.
 */
enum PaymentStatus: string
{
    case Created = 'CREATED';
    case Pending = 'PENDING';
    case Initiated = 'INITIATED';
    case Authorized = 'AUTHORIZED';
    case Captured = 'CAPTURED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Refunded = 'REFUNDED';
    case Disputed = 'DISPUTED';
    case Chargeback = 'CHARGEBACK';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
    case Unknown = 'UNKNOWN';

    /** Money has moved in the merchant's favour. */
    public function isPaid(): bool
    {
        return in_array($this, [
            self::Captured, self::PartiallyRefunded, self::Refunded, self::Disputed,
        ], true);
    }

    /** Nothing further will happen on its own. Stop polling. */
    public function isSettled(): bool
    {
        return $this->isPaid() || in_array($this, [
            self::Failed, self::Expired, self::Cancelled, self::Chargeback,
        ], true);
    }

    /**
     * Definitively no money, and safe to tell the customer so.
     *
     * `Unknown` is deliberately absent: see the class docblock.
     */
    public function isFailure(): bool
    {
        return in_array($this, [self::Failed, self::Expired, self::Cancelled], true);
    }

    /** Human wording, for a screen or an email. */
    public function label(): string
    {
        return match ($this) {
            self::Created, self::Pending => 'Not started',
            self::Initiated, self::Authorized => 'In progress',
            self::Captured => 'Paid',
            self::PartiallyRefunded => 'Partly refunded',
            self::Refunded => 'Refunded',
            self::Disputed => 'Disputed',
            self::Chargeback => 'Charged back',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            // Never "failed", and never "paid".
            self::Unknown => 'Being confirmed',
        };
    }
}
