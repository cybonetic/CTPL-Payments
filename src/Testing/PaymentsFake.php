<?php

declare(strict_types=1);

namespace Ctpl\Payments\Testing;

use Ctpl\Payments\Data\Checkout;
use Ctpl\Payments\Data\Customer;
use Ctpl\Payments\Data\Money;
use Ctpl\Payments\Data\PaymentLink;
use Ctpl\Payments\Data\PaymentMethod;
use Ctpl\Payments\Data\PaymentOrder;
use Ctpl\Payments\Data\Refund;
use Ctpl\Payments\Enums\PaymentLinkStatus;
use Ctpl\Payments\Enums\PaymentMethodType;
use Ctpl\Payments\Enums\PaymentStatus;
use Ctpl\Payments\Enums\RefundState;
use Ctpl\Payments\Exceptions\PaymentsException;
use Ctpl\Payments\Exceptions\UnresolvedAttempt;
use DateTimeInterface;
use PHPUnit\Framework\Assert;

/**
 * `Payments::fake()` — for the consuming application's own tests.
 *
 * ------------------------------------------------------------------
 *  WHY THIS SHIPS WITH THE PACKAGE
 * ------------------------------------------------------------------
 *
 * Without it, an application testing its checkout controller has three
 * bad options: hit a real orchestrator (slow, needs credentials, creates
 * real orders), mock the HTTP layer (tests that assert JSON shapes this
 * package already owns), or not test the payment path at all. The third
 * is what usually happens.
 *
 * So the fake answers as the platform would, and — more usefully — can be
 * told to answer as the platform does on a bad day. The failures worth
 * having a branch for are the ones nobody can reproduce on demand:
 *
 *     Payments::fake()->willBeDeclined();
 *     Payments::fake()->willBeUnresolved();   // do NOT retry
 *     Payments::fake()->willFailWith(new RateLimited(...));
 *
 * `assertPaid()` and friends make the assertion read as the thing being
 * asserted rather than as an inspection of recorded calls.
 */
final class PaymentsFake
{
    /** @var list<array{method: string, arguments: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, PaymentOrder> */
    private array $orders = [];

    /** @var array<string, Refund> */
    private array $refunds = [];

    /** @var array<string, PaymentLink> */
    private array $links = [];

    private PaymentStatus $nextStatus = PaymentStatus::Initiated;

    private PaymentStatus $confirmsAs = PaymentStatus::Captured;

    private ?PaymentsException $nextFailure = null;

    private ?string $failOn = null;

    // -----------------------------------------------------------------
    // Telling it how to behave
    // -----------------------------------------------------------------

    /** The default: an attempt opens, and confirming it captures. */
    public function willSucceed(): self
    {
        $this->nextStatus = PaymentStatus::Initiated;
        $this->confirmsAs = PaymentStatus::Captured;
        $this->nextFailure = null;

        return $this;
    }

    /** The customer's bank says no. Confirming settles as FAILED. */
    public function willBeDeclined(): self
    {
        $this->nextStatus = PaymentStatus::Initiated;
        $this->confirmsAs = PaymentStatus::Failed;
        $this->nextFailure = null;

        return $this;
    }

    /** Nobody paid within the window. */
    public function willExpire(): self
    {
        $this->nextStatus = PaymentStatus::Initiated;
        $this->confirmsAs = PaymentStatus::Expired;
        $this->nextFailure = null;

        return $this;
    }

    /**
     * The gateway timed out and the customer may have been charged.
     *
     * Opening another attempt throws `UnresolvedAttempt`. This is the
     * case worth writing a test for, because the instinctive handling —
     * catch the 409, retry — is the one that charges somebody twice, and
     * a test is the only thing that will tell you your code does it.
     */
    public function willBeUnresolved(): self
    {
        $this->nextStatus = PaymentStatus::Unknown;
        $this->confirmsAs = PaymentStatus::Unknown;
        $this->nextFailure = new UnresolvedAttempt(
            'An earlier attempt on this order has not resolved. Do not retry — poll the payment.',
            type: 'unresolved_attempt',
            status: 409,
        );
        $this->failOn = 'openAttempt';

        return $this;
    }

