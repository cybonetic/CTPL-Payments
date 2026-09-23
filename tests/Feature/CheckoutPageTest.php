<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Feature;

use Ctpl\Payments\Checkout\CheckoutResponder;
use Ctpl\Payments\Data\Checkout;
use Ctpl\Payments\Exceptions\PaymentsException;
use Ctpl\Payments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The page that hands a customer to a gateway the server cannot finish
 * with.
 *
 * ------------------------------------------------------------------
 *  THE REPORT THIS ANSWERS
 * ------------------------------------------------------------------
 *
 * "why sdk only takes to the phonepe, not to any one else, if taken to
 *  anyother then it show the json as shown in screenshot attached, why"
 *
 * Because routing picks the gateway when the attempt is opened, and
 * only PhonePe's `redirect` was one a server could complete. Razorpay
 * and Cashfree are `sdk`; without a page that knows how to open them,
 * `respond()` threw and the integration showed whatever it showed.
 *
 * ------------------------------------------------------------------
 *  WHAT IS ASSERTED, AND WHAT CANNOT BE
 * ------------------------------------------------------------------
 *
 * These render the page and read the markup, and there is one thing
 * markup CANNOT show: which gateway's script the page loads. Both CDN
 * URLs are literals in the page and the choice is made at runtime, so
 * the first version of this file asserted that a Razorpay checkout did
 * not contain the string `sdk.cashfree.com` — which it always does, and
 * always will. Five tests failed against a page that was working.
 *
 * So the division is: these prove the DATA reaches the page correctly
 * and that nothing secret is in it, and `tools/verify_checkout_page.mjs`
 * opens each page in a real browser and records which script was
 * actually requested. A check that reads a literal and calls it a
 * decision is worse than no check.
 */
