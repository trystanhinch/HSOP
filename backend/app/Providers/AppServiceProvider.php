<?php

namespace App\Providers;

use App\Contracts\AiProviderInterface;
use App\Contracts\ConversationalAiProviderInterface;
use App\Contracts\PaymentProviderInterface;
use App\Services\Ai\MockConversationalAiProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Conversational AI is a sibling contract; Phase 1 always uses the mock.
        // Phase 2 will add an OpenAI conversational implementation selectable via config.
        $this->app->singleton(ConversationalAiProviderInterface::class, function ($app) {
            $provider = config('ai.conversational_provider', 'mock');
            $class = config("ai.conversational_providers.{$provider}", MockConversationalAiProvider::class);

            if (! $class || ! class_exists($class)) {
                throw new \InvalidArgumentException("Conversational AI provider [{$provider}] is not configured.");
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
        $this->mergeBrandCorsOrigins();

        RateLimiter::for('public-intake', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('public-intake-start', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        RateLimiter::for('public-intake-message', function (Request $request) {
            $token = $request->input('session_token')
                ?: $request->cookie(config('public.intake_cookie', 'serviceop_intake_token'))
                ?: $request->ip();

            return Limit::perMinute(60)->by('msg:'.$token);
        });

        RateLimiter::for('public-intake-submit', function (Request $request) {
            $token = $request->input('session_token')
                ?: $request->cookie(config('public.intake_cookie', 'serviceop_intake_token'))
                ?: $request->ip();

            return Limit::perMinute(10)->by('submit:'.$token);
        });
    }

    /**
     * CORS allowlist = admin SPA origins + active brand domains + local preview extras.
     * Adding a brand domain is a DB row — no code change.
     */
    private function mergeBrandCorsOrigins(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('brands')) {
                return;
            }

            $brandOrigins = \App\Models\Brand::query()
                ->where('status', 'active')
                ->pluck('domain')
                ->filter()
                ->flatMap(fn (string $domain) => [
                    'https://'.$domain,
                    'http://'.$domain,
                    'https://www.'.$domain,
                    'http://www.'.$domain,
                ])
                ->all();

            $merged = array_values(array_unique(array_filter(array_merge(
                config('cors.allowed_origins', []),
                config('public.extra_cors_origins', []),
                $brandOrigins,
            ))));

            config(['cors.allowed_origins' => $merged]);
            config(['cors.allowed_origins_patterns' => []]);
        } catch (\Throwable) {
            // Table may not exist during early migrate
        }
    }
}
