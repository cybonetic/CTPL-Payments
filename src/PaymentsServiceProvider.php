<?php

declare(strict_types=1);

namespace Ctpl\Payments;

use Ctpl\Payments\Client\Client;
use Ctpl\Payments\Client\Config;
use Ctpl\Payments\Client\TokenStore;
use Ctpl\Payments\Console\PingCommand;
use Ctpl\Payments\Webhooks\WebhookController;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ctpl-payments.php', 'ctpl-payments');

        /*
         * Config is resolved LAZILY, inside the closure.
         *
         * `Config::fromArray()` throws when a credential is missing, and
         * doing that at registration would mean an application that has
         * not configured payments yet cannot run `artisan` at all — not
         * migrations, not `config:cache`, not the command that would tell
         * it what is wrong. Deferring it means the failure lands on the
         * first payment call, which is where it belongs.
         */
        $this->app->singleton(Config::class, fn ($app): Config => Config::fromArray(
            (array) $app['config']->get('ctpl-payments', []),
            // The environment, so `Config` can decide whether its
            // local-only URL override applies. It never does outside
            // `local` and `testing`.
            (string) $app->environment(),
        ));

        $this->app->singleton(TokenStore::class, fn ($app): TokenStore => new TokenStore(
            $app->make(HttpFactory::class),
            $app->make(Config::class),
            $app->bound(CacheFactory::class) ? $app->make(CacheFactory::class) : null,
        ));

        $this->app->singleton(Client::class, fn ($app): Client => new Client(
            $app->make(HttpFactory::class),
            $app->make(Config::class),
            $app->make(TokenStore::class),
        ));

        $this->app->singleton(PaymentsManager::class, fn ($app): PaymentsManager => new PaymentsManager(
            $app->make(Client::class),
        ));

        $this->app->alias(PaymentsManager::class, 'ctpl.payments');
    }

    public function boot(): void
    {
        /*
         * The checkout page, so no application writes gateway
         * JavaScript.
         *
         * `loadViewsFrom` and not a publish: the view has to work
         * without anybody publishing anything, because an integration
         * that has to run a publish command before a Cashfree payment
         * can open is an integration that discovers this from a
         * customer. Publishing is offered for restyling.
         */
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'ctpl-payments');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/ctpl-payments.php' => $this->app->configPath('ctpl-payments.php'),
            ], 'ctpl-payments-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => $this->app->resourcePath('views/vendor/ctpl-payments'),
            ], 'ctpl-payments-views');

            $this->commands([PingCommand::class]);
        }

        $this->registerWebhookRoute();
    }

    /**
     * Registered only when switched on, and never inside a route cache
     * that would outlive the setting.
     *
     * An endpoint that exists by default is one an application does not
     * know it is exposing. It stays off until somebody sets a secret and
     * turns it on, which is also when they will have registered the URL
     * with the platform.
     */
    private function registerWebhookRoute(): void
    {
        $config = (array) $this->app['config']->get('ctpl-payments.webhooks', []);

        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        Route::middleware((array) ($config['middleware'] ?? ['api']))
            ->post((string) ($config['path'] ?? 'ctpl/payments/events'), WebhookController::class)
            ->name('ctpl-payments.webhook');
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [Config::class, TokenStore::class, Client::class, PaymentsManager::class, 'ctpl.payments'];
    }
}
