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
     * @param  string|null  $briefText  The client's brief alone: the only allowed source of a proposed stack.
     * @param  string|null  $factBankText  The fact bank alone: the only allowed source of extras like free support.
     * @return list<string>
     */
    public function check(array $draft, ?string $testPhrase, string $sourceText, float $minimumBid = 0, ?string $briefText = null, ?string $factBankText = null): array
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

        $invented = self::unknownTech($text, $sourceText);
        if ($invented !== []) {
            $failures[] = 'Names tech not in the brief or fact bank: '.implode(', ', $invented).'.';
        }

        if ($briefText !== null) {
            $chosen = self::proposedStack($text, $briefText);
            if ($chosen !== []) {
                $failures[] = 'Proposes a stack the client did not ask for: '.implode(', ', $chosen).'.';
            }
        }

        if ($phrase = self::underminingPhrase($text)) {
            $failures[] = "Talks down the freelancer's experience (\"{$phrase}\").";
        }

        if ($factBankText !== null && ($extra = self::inventedExtra($text, $factBankText))) {
            $failures[] = "Offers an extra that is not in the fact bank (\"{$extra}\").";
        }

        $unknown = array_values(array_unique(array_merge(
            self::unknownNumbers($text, $sourceText, $draft),
            self::unknownSpelledAmounts($text, $sourceText, $draft),
        )));
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

    /** Frameworks, CMSs, languages and tools the draft may only name if the brief or fact bank does. */
    private const TECH = [
        'Laravel', 'Livewire', 'Filament', 'Symfony', 'CodeIgniter', 'CakePHP', 'Yii', 'Django', 'Flask', 'FastAPI',
        'Ruby on Rails', 'Node.js', 'NodeJS', 'NestJS', 'Next.js', 'Nuxt', 'React', 'React Native', 'Vue',
        'Vue.js', 'Angular', 'Svelte', 'jQuery', 'Tailwind', 'Bootstrap', 'WordPress', 'WooCommerce', 'Shopify',
        'Magento', 'PrestaShop', 'OpenCart', 'Drupal', 'Joomla', 'Wix', 'Squarespace', 'Webflow', 'Elementor', 'Divi',
        'Python', 'Ruby', 'Java', 'Kotlin', 'Flutter', 'TypeScript', 'JavaScript', 'PHP', '.NET', 'ASP.NET',
        'MySQL', 'PostgreSQL', 'MongoDB', 'Redis', 'Firebase', 'Supabase', 'AWS', 'Azure', 'Google Cloud', 'Docker',
        'Kubernetes',
    ];

    /** @return list<string> Tech names in the proposal that neither the brief nor the fact bank mentions. */
    public static function unknownTech(string $text, string $sourceText): array
    {
        $found = [];
        foreach (self::TECH as $name) {
            // Case-sensitive in the proposal (so "react quickly" is not React), any case in the sources.
            $pattern = '/(?<![\p{L}\p{N}.])'.preg_quote($name, '/').'(?![\p{L}\p{N}]|\.[\p{L}])/u';
            if (preg_match($pattern, $text) && ! preg_match($pattern.'i', $sourceText)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Tech the proposal puts forward as the solution ("build this as a WordPress plugin",
     * "using Laravel") that the client did not name. Past experience ("I've fixed WooCommerce
     * checkouts") is fine; only present/future solution wording counts.
     *
     * @return list<string>
     */
    public static function proposedStack(string $text, string $briefText): array
    {
        $verbs = '(?:build|develop|create|make|implement|code|write|use|using|set up|base it on|run it on|as an?|with an?|in an?)';
        $found = [];
        foreach (self::TECH as $name) {
            $tech = '(?<![\p{L}\p{N}.])'.preg_quote($name, '/').'(?![\p{L}\p{N}]|\.[\p{L}])';
            $solution = '/\b'.$verbs.'\b[^.?!]{0,40}?'.$tech.'/u';
            if (preg_match($solution, $text) && ! preg_match('/'.$tech.'/iu', $briefText)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    private const NUMBER_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
        'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'fifteen' => 15, 'twenty' => 20, 'thirty' => 30,
        'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'ninety' => 90, 'hundred' => 100,
    ];

    /**
     * Spelled-out amounts with a unit ("two weeks", "thirty days") must come from the sources
     * too, written either way. Plain words like "first" or "one question" are not amounts.
     *
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    public static function unknownSpelledAmounts(string $text, string $sourceText, array $draft): array
    {
        $words = implode('|', array_keys(self::NUMBER_WORDS));
        $units = '(?:hours?|days?|weeks?|months?|years?|revisions?|rounds?|calls?)';
        preg_match_all('/\b('.$words.')\s+(?:full\s+|free\s+|business\s+|working\s+)?'.$units.'\b/iu', $text, $m, PREG_SET_ORDER);

        $allowed = array_flip(self::numbers($sourceText));
        foreach (['bid_amount', 'delivery_days'] as $key) {
            if (isset($draft[$key])) {
                $allowed[self::normalizeNumber((string) $draft[$key])] = true;
            }
        }

        $unknown = [];
        foreach ($m as $match) {
            $word = strtolower($match[1]);
            $inSource = preg_match('/\b'.$word.'\b/iu', $sourceText) || isset($allowed[(string) self::NUMBER_WORDS[$word]]);
            if (! $inSource) {
                $unknown[] = strtolower($match[0]);
            }
        }

        return array_values(array_unique($unknown));
    }

    /** Free support, warranties, bug-fix periods etc. are only allowed if the fact bank offers them. */
    public static function inventedExtra(string $text, string $factBankText): ?string
    {
        // Only "extra service" wording, so scope like "includes support for multiple hotels" is fine.
        $extra = '(?:support|bug[- ]?fix(?:ing|es)?|maintenance|warranty|revisions?)';
        $pattern = '/\b(?:free|complimentary)\s+(?:\w+\s+){0,3}?'.$extra.'\b'
            .'|\b'.$extra.'\b[^.?!]{0,30}?\b(?:is|are) (?:included|free)\b'
            .'|\b(?:post[- ]launch|after[- ]launch|after delivery|after handover)\b[^.?!]{0,30}?\b(?:'.$extra.'|fixes)\b/iu';

        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        preg_match('/'.$extra.'/iu', $m[0], $kw);
        $keyword = strtolower(rtrim($kw[0] ?? '', 's'));

        return $keyword !== '' && mb_stripos($factBankText, $keyword) !== false ? null : trim($m[0]);
    }

    /** First phrase that puts down the freelancer's experience or argues against hiring them. */
    public static function underminingPhrase(string $text): ?string
    {
        $pattern = '/\b(?:(?:this|it) (?:is|would be) (?:a )?(?:new|unfamiliar)(?: \w+){0,2} for me'
            .'|new (?:build|territory|area|ground) for me'
            .'|outside (?:of )?my (?:usual|normal|main|core|typical|focus|comfort|experience|expertise)'
            .'|not my (?:usual|normal|main|core|typical) (?:work|area|focus|field)'
            .'|my background is [^.]{0,80}?\bso\b'
            .'|I (?:have|\'ve) (?:never|not|n\'t) (?:built|done|worked|made)'
            .'|I haven\'t (?:built|done|worked|made)'
            .'|I(?: am|\'m) not (?:a|an|really a|really an) (?:expert|specialist)'
            .'|I (?:don\'t|do not) usually'
            .'|(?:the )?first time I(?:\'d| would| will)? (?:build|do|work)'
            .'|I may not be the (?:best|right))/iu';

        return preg_match($pattern, $text, $m) ? $m[0] : null;
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
