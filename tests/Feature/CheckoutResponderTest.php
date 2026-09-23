<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Feature;

use Ctpl\Payments\Checkout\CheckoutResponder;
use Ctpl\Payments\Enums\CheckoutType;
use Ctpl\Payments\Data\Checkout;
use Ctpl\Payments\Exceptions\PaymentsException;
use Ctpl\Payments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CheckoutResponderTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function checkout(array $overrides = []): Checkout
    {
        return Checkout::fromApi(array_merge([
            'payment_session_id' => 'ps_1',
            'type' => 'redirect',
            'token' => 'pst_abc',
            'redirect_url' => 'https://gateway.test/pay/1',
            'expires_in' => 900,
        ], $overrides));
    }

    #[Test]
    public function a_redirect_checkout_becomes_a_redirect(): void
    {
        $response = CheckoutResponder::respond($this->checkout());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://gateway.test/pay/1', $response->headers->get('Location'));
    }

    /**
     * ------------------------------------------------------------------
     *  A REAL PayU PAYLOAD, NOT ONE THIS PACKAGE INVENTED
     * ------------------------------------------------------------------
     *
     * The previous version of this test drove a type called `form_post`
     * with three made-up fields. The platform has never sent that type:
     * PayU is `hosted`, and the payload below is what
     * `PayUAdapter::checkoutFor()` actually returns — the merchant key,
     * txnid, amount, product info, the customer's details, `surl` and
     * `furl`, and a SHA-512 `hash` computed over them in a fixed order
     * with a salt the application never sees.
     *
     * Because `form_post` did not exist, `Checkout::fromApi()` fell
     * through to its unknown-type fallback and PayU became a plain
     * redirect: a GET to the checkout with none of these fields and no
     * hash. The old test passed the whole time, against a type and a
     * payload both written here.
     */
    #[Test]
    public function a_hosted_checkout_posts_the_gateways_signed_form(): void
    {
        $response = CheckoutResponder::respond($this->checkout([
            'type' => 'hosted',
            'redirect_url' => 'https://secure.payu.in/_payment',
            'public_key' => 'gtKFFx',
            'payload' => [
                'key' => 'gtKFFx',
                'txnid' => 'T-9f2c41aa',
                'amount' => '1250.00',
                'productinfo' => 'Room booking',
                'firstname' => 'Asha',
                'email' => 'asha@example.test',
                'phone' => '919000000001',
                'surl' => 'https://pay.cybonetic.com/api/v1/checkout/return/tok',
                'furl' => 'https://pay.cybonetic.com/api/v1/checkout/return/tok',
                'hash' => str_repeat('a', 128),
                'action' => 'https://secure.payu.in/_payment',
                'method' => 'POST',
                'currency' => 'INR',
                'display_amount' => '₹1,250.00',
            ],
        ]));

        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('action="https://secure.payu.in/_payment"', $html);
        $this->assertStringContainsString('method="POST"', $html);

        // Every signed field, present and unaltered. A form missing one
        // of these is a hash that does not match, and PayU answers that
        // with an error page rather than a payment.
        foreach ([
            'key' => 'gtKFFx',
            'txnid' => 'T-9f2c41aa',
            'amount' => '1250.00',
            'productinfo' => 'Room booking',
            'firstname' => 'Asha',
            'email' => 'asha@example.test',
            'surl' => 'https://pay.cybonetic.com/api/v1/checkout/return/tok',
            'furl' => 'https://pay.cybonetic.com/api/v1/checkout/return/tok',
        ] as $field => $value) {
            $this->assertStringContainsString(
                sprintf('name="%s" value="%s"', $field, htmlspecialchars($value, ENT_QUOTES)),
                $html,
                "the hosted form left out [{$field}], so PayU's hash will not match",
            );
        }

        $this->assertStringContainsString('name="hash" value="' . str_repeat('a', 128) . '"', $html);

        /*
         * `action`, `method` and `display_amount` are instructions about
         * the form and a string for a human. They are not part of what
         * was signed, and posting them back as fields is not what the
         * platform's own hosted page does.
         */
        foreach (['action', 'method', 'display_amount'] as $notAField) {
            $this->assertStringNotContainsString(
                sprintf('name="%s"', $notAField),
                $html,
                "[{$notAField}] was posted as a form field",
            );
        }

        $this->assertStringContainsString('.submit()', $html);

        // The thing most likely to be deleted by somebody tidying up, and
        // the only thing between a customer with JavaScript off and a
        // blank page.
        $this->assertStringContainsString('<noscript>', $html);
    }

    /**
     * The form is posted where the platform SIGNED it for.
     *
     * `action` and `redirect_url` are the same URL today and are not
     * required to stay that way. A hash signed for one host and posted
     * to another fails at the gateway, in production, on a customer.
     */
    #[Test]
    public function the_signed_action_wins_over_the_redirect_url(): void
    {
        $html = (string) CheckoutResponder::respond($this->checkout([
            'type' => 'hosted',
            'redirect_url' => 'https://stale.payu.test/_payment',
            'payload' => [
                'key' => 'gtKFFx',
                'hash' => 'abc',
                'action' => 'https://secure.payu.in/_payment',
                'method' => 'POST',
            ],
        ]))->getContent();

        $this->assertStringContainsString('action="https://secure.payu.in/_payment"', $html);
        $this->assertStringNotContainsString('stale.payu.test', $html);
    }

    /** And a `hosted` type is one the server can finish on its own. */
    #[Test]
    public function hosted_and_redirect_are_the_two_the_server_can_finish(): void
    {
        $this->assertTrue(CheckoutType::Hosted->isServerDriven());
        $this->assertTrue(CheckoutType::Redirect->isServerDriven());

        foreach ([CheckoutType::Sdk, CheckoutType::Intent, CheckoutType::Qr, CheckoutType::Custom] as $type) {
            $this->assertFalse($type->isServerDriven(), $type->value . ' claimed the server could finish it');
        }
    }

    /**
     * ------------------------------------------------------------------
     *  THE SIX THE PLATFORM SENDS
     * ------------------------------------------------------------------
     *
     * Pinned as literal strings, because this enum's whole job is to
     * agree with `Modules\Support\Enums\CheckoutType` on the platform,
     * and the failure when it does not is silent: an unrecognised type
     * becomes a redirect, and a redirect to a gateway expecting a signed
     * POST is a page that cannot take the customer's money.
     */
    #[Test]
    public function the_types_are_the_platforms_types(): void
    {
        $this->assertSame(
            ['hosted', 'sdk', 'redirect', 'qr', 'intent', 'custom'],
            array_map(static fn (CheckoutType $t): string => $t->value, CheckoutType::cases()),
        );
    }

    /**
     * An unknown type is a redirect, and that fallback is why the
     * vocabulary above has to be right.
     */
    #[Test]
    public function an_unknown_type_still_falls_back_to_a_redirect(): void
    {
        $this->assertSame(
            CheckoutType::Redirect,
            \Ctpl\Payments\Data\Checkout::fromApi([
                'payment_session_id' => 'ps_1',
                'type' => 'something_the_platform_added_later',
                'token' => 'tok',
                'redirect_url' => 'https://gateway.test/pay/1',
            ])->type,
        );
    }

    /** A gateway's payload is somebody else's data appearing in our markup. */
    #[Test]
    public function payload_values_are_escaped(): void
    {
        $response = CheckoutResponder::respond($this->checkout([
            'type' => 'hosted',
            'payload' => ['evil' => '"><script>alert(1)</script>'],
        ]));

        $html = $response->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Refused, not approximated.
     *
     * Redirecting a customer to an SDK payload's URL shows them a page
     * that cannot take their money, and they have no way to know that.
     */
    #[Test]
    public function a_browser_driven_checkout_is_refused_with_what_to_do_instead(): void
    {
        foreach (['sdk', 'intent', 'qr'] as $type) {
            try {
                CheckoutResponder::respond($this->checkout(['type' => $type]));
                $this->fail("[{$type}] should not have produced a server response");
            } catch (PaymentsException $e) {
                $this->assertSame('checkout_needs_browser', $e->type);
                $this->assertStringContainsString('CheckoutResponder::page', $e->getMessage());
            }
        }
    }

    #[Test]
    public function an_expired_session_is_refused_rather_than_sent_to(): void
    {
        $this->expectException(PaymentsException::class);
        $this->expectExceptionMessageMatches('/expired/');

        CheckoutResponder::respond($this->checkout([
            'expires_at' => '2020-01-01T00:00:00+00:00',
        ]));
    }

    /**
     * An unknown type is a redirect, not a crash.
     *
     * The platform adding a sixth checkout type should not take an
     * integration down, and every type that has ever shipped carries a
     * redirect_url.
     */
    #[Test]
    public function an_unrecognised_type_falls_back_to_redirecting(): void
    {
        $response = CheckoutResponder::respond($this->checkout(['type' => 'something_new']));

        $this->assertSame(302, $response->getStatusCode());
    }

    #[Test]
    public function the_browser_payload_carries_the_session_token_and_not_the_api_token(): void
    {
        $payload = CheckoutResponder::payloadFor($this->checkout(['type' => 'sdk', 'public_key' => 'pk_1']));

        $this->assertSame('sdk', $payload['type']);
        $this->assertSame('pst_abc', $payload['token']);
        $this->assertSame('pk_1', $payload['public_key']);
        $this->assertArrayNotHasKey('client_secret', $payload);
        $this->assertArrayNotHasKey('access_token', $payload);
    }
}