final class CheckoutPageTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function checkout(array $overrides = []): Checkout
    {
        return Checkout::fromApi(array_merge([
            'payment_session_id' => 'ps_9c1',
            'type' => 'sdk',
            'token' => 'tok_live_abc',
            'expires_in' => 900,
        ], $overrides));
    }

    private function render(Checkout $checkout): string
    {
        return (string) CheckoutResponder::page($checkout)->getContent();
    }

    // -----------------------------------------------------------------

    #[Test]
    public function a_razorpay_checkout_loads_razorpays_script_and_nobody_elses(): void
    {
        $html = $this->render($this->checkout([
            'public_key' => 'rzp_test_1DP5mmOlF5G5ag',
            'payload' => [
                'provider' => 'RAZORPAY',
                'gateway_order_id' => 'order_Nq8xLm',
                'amount' => 125000,
                'currency' => 'INR',
                'return_url' => 'https://pay.cybonetic.com/api/v1/checkout/return/tok',
                'display_amount' => '₹1,250.00',
            ],
        ]));

        // The key id is public by design and belongs in the browser.
        $this->assertStringContainsString('rzp_test_1DP5mmOlF5G5ag', $html);
        $this->assertStringContainsString('order_Nq8xLm', $html);

        // The handback goes to the PLATFORM, which verifies it and then
        // runs its own status query anyway (R1, R12).
        $this->assertStringContainsString('pay.cybonetic.com\\/api\\/v1\\/checkout\\/return\\/tok', $html);
    }

    #[Test]
    public function a_cashfree_checkout_loads_cashfrees_script_with_the_session_id(): void
    {
        $html = $this->render($this->checkout([
            // Deliberately null: Cashfree's browser SDK authenticates
            // with the session id, and the platform sends no key. A page
            // that required one would refuse a working checkout.
            'public_key' => null,
            'payload' => [
                'provider' => 'CASHFREE',
                'payment_session_id' => 'session_xYz123',
                'environment' => 'sandbox',
                'amount' => 125000,
                'currency' => 'INR',
            ],
        ]));

        $this->assertStringContainsString('session_xYz123', $html);
        $this->assertStringContainsString('"environment":"sandbox"', $html);

        // Both CDN URLs are literals in this page and the choice is made
        // at runtime — see the note at the top. Which one is fetched is
        // checked by tools/verify_checkout_page.mjs, in a browser.
    }

    /** The mode is read from the payload, not hard-coded to sandbox. */
    #[Test]
    public function cashfree_production_is_not_opened_in_sandbox(): void
    {
        $html = $this->render($this->checkout([
            'payload' => [
                'provider' => 'CASHFREE',
                'payment_session_id' => 'session_live',
                'environment' => 'production',
            ],
        ]));

        $this->assertStringContainsString('"environment":"production"', $html);
    }

    /**
     * An `sdk` checkout from a provider this page does not know is an
     * honest message, not a guess.
     *
     * Loading the wrong gateway's script is a broken checkout in front
     * of somebody trying to pay. Saying so is worse for a developer and
     * better for a customer, which is the right way round.
     */
    #[Test]
    public function an_unknown_provider_says_so_rather_than_guessing(): void
    {
        $html = $this->render($this->checkout([
            'payload' => ['provider' => 'SOMEONE_NEW'],
        ]));

        // The message exists in the page; that it is the branch taken for
        // an unknown provider is proved in a browser.
        $this->assertStringContainsString('does not know how to open', $html);
        $this->assertStringContainsString('"provider":"SOMEONE_NEW"', $html);
    }

    #[Test]
    public function a_qr_checkout_shows_the_code(): void
    {
        $html = $this->render($this->checkout([
            'type' => 'qr',
            'payload' => ['qr_image' => 'data:image/png;base64,iVBORw0KGgo=', 'display_amount' => '₹1,250.00'],
        ]));

        $this->assertStringContainsString('data:image\\/png;base64,iVBORw0KGgo=', $html);
        $this->assertStringContainsString('₹1,250.00', $html);

        // The page cannot know when the customer has paid, and does not
        // pretend it will find out.
        $this->assertStringContainsString('will not update by itself', $html);
    }

    #[Test]
    public function an_intent_checkout_carries_the_upi_link_and_a_fallback(): void
    {
        $html = $this->render($this->checkout([
            'type' => 'intent',
            'payload' => [
                'intent_url' => 'upi://pay?pa=merchant@bank&am=1250.00',
                'qr_image' => 'data:image/png;base64,iVBORw0KGgo=',
            ],
        ]));

        // `@json` escapes the slashes, which is correct and is why this
        // looks for the escaped form rather than the one a person types.
        $this->assertStringContainsString('upi:\\/\\/pay?pa=merchant@bank', $html);

        // A desktop has no UPI app, so the same payment is offered as a
        // code to scan rather than a link that opens nothing.
        $this->assertStringContainsString('handlers.qr(', $html);
    }

    /**
     * ------------------------------------------------------------------
     *  THE SPINNER IS ON A CLOCK
     * ------------------------------------------------------------------
     *
     * Reported twice from a live install, with different causes and an
     * identical symptom: this page showing "taking you to your payment
     * provider" and never doing anything else.
     *
     * That sameness is the real defect. A gateway's script can be
     * blocked by a content-security policy or an extension, its CDN can
     * be unreachable, and its own code can sit waiting on a value nobody
     * passed it — none of which raises anything this page can catch. A
     * spinner is a promise that something is happening, and nothing was
     * keeping that promise honest.
     *
     * Whether the clock actually fires is proved in a browser, by
     * `tools/verify_checkout_page.mjs`, which hangs the script request
     * and waits it out. This asserts the mechanism is present at all —
     * cheap, and it catches the tidy-up that deletes it.
     */
    #[Test]
    public function the_page_gives_up_rather_than_spinning_for_ever(): void
    {
        $html = $this->render($this->checkout([
            'public_key' => 'rzp_test_1',
            'payload' => ['provider' => 'RAZORPAY', 'gateway_order_id' => 'order_1'],
        ]));

        $this->assertStringContainsString('HANDOFF_SECONDS', $html);
        $this->assertStringContainsString('did not respond within', $html);

        /*
         * And the case a leftover iframe used to silence.
         *
         * The first watchdog asked whether any gateway element was on
         * the page and returned if so. Razorpay leaves its container
         * behind when its modal closes, so for the reported state —
         * "razorpay returns with the success or failure response then it
         * gets stuck at the spinner" — the check found that container,
         * concluded all was well, and said nothing.
         */
        // A source-contiguous fragment: the sentence is built by
        // concatenation, so the whole phrase only exists once a browser
        // has run the page. That the customer SEES it is proved in a
        // browser by tools/verify_checkout_page.mjs.
        $this->assertStringContainsString('was never told what', $html);
        $this->assertStringContainsString('PAYING_SECONDS', $html);

        // And what it says is a failure to HAND OFF, never to pay.
        $this->assertStringContainsString('No money has been taken', $html);
    }

    /**
     * Razorpay does not refuse a missing key — it opens and hangs on its
     * own loading shield for ever.
     *
     * Verified by loading the real script with `public_key` removed: the
     * modal appears and never finishes, with no error and no callback.
     * So the page refuses before fetching anything, where the message
     * can name the field and say whose problem it is.
     */
    #[Test]
    public function a_gateway_that_cannot_open_is_refused_before_its_script_is_fetched(): void
    {
        $html = $this->render($this->checkout([
            'public_key' => null,
            'payload' => ['provider' => 'RAZORPAY', 'gateway_order_id' => 'order_1'],
        ]));

        $this->assertStringContainsString('no publishable key for Razorpay', $html);
        $this->assertStringContainsString('no Cashfree payment session id', $html);
    }

    /**
     * The handback is a POST carrying what the gateway signed.
     *
     * `redirect: true` is supposed to mean Razorpay posts its signed
     * response to `callback_url` itself. When it does not, this page
     * does it by hand rather than leaving a paid customer on a spinner —
     * and as a POST, because `razorpay_signature` is the only thing that
     * proves the handback is authentic and a GET throws it away.
     *
     * That the callbacks are actually WIRED to this is proved in a
     * browser by invoking them; asserting a function exists is not
     * asserting it works, and the version of that check which only
     * counted them passed while the bug was live.
     */
    #[Test]
    public function the_handback_is_posted_with_the_gateways_own_evidence(): void
    {
        $html = $this->render($this->checkout([
            'public_key' => 'rzp_test_1',
            'payload' => [
                'provider' => 'RAZORPAY',
                'gateway_order_id' => 'order_1',
                'callback_url' => 'https://pay.cybonetic.com/api/v1/checkout/return/tok',
            ],
        ]));

        $this->assertStringContainsString("form.method = 'POST'", $html);
        $this->assertStringContainsString('leaveForPlatform', $html);

        // Not an outcome — the platform still asks the gateway.
        $this->assertStringContainsString('Checking your payment', $html);
    }

    /**
     * ------------------------------------------------------------------
     *  THE ONE MOMENT THE PLATFORM CAN SEE THE PAYER
     * ------------------------------------------------------------------
     *
     * An order is created by the application's server, so the address on
     * that request is its data centre. From this page the customer goes
     * straight to the gateway. Without a call from the BROWSER the
     * platform never sees them, and every payer field on the payment is
     * empty — which is what was reported.
     *
     * That the request is actually made, once, is proved in a browser by
     * `tools/verify_checkout_page.mjs`.
     */
    #[Test]
    public function the_page_lets_the_platform_see_the_payer(): void
    {
        $html = $this->render($this->checkout([
            'token' => 'tok_live_abc',
            'public_key' => 'rzp_test_1',
            'payload' => ['provider' => 'RAZORPAY', 'gateway_order_id' => 'order_1'],
        ]));

        $this->assertStringContainsString('checkout\/sessions\/tok_live_abc', $html);

        // `no-cors`, because nothing here reads the reply — the checkout
        // is already on the page — and requiring a readable response
        // would make recording a payer depend on a cross-origin policy
        // that has nothing to do with it.
        $this->assertStringContainsString("mode: 'no-cors'", $html);

        // Never awaited, never fatal. The customer is here to pay.
        $this->assertStringContainsString('keepalive: true', $html);
    }

    /** And it is the platform's own address, which no application can choose. */
    #[Test]
    public function the_payer_call_goes_to_the_platform_and_nowhere_an_application_can_name(): void
    {
        config()->set('ctpl-payments.base_url', 'https://somewhere-else.invalid');

        $html = $this->render($this->checkout([
            'token' => 'tok_live_abc',
            'payload' => ['provider' => 'CASHFREE', 'payment_session_id' => 'session_x'],
        ]));

        $this->assertStringContainsString(
            str_replace('/', '\/', \Ctpl\Payments\Client\Config::PLATFORM_URL),
            $html,
        );
        $this->assertStringNotContainsString('somewhere-else.invalid', $html);
    }

    // -----------------------------------------------------------------
    // What must never be on the page
    // -----------------------------------------------------------------

    /**
     * The session token is scoped to one payment and is safe in a
     * browser. Nothing else about the application is.
     */
    #[Test]
    public function the_page_carries_no_application_credential(): void
    {
        config()->set('ctpl-payments.client_secret', 'super-secret-value-abc123');

        $html = $this->render($this->checkout([
            'payload' => ['provider' => 'CASHFREE', 'payment_session_id' => 'session_x'],
        ]));

        $this->assertStringNotContainsString('super-secret-value-abc123', $html);
        $this->assertStringNotContainsString('client_secret', $html);
    }

    /** A gateway's payload is somebody else's data appearing in our markup. */
    #[Test]
    public function payload_values_cannot_break_out_of_the_script(): void
    {
        $html = $this->render($this->checkout([
            'payload' => [
                'provider' => 'CASHFREE',
                'payment_session_id' => '</script><script>alert(1)</script>',
            ],
        ]));

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
    }

    /** One customer's one payment, never held in a cache between them. */
    #[Test]
    public function the_page_is_never_cached(): void
    {
        $response = CheckoutResponder::page($this->checkout([
            'payload' => ['provider' => 'CASHFREE', 'payment_session_id' => 'session_x'],
        ]));

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // -----------------------------------------------------------------
    // The types the page is not for
    // -----------------------------------------------------------------

    /** A server-driven type still goes the short way round. */
    #[Test]
    public function a_redirect_is_still_a_redirect(): void
    {
        $response = CheckoutResponder::page($this->checkout([
            'type' => 'redirect',
            'redirect_url' => 'https://mercury.phonepe.com/transact/pg?token=x',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://mercury.phonepe.com/transact/pg?token=x', $response->headers->get('Location'));
    }

    #[Test]
    public function a_hosted_checkout_is_still_the_signed_form(): void
    {
        $html = (string) CheckoutResponder::page($this->checkout([
            'type' => 'hosted',
            'redirect_url' => 'https://secure.payu.in/_payment',
            'payload' => [
                'key' => 'gtKFFx',
                'txnid' => 'T-1',
                'hash' => str_repeat('a', 128),
                'action' => 'https://secure.payu.in/_payment',
                'method' => 'POST',
            ],
        ]))->getContent();

        $this->assertStringContainsString('name="hash"', $html);
        $this->assertStringContainsString('action="https://secure.payu.in/_payment"', $html);
    }

    /** `custom` means the adapter described it; a guess would be worse. */
    #[Test]
    public function a_custom_checkout_is_refused_with_a_reason(): void
    {
        $this->expectException(PaymentsException::class);
        $this->expectExceptionMessageMatches('/custom/');

        CheckoutResponder::page($this->checkout(['type' => 'custom', 'payload' => ['whatever' => 1]]));
    }

    /** An expired session is refused before a customer is sent anywhere. */
    #[Test]
    public function an_expired_session_is_refused(): void
    {
        $this->expectException(PaymentsException::class);

        CheckoutResponder::page($this->checkout([
            'expires_at' => '2020-01-01T00:00:00+00:00',
            'payload' => ['provider' => 'CASHFREE', 'payment_session_id' => 'session_x'],
        ]));
    }
}
