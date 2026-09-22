<?php

declare(strict_types=1);

namespace Ctpl\Payments\Facades;

use Ctpl\Payments\Data\Customer;
use Ctpl\Payments\Data\Money;
use Ctpl\Payments\Data\PaymentLink;
use Ctpl\Payments\Data\PaymentOrder;
use Ctpl\Payments\Data\Refund;
use Ctpl\Payments\Enums\PaymentMethodType;
use Ctpl\Payments\PaymentsManager;
use Ctpl\Payments\Testing\PaymentsFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PaymentOrder createOrder(string $reference, Money $amount, ?Customer $customer = null, ?string $description = null, ?string $purpose = null, ?string $invoiceReference = null, array $metadata = [], ?string $idempotencyKey = null)
 * @method static PaymentOrder openAttempt(string|PaymentOrder $order, ?PaymentMethodType $method = null, ?string $idempotencyKey = null)
 * @method static PaymentOrder pay(string $reference, Money $amount, ?Customer $customer = null, ?PaymentMethodType $method = null, ?string $description = null, array $metadata = [])
 * @method static PaymentOrder order(string|PaymentOrder $order)
 * @method static PaymentOrder confirm(string|PaymentOrder $order, ?int $timeoutSeconds = null)
 * @method static Refund refund(string|PaymentOrder $order, ?Money $amount = null, ?string $reason = null, ?string $idempotencyKey = null)
 * @method static Refund refundStatus(string|Refund $refund)
 * @method static array refunds(string|PaymentOrder $order)
 * @method static PaymentLink createLink(string $title, ?int $maxUses, ?Money $amount = null, ?Customer $customer = null, ?string $description = null, ?string $reference = null, ?Money $minimumAmount = null, ?Money $maximumAmount = null, ?\DateTimeInterface $expiresAt = null, ?string $idempotencyKey = null)
 * @method static array links(?string $reference = null, ?string $status = null)
 * @method static PaymentLink link(string|PaymentLink $link)
 * @method static PaymentLink withdrawLink(string|PaymentLink $link, string $reason, ?string $idempotencyKey = null)
 * @method static array paymentMethods()
 * @method static array gatewayStatus()
 *
 * @see PaymentsManager
 */
final class Payments extends Facade
{
    /**
     * Swap the real manager for one that answers from a script.
     *
     * For YOUR tests, so that testing your checkout controller does not
     * need a payment orchestrator, a network, or a gateway. See
     * `PaymentsFake` for what it can be told to do — including the
     * failures worth having a branch for.
     */
    public static function fake(): PaymentsFake
    {
        $fake = new PaymentsFake();

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'ctpl.payments';
    }
}
