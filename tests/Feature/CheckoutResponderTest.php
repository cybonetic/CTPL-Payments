<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Feature;

use Ctpl\Payments\Checkout\CheckoutResponder;
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

    #[Test]
    public function a_form_post_checkout_becomes_a_self_submitting_form(): void
    {
        $response = CheckoutResponder::respond($this->checkout([
            'type' => 'form_post',
            'payload' => ['key' => 'abc123', 'txnid' => 'T-1', 'hash' => 'deadbeef'],
        ]));

        $html = $response->getContent();

        $this->assertStringContainsString('action="https://gateway.test/pay/1"', $html);
        $this->assertStringContainsString('name="txnid" value="T-1"', $html);
        $this->assertStringContainsString('.submit()', $html);

        // The thing most likely to be deleted by somebody tidying up, and
        // the only thing between a customer with JavaScript off and a
        // blank page.
        $this->assertStringContainsString('<noscript>', $html);
    }

    /** A gateway's payload is somebody else's data appearing in our markup. */
    #[Test]
    public function payload_values_are_escaped(): void
    {
        $response = CheckoutResponder::respond($this->checkout([
            'type' => 'form_post',
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
                $this->assertStringContainsString('payloadFor', $e->getMessage());
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
