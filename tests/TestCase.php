<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests;

use Ctpl\Payments\PaymentsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PaymentsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /*
         * No base URL is set, and none can be.
         *
         * The URL is a constant in `Config`, so the faked HTTP
         * expectations below match against `Config::PLATFORM_URL` rather
         * than against a host a test invented. That is the point: a test
         * that pointed the SDK somewhere else would be testing a
         * configuration no application can have.
         */
        $app['config']->set('ctpl-payments.client_id', 'ctpl_test_client');
        $app['config']->set('ctpl-payments.client_secret', 'secret');
        // No sleeping in tests.
        $app['config']->set('ctpl-payments.retry_base_ms', 0);
        $app['config']->set('ctpl-payments.confirm.initial_delay_ms', 0);
        $app['config']->set('ctpl-payments.confirm.max_delay_ms', 0);
    }

    /** The token exchange every other request needs. */
    protected function tokenResponse(): array
    {
        return [
            'access_token' => 'tok_' . str_repeat('a', 32),
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'scope' => 'payment.create payment.read payment.refund',
        ];
    }

    /** @param array<string, mixed> $overrides */
    protected function orderResponse(array $overrides = []): array
    {
        return ['data' => array_merge([
            'payment_order_id' => 'po_11111111-1111-4111-8111-111111111111',
            'external_reference' => 'BOOK-1',
            'status' => 'CREATED',
            'amount' => 125000,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'currency' => 'INR',
            'can_retry' => true,
            'attempt_count' => 0,
            'max_attempts' => 5,
            'attempts' => [],
        ], $overrides)];
    }

    /** @param array<string, mixed> $error */
    protected function problem(string $type, string $message = 'no', array $details = []): array
    {
        return ['error' => array_filter([
            'type' => $type,
            'message' => $message,
            'details' => $details === [] ? null : $details,
            'correlation_id' => 'corr-1',
        ], static fn ($v) => $v !== null)];
    }
}
