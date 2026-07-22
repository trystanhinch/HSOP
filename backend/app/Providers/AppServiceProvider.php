<?php

namespace App\Providers;

use App\Contracts\AiProviderInterface;
use App\Contracts\PaymentProviderInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // App Platform may still have SESSION_DRIVER=database in env before migrations run.
        if ($this->app->environment('production')) {
            config(['session.driver' => 'file']);
        }

        $this->app->singleton(AiProviderInterface::class, function ($app) {
            $provider = config('ai.provider', 'mock');
            $class = config("ai.providers.{$provider}");

            if (! $class || ! class_exists($class)) {
                throw new \InvalidArgumentException("AI provider [{$provider}] is not configured.");
            }

            return $app->make($class);
        });

        $this->app->singleton(PaymentProviderInterface::class, function ($app) {
            $provider = config('payment.provider', 'mock');
            $class = config("payment.providers.{$provider}");

            if (! $class || ! class_exists($class)) {
                throw new \InvalidArgumentException("Payment provider [{$provider}] is not configured.");
            }

            return $app->make($class);
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
