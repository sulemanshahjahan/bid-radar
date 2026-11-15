<?php

namespace App\Services;

use App\Models\Draft;
use App\Models\Project;

/**
 * One-click bid filling. The "Fill bid" link opens the Freelancer project with the draft's bid
 * (amount, days or hours, proposal text) in the URL fragment; the fragment never reaches any
 * server. The "BidRadar Fill" bookmarklet then fills the bid form from it and ticks the free
 * upgrades and an eligible sponsor. The owner reviews it and presses Place Bid: nothing is ever
 * submitted automatically.
 */
class BidFill
{
    public const FRAGMENT_KEY = 'bidradar';

    /** Bookmarklet source (plain JavaScript, no external requests). */
    public const SCRIPT = 'resources/bidfill/bookmarklet.js';

    /** @return array{a: float, c: string, h: bool, d: int|null, hrs: int|null, t: string, id: int} */
    public static function payload(Project $project, Draft $draft): array
    {
        $hours = $project->scoring['estimated_hours'] ?? null;

        return [
            'id' => $project->id,
            'a' => (float) $draft->bid_amount,
            'c' => (string) $draft->currency,
            'h' => (bool) $draft->is_hourly,
            'd' => $draft->delivery_days !== null ? (int) $draft->delivery_days : null,
            'hrs' => is_numeric($hours) ? max(1, (int) $hours) : null,
            't' => $draft->finalText(),
        ];
    }

    /** The project's Freelancer bid page with the draft in the fragment, or null without a Freelancer URL or draft. */
    public static function link(Project $project): ?string
    {
        $draft = $project->latestDraft;
        if (! $project->url || $draft === null) {
            return null;
        }

        $url = rtrim(preg_replace('/#.*$/', '', $project->url), '/');
        if (! str_ends_with($url, '/details')) {
            $url .= '/details'; // the bid form lives here; linking straight to it avoids a redirect that could drop the fragment
        }
        $json = json_encode(self::payload($project, $draft), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $url.'#'.self::FRAGMENT_KEY.'='.rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    /** The bookmarklet as a javascript: URL, for dragging to the bookmarks bar. */
    public static function bookmarklet(): string
    {
        $js = (string) file_get_contents(base_path(self::SCRIPT));
        // Drop comment-only lines; keep the code readable in the repo, compact in the bookmark.
        $js = implode("\n", array_filter(array_map('rtrim', explode("\n", $js)), fn ($l) => $l !== '' && ! str_starts_with(ltrim($l), '//')));

        return 'javascript:'.rawurlencode('(function(){'.$js."\n})();");
    }
}
