<?php

declare(strict_types=1);

namespace Ctpl\Payments\Enums;

/**
 * Where a refund is.
 *
 * Raising one is a request, not a payout: the money moves on the gateway's
 * schedule, and a refund that is accepted is not a refund that has
 * arrived. `isSettled()` is the only safe basis for telling a customer
 * their money is back.
 *
 * Unknown values are tolerated rather than fatal — see `from()`. The
 * platform may add a state before this package is updated, and an SDK
 * that throws on an unrecognised refund state would take an application
 * down over a label.
 */
enum RefundState: string
{
    case Requested = 'REQUESTED';
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Unknown = 'UNKNOWN';

    public static function fromApi(?string $value): self
    {
        return $value === null ? self::Unknown : (self::tryFrom($value) ?? self::Unknown);
    }

    /** The money is definitively back with the customer. */
    public function isComplete(): bool
    {
        return $this === self::Completed;
    }

    /** Nothing further will happen on its own. */
    public function isSettled(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Rejected], true);
    }
}
