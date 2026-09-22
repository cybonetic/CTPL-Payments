<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Integration;

use Ctpl\Payments\Checkout\CheckoutResponder;
use Ctpl\Payments\Data\Customer;
use Ctpl\Payments\Data\Money;
use Ctpl\Payments\Enums\PaymentMethodType;
use Ctpl\Payments\Enums\PaymentStatus;
use Ctpl\Payments\Enums\RefundState;
use Ctpl\Payments\Exceptions;
use Ctpl\Payments\PaymentsManager;
use Ctpl\Payments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

/**
 * The SDK against a real orchestrator, over a real network.
 *
 * ------------------------------------------------------------------
 *  WHY THE OTHER TESTS ARE NOT ENOUGH
 * ------------------------------------------------------------------
 *
 * Everything in tests/Feature runs against `Http::fake()`, which answers
 * with bodies this package wrote. That proves the SDK is internally
 * consistent and proves nothing at all about whether the platform agrees
 * — a renamed field, a status code that is 201 where the SDK expected
 * 200, a `checkout` block that moved: every one of those passes a faked
 * suite and fails on the first real payment.
 *
 * So this takes a real payment: create, open an attempt, get a real
 * checkout, wait for a real server-side confirmation, refund it, make a
 * link, withdraw it. Nothing is stubbed.
 *
 * Not in the default suite, deliberately — a suite that needs a running
 * platform is a suite people stop running. `tools/verify_sdk.sh` runs it,
 * against an install it has checked is up.
 *
 * Needs:
 *   CTPL_PAYMENTS_URL, CTPL_PAYMENTS_CLIENT_ID, CTPL_PAYMENTS_CLIENT_SECRET
 *   CTPL_TEST_AMOUNT — must match what the sandbox's status query reports
 */
final class AgainstRealOrchestratorTest extends TestCase
{
    private static ?string $paidOrderId = null;

    protected function defineEnvironment($app): void
    {
        foreach (['CTPL_PAYMENTS_URL', 'CTPL_PAYMENTS_CLIENT_ID', 'CTPL_PAYMENTS_CLIENT_SECRET'] as $key) {
            if ((string) getenv($key) === '') {
                $this->markTestSkipped($key . ' is not set; run this through tools/verify_sdk.sh');
            }
        }

        $app['config']->set('ctpl-payments.base_url', (string) getenv('CTPL_PAYMENTS_URL'));
        $app['config']->set('ctpl-payments.client_id', (string) getenv('CTPL_PAYMENTS_CLIENT_ID'));
        $app['config']->set('ctpl-payments.client_secret', (string) getenv('CTPL_PAYMENTS_CLIENT_SECRET'));
        $app['config']->set('ctpl-payments.confirm.timeout_seconds', 60);

        /*
         * A SHARED cache store, and this suite is why the package's
         * config says so twice.
         *
         * Testbench builds a fresh application per test, so an `array`
         * store gives every test its own TokenStore and every test
         * fetches its own token. The token endpoint is throttled at
         * twenty a minute BY IP — it is the only one reachable without a
         * token — and this suite duly exhausted it and then reported
         * `invalid_client`, which reads exactly like bad credentials.
         *
         * That is not a quirk of the test harness: it is precisely what
         * happens to an application that caches its token in a per-process
         * store and then restarts its workers. Running the suite the way a
         * deployment should be configured means the suite exercises the
         * shared-token path rather than hiding the problem.
         */
        $app['config']->set('cache.default', 'file');
    }

    private function payments(): PaymentsManager
    {
        return $this->app->make(PaymentsManager::class);
    }

    private function amount(): Money
    {
        return Money::minor((int) (getenv('CTPL_TEST_AMOUNT') ?: 125000));
    }

    private function reference(string $what): string
    {
        return strtoupper($what) . '-' . bin2hex(random_bytes(4));
    }

    // -----------------------------------------------------------------

    #[Test]
    public function it_authenticates_and_reads_reference_data(): void
    {
        $methods = $this->payments()->paymentMethods();

        $this->assertNotEmpty($methods, 'the platform returned no payment methods');
        $this->assertContains('UPI', array_map(static fn ($m): string => $m->code, $methods));

        $gateways = $this->payments()->gatewayStatus();

        $this->assertNotEmpty($gateways);
        // The line the platform will not cross, checked from the outside.
        $this->assertObjectNotHasProperty('successRate', $gateways[0]);
    }

    #[Test]
    public function it_takes_a_payment_all_the_way_to_captured(): void
    {
        $reference = $this->reference('sdk-pay');

        $order = $this->payments()->pay(
            reference: $reference,
            amount: $this->amount(),
            customer: new Customer('sdk-customer-1', 'Asha Nair', 'asha@example.test', '+919000000001'),
            method: PaymentMethodType::Upi,
            description: 'SDK integration test',
            metadata: ['suite' => 'ctpl-payments-laravel'],
        );

        $this->assertSame($reference, $order->externalReference);
        $this->assertSame(PaymentStatus::Initiated, $order->status);
        $this->assertSame($this->amount()->minorUnits, $order->amount->minorUnits);

        // Rule R3, observed from the outside: nothing may open a second
        // attempt while this one is in flight.
        $this->assertFalse($order->canRetry);

        // A real checkout, for a real gateway, that the responder can use.
        $this->assertNotNull($order->checkout, 'no checkout came back');
        $this->assertNotSame('', $order->checkout->token);

        if (CheckoutResponder::isServerDriven($order)) {
            $response = CheckoutResponder::respond($order);
            $this->assertContains($response->getStatusCode(), [200, 302]);
        } else {
            $this->assertArrayHasKey('token', CheckoutResponder::payloadFor($order));
        }

        // And the part that matters: confirmation comes from the
        // platform's own server-side status query, not from anything the
        // SDK inferred.
        $confirmed = $this->payments()->confirm($order);

        $this->assertTrue(
            $confirmed->isPaid(),
            'the payment did not capture; it finished as ' . $confirmed->status->value
            . '. Is payments:confirm-pending running against the install under test?',
        );
        $this->assertSame($this->amount()->minorUnits, $confirmed->amountPaid->minorUnits);
        $this->assertNotNull($confirmed->capturedAt);

        self::$paidOrderId = $confirmed->id;
    }

