<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\TaxProvider;
use App\Policies\SubscriptionPolicy;
use App\Services\MercadoPagoGateway;
use App\Services\SimulatedTaxProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TaxProvider::class, SimulatedTaxProvider::class);
        $this->app->bind(PaymentGateway::class, MercadoPagoGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('view-subscription', [SubscriptionPolicy::class, 'view']);
        Gate::define('manage-subscription', [SubscriptionPolicy::class, 'manage']);
        //
    }
}