    /** Any failure you like, on the next call to `$method`. */
    public function willFailWith(PaymentsException $exception, string $method = 'openAttempt'): self
    {
        $this->nextFailure = $exception;
        $this->failOn = $method;

        return $this;
    }

    // -----------------------------------------------------------------
    // The surface the application calls
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $metadata */
    public function createOrder(
        string $reference,
        Money $amount,
        ?Customer $customer = null,
        ?string $description = null,
        ?string $purpose = null,
        ?string $invoiceReference = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): PaymentOrder {
        $this->record('createOrder', compact('reference', 'amount', 'customer', 'description', 'metadata'));
        $this->maybeFail('createOrder');

        return $this->orders[$reference] = $this->order(
            reference: $reference,
            amount: $amount,
            status: PaymentStatus::Created,
            customer: $customer,
            metadata: $metadata,
        );
    }

    public function openAttempt(
        string|PaymentOrder $order,
        ?PaymentMethodType $method = null,
        ?string $idempotencyKey = null,
    ): PaymentOrder {
        $this->record('openAttempt', ['order' => $order, 'method' => $method]);
        $this->maybeFail('openAttempt');

        $existing = $this->find($order);

        return $this->orders[$existing->externalReference] = $this->order(
            reference: $existing->externalReference,
            amount: $existing->amount,
            status: $this->nextStatus,
            customer: $existing->customer,
            metadata: $existing->metadata,
            withCheckout: true,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function pay(
        string $reference,
        Money $amount,
        ?Customer $customer = null,
        ?PaymentMethodType $method = null,
        ?string $description = null,
        array $metadata = [],
    ): PaymentOrder {
        $this->createOrder($reference, $amount, $customer, $description, metadata: $metadata);

        return $this->openAttempt($reference, $method);
    }

    public function order(
        string|PaymentOrder $order = '',
        ?Money $amount = null,
        ?PaymentStatus $status = null,
        ?Customer $customer = null,
        array $metadata = [],
        bool $withCheckout = false,
        ?string $reference = null,
    ): PaymentOrder {
        // Two jobs, one name, because the facade's `order()` is a read and
        // this class also builds them. A non-empty first argument is a
        // read; everything else is construction.
        if ($order !== '' && $reference === null) {
            $this->record('order', ['order' => $order]);

            return $this->find($order);
        }

        $reference ??= is_string($order) && $order !== '' ? $order : 'FAKE-REF';
        $amount ??= Money::minor(100_00);
        $status ??= PaymentStatus::Created;

        $checkout = $withCheckout ? [
            'payment_session_id' => 'ps_' . md5($reference),
            'type' => 'redirect',
            'token' => 'pst_' . md5('token' . $reference),
            'redirect_url' => 'https://gateway.invalid/checkout/' . md5($reference),
            'expires_in' => 900,
        ] : null;

        $isPaid = $status->isPaid();

        return PaymentOrder::fromApi([
            'payment_order_id' => 'po_' . substr(md5($reference), 0, 8) . '-0000-4000-8000-000000000000',
            'external_reference' => $reference,
            'status' => $status->value,
            'amount' => $amount->minorUnits,
            'amount_paid' => $isPaid ? $amount->minorUnits : 0,
            'amount_refunded' => 0,
            'currency' => $amount->currency,
            'can_retry' => ! $status->isSettled() && $status !== PaymentStatus::Unknown
                && $status !== PaymentStatus::Initiated,
            'attempt_count' => $withCheckout ? 1 : 0,
            'max_attempts' => 5,
            'customer' => $customer === null ? null : $customer->toArray(),
            'metadata' => $metadata,
            'attempts' => $withCheckout ? [[
                'payment_attempt_id' => 'pa_' . substr(md5($reference), 0, 8) . '-0000-4000-8000-000000000000',
                'attempt_number' => 1,
                'status' => $status->value,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency,
                'checkout' => $checkout,
            ]] : [],
            'created_at' => '2026-01-01T00:00:00+00:00',
        ], $checkout);
    }

    public function confirm(string|PaymentOrder $order, ?int $timeoutSeconds = null): PaymentOrder
    {
        $this->record('confirm', ['order' => $order]);
        $this->maybeFail('confirm');

        $existing = $this->find($order);

        return $this->orders[$existing->externalReference] = $this->order(
            reference: $existing->externalReference,
            amount: $existing->amount,
            status: $this->confirmsAs,
            customer: $existing->customer,
            metadata: $existing->metadata,
            withCheckout: true,
        );
    }

    public function refund(
        string|PaymentOrder $order,
        ?Money $amount = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): Refund {
        $this->maybeFail('refund');

        $existing = $this->find($order);

        /*
         * The amount RESOLVED, not the argument.
         *
         * `refund($order)` with no amount is a full refund, and recording
         * the literal `null` meant `assertRefunded($theFullAmount)` failed
         * against exactly the call it was written for. An assertion helper
         * that only works when you pass the optional argument is a helper
         * nobody uses twice.
         */
        $amount ??= $existing->amount;

        $this->record('refund', ['order' => $order, 'amount' => $amount, 'reason' => $reason]);

        $refund = Refund::fromApi([
            'refund_id' => 'rfd_' . substr(md5($existing->id . $amount->minorUnits), 0, 8)
                . '-0000-4000-8000-000000000000',
            'payment_order_id' => $existing->id,
            'amount' => $amount->minorUnits,
            'currency' => $amount->currency,
            'state' => RefundState::Requested->value,
            'state_description' => 'Requested',
            'reason' => $reason,
        ]);

        return $this->refunds[$refund->id] = $refund;
    }

    public function refundStatus(string|Refund $refund): Refund
    {
        $id = $refund instanceof Refund ? $refund->id : $refund;
        $this->record('refundStatus', ['refund' => $id]);

        return $this->refunds[$id] ?? throw new PaymentsException('No such refund in the fake.', type: 'not_found');
    }

    /** @return list<Refund> */
    public function refunds(string|PaymentOrder $order): array
    {
        $id = $this->find($order)->id;
        $this->record('refunds', ['order' => $id]);

        return array_values(array_filter(
            $this->refunds,
            static fn (Refund $r): bool => $r->paymentOrderId === $id,
        ));
    }

    public function createLink(
        string $title,
        ?int $maxUses,
        ?Money $amount = null,
        ?Customer $customer = null,
        ?string $description = null,
        ?string $reference = null,
        ?Money $minimumAmount = null,
        ?Money $maximumAmount = null,
        ?DateTimeInterface $expiresAt = null,
        ?string $idempotencyKey = null,
    ): PaymentLink {
        $this->record('createLink', ['title' => $title, 'max_uses' => $maxUses, 'amount' => $amount]);
        $this->maybeFail('createLink');

        $amount ??= Money::minor(100_00);
        $id = 'pln_' . substr(md5($title . $reference), 0, 8) . '-0000-4000-8000-000000000000';

        return $this->links[$id] = PaymentLink::fromApi([
            'payment_link_id' => $id,
            'title' => $title,
            'status' => PaymentLinkStatus::Active->value,
            'currency' => $amount->currency,
            'amount' => $amount->minorUnits,
            'single_use' => $maxUses === 1,
            'max_uses' => $maxUses,
            'payments' => 0,
            'reference' => $reference,
            // Only ever on creation, as on the real platform.
            'url' => 'https://pay.invalid/pay/' . substr(md5($id), 0, 22),
        ]);
    }

    /** @return list<PaymentLink> */
    public function links(?string $reference = null, ?string $status = null): array
    {
        $this->record('links', ['reference' => $reference, 'status' => $status]);

        return array_values($this->links);
    }

    public function link(string|PaymentLink $link): PaymentLink
    {
        $id = $link instanceof PaymentLink ? $link->id : $link;

        return $this->links[$id] ?? throw new PaymentsException('No such link in the fake.', type: 'not_found');
    }

    public function withdrawLink(string|PaymentLink $link, string $reason, ?string $idempotencyKey = null): PaymentLink
    {
        $existing = $this->link($link);
        $this->record('withdrawLink', ['link' => $existing->id, 'reason' => $reason]);

        return $this->links[$existing->id] = PaymentLink::fromApi([
            'payment_link_id' => $existing->id,
            'title' => $existing->title,
            'status' => PaymentLinkStatus::Disabled->value,
            'currency' => $existing->currency,
            'single_use' => $existing->singleUse,
            'payments' => $existing->payments,
        ]);
    }

    /** @return list<PaymentMethod> */
    public function paymentMethods(): array
    {
        $this->record('paymentMethods', []);

        return [
            new PaymentMethod('UPI', 'UPI', 'Unified Payments Interface'),
            new PaymentMethod('CARD', 'Card', 'Credit and debit cards'),
        ];
    }

    /** @return list<\Ctpl\Payments\Data\GatewayStatus> */
    public function gatewayStatus(): array
    {
        $this->record('gatewayStatus', []);

        return [new \Ctpl\Payments\Data\GatewayStatus(
            id: 'gwa_fake', provider: 'SANDBOX', environment: 'DEVELOPMENT',
            status: 'ACTIVE', health: 'HEALTHY', acceptingPayments: true,
        )];
    }

    // -----------------------------------------------------------------
    // Assertions
    // -----------------------------------------------------------------

    public function assertOrderCreated(?string $reference = null): self
    {
        return $this->assertCalled('createOrder', $reference === null ? null
            : static fn (array $a): bool => ($a['reference'] ?? null) === $reference);
    }

    public function assertAttemptOpened(): self
    {
        return $this->assertCalled('openAttempt');
    }

    /** The application actually asked the platform, rather than believing a redirect. */
    public function assertConfirmed(): self
    {
        return $this->assertCalled('confirm');
    }

    public function assertRefunded(?Money $amount = null): self
    {
        return $this->assertCalled('refund', $amount === null ? null
            : static fn (array $a): bool => ($a['amount'] ?? null)?->equals($amount) === true);
    }

    public function assertNothingCharged(): self
    {
        Assert::assertSame(
            [],
            array_values(array_filter(
                $this->calls,
                static fn (array $c): bool => in_array($c['method'], ['createOrder', 'openAttempt', 'pay'], true),
            )),
            'Expected no payment to have been started, and one was.',
        );

        return $this;
    }

    /** @param (callable(array<string, mixed>): bool)|null $matching */
    public function assertCalled(string $method, ?callable $matching = null): self
    {
        $matches = array_filter(
            $this->calls,
            static fn (array $c): bool => $c['method'] === $method
                && ($matching === null || $matching($c['arguments'])),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected [%s] to have been called%s. Calls made: %s',
            $method,
            $matching === null ? '' : ' with matching arguments',
            $this->calls === [] ? 'none' : implode(', ', array_column($this->calls, 'method')),
        ));

        return $this;
    }

    public function assertNotCalled(string $method): self
    {
        Assert::assertEmpty(
            array_filter($this->calls, static fn (array $c): bool => $c['method'] === $method),
            sprintf('Expected [%s] not to have been called, and it was.', $method),
        );

        return $this;
    }

    /** @return list<array{method: string, arguments: array<string, mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    // -----------------------------------------------------------------

    /** @param array<string, mixed> $arguments */
    private function record(string $method, array $arguments): void
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];
    }

    private function maybeFail(string $method): void
    {
        if ($this->nextFailure !== null && $this->failOn === $method) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }
    }

    private function find(string|PaymentOrder $order): PaymentOrder
    {
        if ($order instanceof PaymentOrder) {
            return $order;
        }

        foreach ($this->orders as $known) {
            if ($known->id === $order || $known->externalReference === $order) {
                return $known;
            }
        }

        return $this->orders[$order] ?? $this->order(reference: $order, status: PaymentStatus::Created);
    }
}
