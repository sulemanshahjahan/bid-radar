<?php

namespace App\Services;

use App\Models\ApiCall;
use App\Services\Claude\ClaudeResponse;
use Illuminate\Support\Facades\Log;

/**
 * Logs every external API call and enforces the daily AI spend cap.
 */
class CostTracker
{
    public function __construct(private readonly Settings $settings) {}

    /** Cost in USD of one Claude response, using per-model prices from config. */
    public function cost(string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): float
    {
        $prices = $this->pricesFor($model);
        if ($prices === null) {
            return 0.0;
        }

        $promptTokens = $input + $cacheWrite + $cacheRead;
        if (isset($prices['long_context_threshold']) && $promptTokens > $prices['long_context_threshold']) {
            $prices = array_merge($prices, $prices['long']);
        }

        return ($input * $prices['input']
            + $output * $prices['output']
            + $cacheWrite * $prices['cache_write']
            + $cacheRead * $prices['cache_read']) / 1_000_000;
    }

    public function logClaude(string $purpose, ?int $projectId, string $requestedModel, ClaudeResponse $response, int $durationMs): ApiCall
    {
        // A server-side fallback may answer with a different model; bill what actually ran.
        $model = $this->pricesFor($response->model) !== null ? $response->model : $requestedModel;

        $call = ApiCall::query()->create([
            'provider' => 'claude',
            'purpose' => $purpose,
            'project_id' => $projectId,
            'model' => $response->model ?: $requestedModel,
            'status' => $response->refused() ? 'refusal' : 'ok',
            'http_status' => 200,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cache_write_tokens' => $response->cacheWriteTokens,
            'cache_read_tokens' => $response->cacheReadTokens,
            'cost_usd' => $this->cost($model, $response->inputTokens, $response->outputTokens, $response->cacheWriteTokens, $response->cacheReadTokens),
            'duration_ms' => $durationMs,
            'error' => $response->refused() ? 'refusal: '.($response->refusalCategory ?? 'unknown') : null,
        ]);

        Log::info('claude call', [
            'purpose' => $purpose, 'project_id' => $projectId, 'model' => $call->model,
            'status' => $call->status, 'in' => $call->input_tokens, 'out' => $call->output_tokens,
            'cache_w' => $call->cache_write_tokens, 'cache_r' => $call->cache_read_tokens,
            'cost_usd' => (float) $call->cost_usd,
        ]);

        return $call;
    }

    /** Log a non-Claude call (Freelancer, Telegram). No tokens or secrets are ever stored. */
    public function logApi(string $provider, string $purpose, bool $ok, ?int $httpStatus, int $durationMs, ?string $error = null): void
    {
        ApiCall::query()->create([
            'provider' => $provider,
            'purpose' => $purpose,
            'status' => $ok ? 'ok' : 'error',
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'error' => $error ? mb_substr($error, 0, 1000) : null,
        ]);

        $context = compact('provider', 'purpose', 'httpStatus', 'durationMs', 'error');
        $ok ? Log::info($provider.' call', $context) : Log::warning($provider.' call failed', $context);
    }

    public function logError(string $provider, string $purpose, ?int $projectId, ?string $model, ?int $httpStatus, string $error, int $durationMs): void
    {
        ApiCall::query()->create([
            'provider' => $provider,
            'purpose' => $purpose,
            'project_id' => $projectId,
            'model' => $model,
            'status' => 'error',
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'error' => mb_substr($error, 0, 1000),
        ]);

        Log::warning($provider.' call failed', compact('purpose', 'projectId', 'model', 'httpStatus', 'error'));
    }

    public function spentToday(): float
    {
        return (float) ApiCall::query()
            ->where('provider', 'claude')
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('cost_usd');
    }

    public function spentThisMonth(): float
    {
        return (float) ApiCall::query()
            ->where('provider', 'claude')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }

    public function dailyCap(): float
    {
        return $this->settings->float('daily_spend_cap_usd');
    }

    public function capReached(): bool
    {
        return $this->spentToday() >= $this->dailyCap();
    }

    /** @return array<string, float|int>|null */
    private function pricesFor(string $model): ?array
    {
        return config('bidradar.prices.'.$model);
    }
}
