<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Feature;

use Ctpl\Payments\Data\Money;
use Ctpl\Payments\Exceptions\UnresolvedAttempt;
use Ctpl\Payments\Facades\Payments;
use Ctpl\Payments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The fake an application uses to test its own payment code.
 *
 * These tests are written the way a consumer would write theirs, so that
 * this file doubles as the worked example.
 */
final class PaymentsFakeTest extends TestCase
{
    #[Test]
    public function a_happy_path_needs_no_network(): void
    {
        $fake = Payments::fake();

        $order = Payments::pay('BOOK-1', Money::rupees(1250));

        $this->assertNotNull($order->checkout, 'the customer needs somewhere to go');

        $confirmed = Payments::confirm($order);

        $this->assertTrue($confirmed->isPaid());
        $this->assertSame(125000, $confirmed->amountPaid->minorUnits);

        $fake->assertOrderCreated('BOOK-1')->assertAttemptOpened()->assertConfirmed();
    }

    #[Test]
    public function a_declined_card_is_an_outcome_and_not_an_exception(): void
    {
        Payments::fake()->willBeDeclined();

        $order = Payments::confirm(Payments::pay('BOOK-2', Money::rupees(500)));

        $this->assertFalse($order->isPaid());
        $this->assertTrue($order->status->isFailure());
        $this->assertSame('Failed', $order->status->label());
    }

    /**
     * The test every integration should have and almost none does.
     *
     * The instinctive handling of a 409 — catch it and retry — is the one
     * that charges a customer twice, and nothing but a test will tell you
     * your code does it.
     */
    #[Test]
    public function an_unresolved_attempt_reaches_the_application_as_a_refusal_to_retry(): void
    {
        Payments::fake()->willBeUnresolved();

        $this->expectException(UnresolvedAttempt::class);

        Payments::pay('BOOK-3', Money::rupees(999));
    }

    #[Test]
    public function the_fake_can_prove_no_payment_was_started(): void
    {
        $fake = Payments::fake();

        // The application decided not to charge — a free booking, say.
        $fake->assertNothingCharged();
    }

    #[Test]
    public function refunds_and_links_work_against_it_too(): void
    {
        $fake = Payments::fake();

        $order = Payments::confirm(Payments::pay('BOOK-4', Money::rupees(2000)));
        $refund = Payments::refund($order, reason: 'Cancelled');

        $this->assertSame(200000, $refund->amount->minorUnits);
        $fake->assertRefunded(Money::rupees(2000));

        $link = Payments::createLink('Invoice 7', maxUses: null, amount: Money::rupees(300));

        $this->assertNotNull($link->url);
        $this->assertFalse($link->singleUse);
    }
}
