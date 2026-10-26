<?php

namespace App\Services\Telegram;

use App\Models\Draft;
use App\Models\Project;
use App\Services\BidTracker;
use App\Services\CostTracker;
use App\Services\Settings;
use App\Services\SponsorBadge;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends messages to the owner's Telegram chat only (TELEGRAM_CHAT_ID).
 */
class Telegram
{
    public function __construct(private readonly CostTracker $log) {}

    public function configured(): bool
    {
        return (bool) config('bidradar.telegram.bot_token') && (bool) config('bidradar.telegram.chat_id');
    }

    public function isOwnerChat(mixed $chatId): bool
    {
        return $this->configured() && (string) $chatId === (string) config('bidradar.telegram.chat_id');
    }

    /**
     * The project alert. Plays a sound only for a top project (adjusted score at or above
     * Auto-draft and fewer than 10 bids); every other project alert is silent. When a draft
     * is ready, two silent follow-ups carry the proposal text alone, then the bid and delivery,
     * so each can be copied on a phone with one long-press (the numbers also tap-to-copy).
     */
    public function projectAlert(Project $project, bool $quiet = false, bool $capReached = false, ?string $note = null): bool
    {
        $silent = $quiet || ! self::isTopProject($project);
        $sent = $this->send(self::alertText($project, $capReached, $note), self::alertButtons($project), $silent, 'alert');
        if (! $sent) {
            return false;
        }
        $project->forceFill(['alerted_at' => now()])->save();

        $draft = $project->latestDraft()->first();
        if ($draft !== null && ! $capReached && $note === null) {
            $this->send(htmlspecialchars($draft->finalText(), ENT_QUOTES | ENT_HTML5), [], true, 'alert_copy');
            $this->send(self::bidText($draft), [], true, 'alert_copy');
        }

        return true;
    }

    public static function isTopProject(Project $project): bool
    {
        return $project->fit_score !== null
            && $project->fit_score >= app(Settings::class)->int('threshold_high')
            && (int) $project->detail('bid_count', 0) < 10;
    }

    /** Bid amount and delivery days, each in a tap-to-copy code span. */
    public static function bidText(Draft $draft): string
    {
        $amount = rtrim(rtrim(number_format((float) $draft->bid_amount, 2, '.', ''), '0'), '.');
        $lines = ['Bid: <code>'.$amount.'</code> '.$draft->currency.($draft->is_hourly ? ' per hour' : '')];
        $lines[] = $draft->delivery_days
            ? 'Delivery: <code>'.$draft->delivery_days.'</code> days'
            : 'Delivery: not set (no turnaround for this category)';

        return implode("\n", $lines);
    }

    public function reply(string $text): bool
    {
        return $this->send($text, [], false, 'reply');
    }

    /** @param list<array{text: string, url: string}> $buttons */
    public function send(string $html, array $buttons = [], bool $silent = false, string $purpose = 'alert'): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $payload = array_filter([
            'chat_id' => config('bidradar.telegram.chat_id'),
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'disable_notification' => $silent,
            'reply_markup' => $buttons ? ['inline_keyboard' => [$buttons]] : null,
        ], fn ($v) => $v !== null);

        $started = hrtime(true);
        try {
            $response = Http::timeout(10)->post($this->url('sendMessage'), $payload);
            $ok = $response->successful() && $response->json('ok') === true;
            $this->log->logApi('telegram', $purpose, $ok, $response->status(), self::ms($started), $ok ? null : (string) $response->json('description'));

            return $ok;
        } catch (Throwable $e) {
            $this->log->logApi('telegram', $purpose, false, null, self::ms($started), self::redact($e->getMessage()));

            return false;
        }
    }

    /** @return array<string, mixed> */
    public function call(string $method, array $params = []): array
    {
        $response = Http::timeout(15)->post($this->url($method), $params);

        return (array) $response->json();
    }

    public static function alertText(Project $project, bool $capReached = false, ?string $note = null): string
    {
        $d = $project->details ?? [];
        $s = $project->scoring ?? [];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5);
        $cur = $d['currency'] ?? 'USD';
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        $score = (int) $project->fit_score;
        $dot = $score >= app(Settings::class)->int('threshold_high') ? '🟢' : '🟡';
        $category = config('bidradar.categories.'.$project->category, 'Other');

        $budget = match (true) {
            ! empty($d['budget_min']) && ! empty($d['budget_max']) => $n($d['budget_min']).'–'.$n($d['budget_max']).' '.$cur,
            ! empty($d['budget_min']) => $n($d['budget_min']).'+ '.$cur,
            default => 'up to '.$n($d['budget_max'] ?? 0).' '.$cur,
        };
        if (($d['type'] ?? '') === 'hourly') {
            $budget .= '/hr';
        }
        $rate = $project->exists ? BidTracker::perHour($project) : null;
        $bids = (int) ($d['bid_count'] ?? 0).' bids'.(! empty($d['bid_avg']) ? ' (avg '.$n($d['bid_avg']).')' : '')
            .($rate !== null ? ' · '.$rate.'/hr' : '');
        $age = $project->posted_at ? 'posted '.$project->posted_at->diffForHumans(short: true) : '';

        $c = $d['client'] ?? [];
        $client = array_filter([
            ($c['payment_verified'] ?? false) ? '✅ payment verified' : '❌ unverified',
            ! empty($c['rating']) ? '★'.$c['rating'] : null,
            $c['country'] ?? null,
            ! empty($c['projects']) ? $c['projects'].' projects' : null,
        ]);

        $lines = [
            "{$dot} <b>{$score}</b> · {$e($category)} · <b>{$e($project->title)}</b>",
            $e(implode(' · ', array_filter([$budget, $bids, $age]))),
            'Client: '.$e(implode(' · ', $client)),
        ];
        if (! empty($s['reason'])) {
            $lines[] = 'Why: '.$e($s['reason']);
        }
        if ($project->exists && app(SponsorBadge::class)->applies($project)) {
            $lines[] = '💎 <b>Worth sponsoring</b>';
        }
        if (! empty($s['client_test_phrase'])) {
            $lines[] = '⚠️ Client test phrase: <b>"'.$e($s['client_test_phrase']).'"</b>';
        }
        if (! empty($s['red_flags'])) {
            $lines[] = '🚩 '.$e(implode('; ', $s['red_flags']));
        }
        if ($capReached) {
            $lines[] = '💸 Daily AI cap reached: no draft yet.';
        }
        if ($note !== null) {
            $lines[] = '⚠️ '.$e($note);
        }

        return implode("\n", $lines);
    }

    /** @return list<array{text: string, url: string}> */
    public static function alertButtons(Project $project): array
    {
        $buttons = [['text' => 'Open draft', 'url' => route('projects.show', $project)]];
        if ($project->url) {
            $buttons[] = ['text' => 'Open on Freelancer', 'url' => $project->url];
        }

        return $buttons;
    }

    /** The bot token is part of the URL, so never let it reach a log. */
    public static function redact(string $text): string
    {
        $token = (string) config('bidradar.telegram.bot_token');

        return $token !== '' ? str_replace($token, '[redacted]', $text) : $text;
    }

    private function url(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('bidradar.telegram.bot_token').'/'.$method;
    }

    private static function ms(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
