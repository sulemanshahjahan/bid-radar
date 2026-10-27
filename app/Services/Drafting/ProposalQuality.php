<?php

namespace App\Services\Drafting;

/**
 * Weighted quality heuristic from the drafting model's self-review (each
 * criterion 0-10). It is the model rating its own draft: useful to catch a
 * weak draft, never evidence that a proposal wins. Hard code checks always
 * win over this score; real outcomes are tracked on the Stats page.
 */
class ProposalQuality
{
    /** criterion => [weight %, what the model rates] */
    public const CRITERIA = [
        'hook' => [20, 'Opening hook: specific to this brief, credible, makes the client keep reading'],
        'understanding' => [15, "Understands the client's real problem and priority"],
        'expertise' => [15, 'Technical insight relevant to this job, shown rather than claimed'],
        'proof' => [10, 'Strongest relevant proof from the fact bank, or none rather than weak or unrelated proof'],
        'execution' => [10, 'Clear approach and deliverables without a routine step list'],
        'timing' => [10, 'Delivery handled competitively and realistically (or left out when none was given)'],
        'tone' => [10, 'Sounds like a confident developer typing, not a brochure or AI'],
        'concision' => [10, 'Every sentence earns its place; right length for the job'],
    ];

    /** Hook types the model chooses from. The chosen one is stored with the draft for win-rate tracking. */
    public const HOOKS = [
        'diagnosis' => 'Problem diagnosis: name the likely causes of the symptom the client describes',
        'risk' => 'Business-risk prevention: the part that costs the client money or customers if it goes wrong',
        'recommendation' => 'Expert recommendation: the better option when the brief offers a choice, and why',
        'fast_fix' => 'Fast, focused fix: a small, clear job framed as targeted work, not a rebuild',
        'simpler' => 'Simpler implementation: native or lighter approach than the client expects',
        'experience' => 'Relevant experience: a directly comparable past project from the fact bank (only if one exists)',
    ];

    /** @param array<string, mixed> $review */
    public static function score(array $review): ?int
    {
        $total = 0.0;
        foreach (self::CRITERIA as $key => [$weight]) {
            if (! is_numeric($review[$key] ?? null)) {
                return null;
            }
            $total += $weight * max(0, min(10, (float) $review[$key])) / 10;
        }

        return (int) round($total);
    }

    /**
     * @param  array<string, mixed>  $review
     * @return list<string> The lowest-rated criteria, for a revision.
     */
    public static function weakest(array $review, int $count = 2): array
    {
        $scores = [];
        foreach (self::CRITERIA as $key => [, $label]) {
            if (is_numeric($review[$key] ?? null)) {
                $scores[$label] = (float) $review[$key];
            }
        }
        asort($scores);

        return array_slice(array_keys($scores), 0, $count);
    }

    public static function hookLabel(?string $hook): ?string
    {
        return $hook && isset(self::HOOKS[$hook]) ? strstr(self::HOOKS[$hook], ':', true) : null;
    }
}