    #[Test]
    #[Depends('it_takes_a_payment_all_the_way_to_captured')]
    public function it_refunds_the_payment_it_took(): void
    {
        $this->assertNotNull(self::$paidOrderId, 'no captured payment to refund');

        $refund = $this->payments()->refund(
            self::$paidOrderId,
            reason: 'SDK integration test',
        );

        $this->assertSame($this->amount()->minorUnits, $refund->amount->minorUnits);
        $this->assertSame(self::$paidOrderId, $refund->paymentOrderId);

        /*
         * The state is whatever the gateway says, and this test says so
         * rather than assuming.
         *
         * The first version asserted the refund could NOT be complete
         * the instant it was raised — reasoning that raising is a request
         * and the money moves later. That is true of most gateways and
         * not of all: the sandbox settles synchronously, and the
         * assertion failed against a platform behaving correctly.
         *
         * So what is asserted is the thing that is actually promised:
         * the state is one this SDK knows how to read, and `isComplete()`
         * is the only basis for telling a customer their money is back.
         */
        $this->assertNotSame(
            RefundState::Unknown,
            $refund->state,
            'the platform reported a refund state this SDK does not know: ' . $refund->stateDescription,
        );

        $fetched = $this->payments()->refundStatus($refund);
        $this->assertSame($refund->id, $fetched->id);

        $listed = $this->payments()->refunds(self::$paidOrderId);
        $this->assertContains($refund->id, array_map(static fn ($r): string => $r->id, $listed));
    }

    #[Test]
    public function an_idempotency_key_returns_the_same_payment_rather_than_a_second_one(): void
    {
        $reference = $this->reference('sdk-idem');
        $key = $reference . ':order';

        $first = $this->payments()->createOrder($reference, $this->amount(), idempotencyKey: $key);
        $second = $this->payments()->createOrder($reference, $this->amount(), idempotencyKey: $key);

        // The whole reason a retry after a timeout is safe.
        $this->assertSame($first->id, $second->id, 'the same key created two payments');
    }

    #[Test]
    public function a_payment_that_does_not_exist_is_a_typed_not_found(): void
    {
        $this->expectException(Exceptions\NotFound::class);

        $this->payments()->order('po_00000000-0000-4000-8000-000000000000');
    }

    #[Test]
    public function an_amount_the_platform_refuses_arrives_as_a_validation_failure(): void
    {
        try {
            // Zero is not an amount. The SDK's Money refuses negatives, so
            // this is the smallest thing the PLATFORM gets to refuse, which
            // is what is under test: that its refusal arrives typed.
            $this->payments()->createOrder($this->reference('sdk-bad'), Money::minor(0));
            $this->fail('the platform accepted a zero-amount order');
        } catch (Exceptions\ValidationFailed $e) {
            $this->assertNotNull($e->correlationId, 'a refusal with no correlation id cannot be supported');
            $this->assertStringContainsStringIgnoringCase('amount', json_encode($e->details) ?: '');
        }
    }

    #[Test]
    public function it_creates_lists_and_withdraws_a_payment_link(): void
    {
        $reference = $this->reference('sdk-link');

        $link = $this->payments()->createLink(
            title: 'SDK integration test',
            maxUses: 1,
            amount: $this->amount(),
            customer: new Customer('sdk-customer-2', 'Bala Rao', 'bala@example.test', '+919000000002'),
            reference: $reference,
        );

        // The payable address, returned once.
        $this->assertNotNull($link->url);
        $this->assertStringContainsString('/pay/', $link->url);
        $this->assertTrue($link->singleUse);
        $this->assertTrue($link->status->isOpen());

        $found = $this->payments()->link($link->id);
        $this->assertSame($link->id, $found->id);

        $listed = $this->payments()->links(reference: $reference);
        $this->assertContains($link->id, array_map(static fn ($l): string => $l->id, $listed));

        $withdrawn = $this->payments()->withdrawLink($link, 'SDK integration test tidy-up');
        $this->assertFalse($withdrawn->status->isOpen());
    }

    /**
     * The SDK's own guard, checked against the platform's.
     *
     * Both refuse a multi-use link carrying one customer, for the same
     * reason — everyone who opens it is a different person. The SDK
     * refuses first, which is better, and this proves the platform would
     * have too rather than letting the SDK enforce something the platform
     * has quietly stopped caring about.
     */
    #[Test]
    public function the_platform_agrees_that_a_reusable_link_cannot_carry_one_customer(): void
    {
        try {
            $this->payments()->client()->mutate(
                'POST',
                'payment-links',
                $this->reference('sdk-badlink') . ':link',
                [
                    'title' => 'SDK guard check',
                    'currency' => 'INR',
                    'amount' => $this->amount()->minorUnits,
                    'max_uses' => 3,
                    'customer_name' => 'Should Not Be Allowed',
                    'customer_email' => 'nope@example.test',
                    'customer_phone' => '+919000000003',
                ],
            );

            $this->fail('the platform accepted a reusable link with one customer on it');
        } catch (Exceptions\PaymentsException $e) {
            $this->assertContains($e->type, ['payment_link_invalid', 'validation_failed'], $e->getMessage());
        }
    }
}
