<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Unit;

use Ctpl\Payments\Client\Config;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The payment URL is a constant, and the escape hatch cannot escape.
 *
 * ------------------------------------------------------------------
 *  WHAT THESE TESTS ARE PROTECTING
 * ------------------------------------------------------------------
 *
 * An application cannot set where its payments go. That is not tidiness:
 * a configurable base URL means anything able to write a line into `.env`
 * — a leaked deploy credential, a compromised CI job, a config template
 * applied to the wrong host — can silently point every payment, every
 * card detail and every access token at a host of its choosing, and
 * nothing about the application would look wrong.
 *
 * `CTPL_PAYMENTS_BASE_URL_OVERRIDE` exists so this package's own
 * integration suite can run against a local orchestrator, because a suite
 * that can only run against production is a suite nobody runs. The tests
 * below are the reason that hatch is safe to have: it is honoured in
 * `local` and `testing` and nowhere else, and the ones that matter are
 * the negative cases.
 */
final class PlatformUrlTest extends TestCase
{
    private const LOCAL = 'http://127.0.0.1:8000';

    protected function tearDown(): void
    {
        putenv('CTPL_PAYMENTS_BASE_URL_OVERRIDE');

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function credentials(): array
    {
        return ['client_id' => 'ctpl_x', 'client_secret' => 'secret'];
    }

    #[Test]
    public function the_platform_url_is_the_live_host(): void
    {
        $this->assertSame('https://pay.cybonetic.com', Config::PLATFORM_URL);
    }

    #[Test]
    public function an_application_that_configures_nothing_reaches_the_platform(): void
    {
        $config = Config::fromArray($this->credentials(), 'production');

        $this->assertSame('https://pay.cybonetic.com', $config->baseUrl);
        $this->assertSame('https://pay.cybonetic.com/api/v1/payment-orders', $config->url('payment-orders'));
        $this->assertSame('https://pay.cybonetic.com/api/v1/auth/token', $config->tokenUrl());
    }

    /**
     * A base_url in the config array does nothing at all.
     *
     * The key is gone from the published config, but an application
     * upgrading from a version that had it — or one that copied an older
     * template — would still have the line. It must be inert rather than
     * honoured.
     */
    #[Test]
    public function a_leftover_base_url_setting_is_ignored(): void
    {
        $config = Config::fromArray(
            $this->credentials() + ['base_url' => 'https://not-the-platform.invalid'],
            'production',
        );

        $this->assertSame('https://pay.cybonetic.com', $config->baseUrl);
    }

    /**
     * THE one that matters.
     *
     * If this ever fails, an environment variable can redirect a
     * production application's payments.
     */
    #[Test]
    public function the_override_is_ignored_outside_local_and_testing(): void
    {
        putenv('CTPL_PAYMENTS_BASE_URL_OVERRIDE=' . self::LOCAL);

        foreach (['production', 'staging', 'prod', 'uat', 'demo', ''] as $environment) {
            $this->assertSame(
                'https://pay.cybonetic.com',
                Config::fromArray($this->credentials(), $environment)->baseUrl,
                "the override was honoured in [{$environment}]",
            );
        }
    }

    #[Test]
    public function the_override_works_in_local_and_testing_so_the_suite_can_run(): void
    {
        putenv('CTPL_PAYMENTS_BASE_URL_OVERRIDE=' . self::LOCAL . '/');

        foreach (['local', 'testing'] as $environment) {
            $this->assertSame(
                self::LOCAL,
                Config::fromArray($this->credentials(), $environment)->baseUrl,
                $environment,
            );
        }
    }

    #[Test]
    public function an_empty_override_falls_back_to_the_platform(): void
    {
        putenv('CTPL_PAYMENTS_BASE_URL_OVERRIDE=   ');

        $this->assertSame(
            'https://pay.cybonetic.com',
            Config::fromArray($this->credentials(), 'local')->baseUrl,
        );
    }

    /**
     * Defaulting to `production` is what makes the guard fail safe.
     *
     * Anything calling `fromArray()` without saying which environment it
     * is in gets the constant, rather than whatever an environment
     * variable happens to say.
     */
    #[Test]
    public function the_environment_defaults_to_production(): void
    {
        putenv('CTPL_PAYMENTS_BASE_URL_OVERRIDE=' . self::LOCAL);

        $this->assertSame('https://pay.cybonetic.com', Config::fromArray($this->credentials())->baseUrl);
    }
}
