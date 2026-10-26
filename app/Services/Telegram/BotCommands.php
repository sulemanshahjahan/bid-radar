<?php

namespace App\Services\Telegram;

use App\Models\ApiCall;
use App\Models\Project;
use App\Services\CostTracker;
use App\Services\Settings;

/**
 * Handles /pause, /resume and /today from the owner's chat.
 */
class BotCommands
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CostTracker $costs,
    ) {}

    public function handle(string $text): string
    {
        $command = strtolower(strtok(trim($text), " @\n") ?: '');

        return match ($command) {
            '/pause' => $this->pause(),
            '/resume' => $this->resume(),
            '/today' => $this->today(),
            default => "Commands:\n/pause - stop polling and alerts\n/resume - start again\n/today - today's alerts, bids and AI spend",
        };
    }

    private function pause(): string
    {
        $this->settings->set(['paused' => true]);

        return '⏸ Paused. No polling or alerts until /resume.';
    }

    private function resume(): string
    {
        $this->settings->set(['paused' => false]);

        return $this->settings->pollingActive()
            ? '▶️ Resumed. Polling every '.$this->settings->int('poll_interval_minutes').' min.'
            : '▶️ Resumed, but polling is switched off in Settings.';
    }

    private function today(): string
    {
        $start = now()->startOfDay();
        $alerts = ApiCall::query()->where('provider', 'telegram')->where('purpose', 'alert')
            ->where('status', 'ok')->where('created_at', '>=', $start)->count();
        $bids = Project::query()->where('bid_placed_at', '>=', $start)->count();
        $spent = $this->costs->spentToday();
        $cap = $this->costs->dailyCap();

        return implode("\n", [
            '📊 Today',
            "Alerts sent: {$alerts}",
            "Bids placed: {$bids}",
            sprintf('AI spend: $%.3f of $%.2f%s', $spent, $cap, $spent >= $cap ? ' (cap reached)' : ''),
            'Polling: '.($this->settings->get('paused') ? 'paused' : ($this->settings->pollingActive() ? 'on' : 'off')),
        ]);
    }
}
