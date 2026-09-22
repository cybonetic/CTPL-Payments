<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Feature;

use Ctpl\Payments\Data\Money;
use Ctpl\Payments\Exceptions;
use Ctpl\Payments\PaymentsManager;
use Ctpl\Payments\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The HTTP layer: what it sends, and what it makes of what comes back.
 */
final class ClientTest extends TestCase
{
    private function payments(): PaymentsManager
    {
        return $this->app->make(PaymentsManager::class);
    }

    // -----------------------------------------------------------------
    // The token
    // -----------------------------------------------------------------

    #[Test]
    public function it_fetches_a_token_once_and_reuses_it(): void
    {
        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/payment-methods' => Http::response(['data' => [['code' => 'UPI', 'name' => 'UPI']]]),
        ]);

        $this->payments()->paymentMethods();
        $this->payments()->paymentMethods();

        // Three requests, not four: the token endpoint is throttled by IP
        // at twenty a minute, because it is the only one reachable
        // without a token. An SDK that fetched one per call would get its
        // users limited.
        Http::assertSentCount(3);
    }

    #[Test]
    public function every_request_carries_a_bearer_token_and_a_correlation_id(): void
    {
        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*' => Http::response(['data' => []]),
        ]);

        $this->payments()->paymentMethods();

        Http::assertSent(function ($request): bool {
            if (str_contains($request->url(), 'auth/token')) {
                return true;
            }

            return $request->hasHeader('Authorization', 'Bearer ' . $this->tokenResponse()['access_token'])
                && $request->header('X-Correlation-Id') !== []
                && str_starts_with($request->header('User-Agent')[0], 'ctpl-payments-laravel/');
        });
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    #[Test]
    public function every_write_carries_an_idempotency_key(): void
    {
        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/payment-orders' => Http::response($this->orderResponse(), 201),
        ]);

        $this->payments()->createOrder('BOOK-4102', Money::rupees(1250));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'payment-orders')
            && $request->hasHeader('Idempotency-Key', 'BOOK-4102:order'));
    }

    /**
     * Stricter than the API, on purpose: the platform answers `400
     * idempotency_key_required`, and this refuses before a request is made.
     */
    #[Test]
    public function an_empty_idempotency_key_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        $this->expectException(Exceptions\PaymentsException::class);
        $this->expectExceptionMessageMatches('/idempotency key is required/i');

        $this->app->make(\Ctpl\Payments\Client\Client::class)->mutate('POST', 'payment-orders', '  ', []);
    }

    // -----------------------------------------------------------------
    // Errors
    // -----------------------------------------------------------------

    /**
     * The most important test in this package.
     *
     * Three different 409s live on this API. Mapping them by status code
     * would collapse them into one, and the one that gets retried is the
     * one that charges the customer twice.
     */
    #[Test]
    #[DataProvider('conflicts')]
    public function the_three_conflicts_become_three_different_exceptions(
        string $type,
        string $class,
        bool $retryable,
    ): void {
        /*
         * One case per test, via a provider, rather than a loop.
         *
         * The first version looped and called `Http::fake()` each time.
         * Laravel's fake APPENDS stub callbacks rather than replacing
         * them, so the first attempts-URL stub answered all three
         * iterations and the test reported that `request_in_progress`
         * produced an `UnresolvedAttempt`. The test was wrong, not the
         * mapper — but only because the second case failed loudly; had
         * the first case been the retryable one, this would have passed
         * while proving nothing.
         */
        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/attempts' => Http::response($this->problem($type, 'no'), 409),
        ]);

        try {
            $this->payments()->openAttempt('po_1');
            $this->fail("[{$type}] did not raise");
        } catch (Exceptions\PaymentsException $e) {
            $this->assertInstanceOf($class, $e, $type);
            $this->assertSame($retryable, $e->retryable(), $type . ' retryable()');
        }
    }

    /** @return array<string, array{string, class-string, bool}> */
    public static function conflicts(): array
    {
        return [
            // The one that must never be retried.
            'unresolved_attempt' => ['unresolved_attempt', Exceptions\UnresolvedAttempt::class, false],
            'request_in_progress' => ['request_in_progress', Exceptions\RequestInProgress::class, true],
            'idempotency_key_conflict' => ['idempotency_key_conflict', Exceptions\IdempotencyKeyConflict::class, false],
        ];
    }

    #[Test]
    public function an_unresolved_attempt_carries_the_ids_and_is_never_retried(): void
    {
        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/attempts' => Http::response($this->problem(
                'unresolved_attempt',
                'An earlier attempt has not resolved.',
                ['payment_order_id' => 'po_9', 'payment_attempt_id' => 'pa_9'],
            ), 409),
        ]);

        try {
            $this->payments()->openAttempt('po_9');
            $this->fail('did not raise');
        } catch (Exceptions\UnresolvedAttempt $e) {
            $this->assertSame('po_9', $e->paymentOrderId());
            $this->assertSame('pa_9', $e->paymentAttemptId());
            $this->assertFalse($e->retryable());
        }

        // Token, then the attempt, and NOT a second attempt. Retrying
        // this is how one customer pays twice.
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_rate_limit_is_retried_and_honours_retry_after(): void
    {
        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/payment-methods' => Http::sequence()
                ->push($this->problem('rate_limited', 'Too many requests.'), 429, ['Retry-After' => '0'])
                ->push(['data' => [['code' => 'UPI', 'name' => 'UPI']]], 200),
        ]);

        $methods = $this->payments()->paymentMethods();

        $this->assertCount(1, $methods);
    }

    #[Test]
    public function it_gives_up_after_the_configured_retries(): void
    {
        config()->set('ctpl-payments.retries', 1);

        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/payment-methods' => Http::response($this->problem('rate_limited'), 429, ['Retry-After' => '0']),
        ]);

        $this->expectException(Exceptions\RateLimited::class);

        $this->payments()->paymentMethods();
    }

    #[Test]
    public function an_unrecognised_error_type_is_not_retryable(): void
    {
        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/payment-methods' => Http::response($this->problem('something_new_the_sdk_predates'), 422),
        ]);

        try {
            $this->payments()->paymentMethods();
            $this->fail('did not raise');
        } catch (Exceptions\PaymentsException $e) {
            $this->assertSame(Exceptions\PaymentsException::class, $e::class);
            $this->assertFalse($e->retryable(), 'an unknown payment failure must not be hammered');
        }
    }

    #[Test]
    public function a_body_that_is_not_the_platforms_is_named_as_such(): void
    {
        Http::fake([
            '*/auth/token' => Http::response($this->tokenResponse()),
            '*/payment-methods' => Http::response('<html>502 Bad Gateway</html>', 502),
        ]);

        try {
            $this->payments()->paymentMethods();
            $this->fail('did not raise');
        } catch (Exceptions\PaymentsException $e) {
            $this->assertSame('upstream_error', $e->type);
            $this->assertStringContainsString('or something in front of it', $e->getMessage());
        }
    }

    #[Test]
    public function bad_credentials_say_all_four_things_it_might_be(): void
    {
        Http::fake(['*/auth/token' => Http::response($this->problem('invalid_client'), 401)]);

        try {
            $this->payments()->paymentMethods();
            $this->fail('did not raise');
        } catch (Exceptions\InvalidClient $e) {
            $this->assertStringContainsString('revoked', $e->getMessage());
            $this->assertStringContainsString('address', $e->getMessage());
        }
    }
}
