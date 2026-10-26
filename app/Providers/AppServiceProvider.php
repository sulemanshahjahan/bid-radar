<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Claude\AnthropicClaudeClient;
use App\Services\Claude\ClaudeClient;
use App\Services\Settings;
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
        $this->app->singleton(Settings::class);

        $this->app->bind(ClaudeClient::class, fn () => new AnthropicClaudeClient(new Client(
            apiKey: (string) config('bidradar.claude.api_key'),
            requestOptions: ['timeout' => (float) config('bidradar.claude.timeout'), 'maxRetries' => 2],
        )));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
