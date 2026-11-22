<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\TaxProvider;
use App\Mcp\BusinessAccess;
use App\Policies\SubscriptionPolicy;
use App\Policies\TreasuryPolicy;
use App\Services\MercadoPagoGateway;
use App\Services\SimulatedTaxProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        Gate::define('view-treasury', [TreasuryPolicy::class, 'view']);
        Gate::define('register-bank-receipt', [TreasuryPolicy::class, 'register']);
        Gate::define('manage-treasury', [TreasuryPolicy::class, 'manage']);
        Gate::define('manage-subscription', [SubscriptionPolicy::class, 'manage']);
        Sanctum::authenticateAccessTokensUsing(static fn (PersonalAccessToken $token, bool $valid): bool => $valid
            && ! in_array(BusinessAccess::SCOPE, $token->abilities ?? [], true));
    }
}
