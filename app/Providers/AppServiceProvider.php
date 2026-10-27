<?php

declare(strict_types=1);

namespace App\Providers;

use App\Gateways\FakePaymentGateway;
use App\Gateways\PaymentGateway;
use App\Gateways\StripePaymentGateway;
use App\Gateways\StripeWebhooks;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Plain bindings, not singletons: the fake records calls, and Octane workers must not share that state.
        $this->app->bind(PaymentGateway::class, fn (Application $app): PaymentGateway => config('payments.gateway') === 'stripe'
            ? new StripePaymentGateway(new StripeClient(['api_key' => is_string($key = config('services.stripe.secret')) && $key !== '' ? $key : null]))
            : new FakePaymentGateway);

        $this->app->bind(function (): StripeWebhooks {
            $secret = config('services.stripe.webhook_secret');

            return new StripeWebhooks(is_string($secret) ? $secret : null);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
