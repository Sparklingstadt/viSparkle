<?php

namespace App\Providers;

use App\Billing\BillingException;
use App\Billing\FakeGateway;
use App\Billing\PaymentGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function (Application $app): PaymentGateway {
            return match ($driver = config('fanbox.billing.driver')) {
                // StripeGateway arrives with the Stripe integration.
                'stripe' => throw new BillingException('stripe driver not available'),
                'fake' => $app->make(FakeGateway::class),
                default => throw new InvalidArgumentException("Unknown billing driver [{$driver}]."),
            };
        });

        // The same instance backs the interface when the driver is fake, so test and dev code can reach its controls.
        $this->app->singleton(FakeGateway::class, function (Application $app): FakeGateway {
            if (config('fanbox.billing.driver') !== 'fake') {
                throw new BillingException('The fake billing driver is not active.');
            }

            if ($app->isProduction()) {
                throw new LogicException('The fake billing driver cannot be used in production.');
            }

            return new FakeGateway(Cache::store());
        });
    }
}
