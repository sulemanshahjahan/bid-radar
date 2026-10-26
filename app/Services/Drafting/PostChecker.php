<?php

namespace App\Services\Drafting;

use App\Services\Settings;

/**
 * Code checks run on every draft. Returns a list of human-readable failures
 * (empty list = pass). Spec section 5 + hard rules 4 and 5.
 */
class PostChecker
{
    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    private const URL = '/\b(?:https?:\/\/|www\.)\S+|\b[a-z0-9\-]+\.(?:com|net|org|io|co|pro|dev|me|info|biz|us|uk|in|pk|app|site|xyz|online|store|shop|link|ly|gl)\b(?:\/\S*)?/';

    private const PHONE = '/\+?\d[\d\s().\-]{7,}\d/';

    private const HANDLE = '/(?<![\w.])@[A-Za-z0-9_]{3,}/';

    private const OFF_PLATFORM = '/\b(whats\s?app|telegram|skype|wechat|discord|signal app|viber|zoom|google meet|gmail|outlook|hotmail|my email|e-?mail me|call me|text me|dm me|message me on|contact me (?:at|on|via)|reach me (?:at|on|via)|off[- ]platform|outside (?:of )?freelancer|outside the platform)\b/i';

    private const EMOJI = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u';

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $draft  proposal_text, bid_amount, delivery_days, milestones, is_hourly
     * @param  string  $sourceText  Fact bank + brief: the only allowed source of numbers.
     * @return list<string>
     */
    public function check(array $draft, ?string $testPhrase, string $sourceText, float $minimumBid = 0): array
    {
        $text = (string) ($draft['proposal_text'] ?? '');
        $failures = [];

        $words = self::wordCount($text);
        $min = $this->settings->int('word_min');
        $max = $this->settings->int('word_max');
        if ($words < $min || $words > $max) {
            $failures[] = "Word count is {$words}; must be {$min}-{$max}.";
        }

        foreach ($this->settings->list('banned_phrases') as $phrase) {
            if (mb_stripos($text, $phrase) !== false) {
                $failures[] = "Contains banned phrase \"{$phrase}\".";
            }
        }

        if ($testPhrase !== null && $testPhrase !== '' && mb_stripos($text, $testPhrase) === false) {
            $failures[] = "Missing the client's test phrase \"{$testPhrase}\".";
        }

        $failures = array_merge($failures, self::contactViolations($text));

        if (mb_stripos($text, 'pamlico') !== false) {
            $failures[] = 'Mentions a forbidden company name.';
        }

        // An exclamation mark inside the client's own test phrase is allowed.
        $withoutPhrase = $testPhrase ? str_ireplace($testPhrase, '', $text) : $text;
        if (str_contains($withoutPhrase, '!')) {
            $failures[] = 'Contains an exclamation mark.';
        }
        if (preg_match(self::EMOJI, $text)) {
            $failures[] = 'Contains an emoji.';
        }

        $unknown = self::unknownNumbers($text, $sourceText, $draft);
        if ($unknown !== []) {
            $failures[] = 'Uses numbers not found in the fact bank or brief: '.implode(', ', $unknown).'.';
        }

        return array_merge($failures, self::pricingViolations($draft, $minimumBid));
    }

    /** @return list<string> */
    public static function contactViolations(string $text): array
    {
        $out = [];
        if (preg_match(self::EMAIL, $text)) {
            $out[] = 'Contains an email address.';
        }
        if (preg_match(self::URL, $text)) {
            $out[] = 'Contains a link or web address.';
        }
        if (preg_match_all(self::PHONE, $text, $m)) {
            foreach ($m[0] as $candidate) {
                if (preg_match_all('/\d/', $candidate) >= 8) {
                    $out[] = 'Contains a phone number.';
                    break;
                }
            }
        }
        if (preg_match(self::HANDLE, $text)) {
            $out[] = 'Contains a chat handle.';
        }
        if (preg_match(self::OFF_PLATFORM, $text, $m)) {
            $out[] = "Mentions off-platform contact (\"{$m[0]}\").";
        }

        return $out;
    }

    public static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * Every number in the proposal must come from the fact bank, the brief,
     * or the draft's own price/timeline figures.
     *
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    public static function unknownNumbers(string $text, string $sourceText, array $draft): array
    {
        $allowed = array_flip(self::numbers($sourceText));
        foreach ([$draft['bid_amount'] ?? null, $draft['delivery_days'] ?? null] as $n) {
            if ($n !== null) {
                $allowed[self::normalizeNumber((string) $n)] = true;
            }
        }
        foreach ((array) ($draft['milestones'] ?? []) as $m) {
            $allowed[self::normalizeNumber((string) ($m['amount'] ?? ''))] = true;
        }

        $unknown = [];
        foreach (self::numbers($text) as $n) {
            if (! isset($allowed[$n])) {
                $unknown[$n] = $n;
            }
        }

        return array_values($unknown);
    }

    /** @return list<string> */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $m);

        return array_values(array_unique(array_map(fn ($n) => self::normalizeNumber(rtrim($n, ',')), $m[0])));
    }

    public static function normalizeNumber(string $n): string
    {
        $n = str_replace(',', '', trim($n));
        if (str_contains($n, '.')) {
            $n = rtrim(rtrim($n, '0'), '.');
        }

        return $n === '' ? '0' : $n;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    public static function pricingViolations(array $draft, float $minimumBid): array
    {
        $out = [];
        $bid = (float) ($draft['bid_amount'] ?? 0);

        if ($bid <= 0) {
            $out[] = 'Bid amount is missing.';
        } elseif ($minimumBid > 0 && $bid + 0.001 < $minimumBid) {
            $out[] = 'Bid is below the minimum allowed ('.$minimumBid.').';
        }

        if (! ($draft['is_hourly'] ?? false)) {
            $milestones = (array) ($draft['milestones'] ?? []);
            $count = count($milestones);
            if ($count < 2 || $count > 4) {
                $out[] = "Has {$count} milestones; needs 2-4.";
            }
            $sum = array_sum(array_map(fn ($m) => (float) ($m['amount'] ?? 0), $milestones));
            if ($count > 0 && abs($sum - $bid) > 0.01) {
                $out[] = "Milestones add up to {$sum}, not the bid of {$bid}.";
            }
        }

        return $out;
    }
}
