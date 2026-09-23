<?php

declare(strict_types=1);

/*
 * Render `ctpl-payments::checkout` for one checkout, given as JSON on
 * stdin, and print the HTML.
 *
 * ------------------------------------------------------------------
 *  A CONTAINER, NOT A FRAMEWORK
 * ------------------------------------------------------------------
 *
 * The browser check has to render the page the way an application
 * does — through `CheckoutResponder::page()` and the package's own
 * view — rather than from a copy of the markup, which would prove only
 * that the check agrees with itself.
 *
 * The first attempt registered `ViewServiceProvider` before binding
 * `config`, and every case failed with "Target class [config] does not
 * exist" — a fault in the harness that read exactly like the page being
 * broken. Hence the explicit order below.
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\ViewServiceProvider;

$compiled = sys_get_temp_dir() . '/ctpl-checkout-views';
@mkdir($compiled, 0777, true);

$app = new Application(dirname(__DIR__));

$app->instance('config', new Repository([
    'view' => ['paths' => [], 'compiled' => $compiled],
    'ctpl-payments' => require __DIR__ . '/../config/ctpl-payments.php',
]));

$app->register(new EventServiceProvider($app));
$app->register(new FilesystemServiceProvider($app));
$app->register(new ViewServiceProvider($app));

Facade::setFacadeApplication($app);
Application::setInstance($app);

$app->make('view')->addNamespace('ctpl-payments', __DIR__ . '/../resources/views');

$checkout = Ctpl\Payments\Data\Checkout::fromApi(
    json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR),
);

echo Ctpl\Payments\Checkout\CheckoutResponder::page($checkout)->getContent();
