<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only JSON list of the projects that were pushed as alerts (live, scored at or above
 * push_min_score), for the owner's own bidding assistant. Needs the feed token created on the
 * Settings page, sent as "Authorization: Bearer <token>". Only its SHA-256 hash is stored.
 */
class AlertFeedController extends Controller
{
    public const TOKEN_HASH = 'alert_feed_token_hash';

    public function __invoke(Request $request, Settings $settings): JsonResponse
    {
        $hash = (string) $settings->get(self::TOKEN_HASH, '');
        $token = (string) $request->bearerToken();
        if ($hash === '' || $token === '' || ! hash_equals($hash, hash('sha256', $token))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $since = Carbon::createFromTimestamp(max(0, (int) $request->query('since', (string) now()->subHour()->getTimestamp())));

        $projects = Project::query()
            ->where('source', 'freelancer')
            ->whereNull('purged_at')
            ->whereNotNull('scored_at')
            ->where('scored_at', '>', $since)
            ->where('fit_score', '>=', max(1, $settings->int('push_min_score')))
            ->orderBy('scored_at')
            ->limit(20)
            ->with('latestDraft')
            ->get();

        return response()->json([
            'now' => now()->getTimestamp(),
            'projects' => $projects->map(fn (Project $p) => self::item($p))->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function item(Project $p): array
    {
        $d = $p->details ?? [];
        $s = $p->scoring ?? [];
        $draft = $p->latestDraft;

        return [
            'id' => $p->id,
            'scored_at' => $p->scored_at?->getTimestamp(),
            'posted_at' => $p->posted_at?->getTimestamp(),
            'score' => $p->fit_score,
            'category' => $p->category,
            'status' => $p->status,
            'title' => $p->title,
            'freelancer_url' => $p->url,
            'app_url' => route('projects.show', $p),
            'type' => $d['type'] ?? 'fixed',
            'currency' => $d['currency'] ?? 'USD',
            'budget_min' => $d['budget_min'] ?? null,
            'budget_max' => $d['budget_max'] ?? null,
            'bid_count' => $d['bid_count'] ?? null,
            'bid_avg' => $d['bid_avg'] ?? null,
            'payment_verified' => $d['client']['payment_verified'] ?? null,
            'reason' => $s['reason'] ?? null,
            'brief_summary' => $s['brief_summary'] ?? null,
            'client_test_phrase' => $s['client_test_phrase'] ?? null,
            'client_questions' => $s['client_questions'] ?? [],
            'client_requirements' => $s['client_requirements'] ?? [],
            'red_flags' => $s['red_flags'] ?? [],
            'estimated_hours' => $s['estimated_hours'] ?? null,
            'description' => $p->description,
            'draft' => $draft ? [
                'text' => $draft->edited_text ?: $draft->proposal_text,
                'bid_amount' => $draft->bid_amount,
                'delivery_days' => $draft->delivery_days,
                'needs_review' => $draft->needsReview(),
            ] : null,
        ];
    }
}
