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
     * @param  array{word_min?: int, word_max?: int, client_word_limit?: int|null, phrase_opens?: bool, deliverables?: list<string>}  $rules  Per-draft limits (ProposalLength, ClientInstructions); Settings when absent.
     * @return list<string>
     */
    public function check(array $draft, ?string $testPhrase, string $sourceText, float $minimumBid = 0, ?string $briefText = null, ?string $factBankText = null, array $rules = []): array
    {
        $text = (string) ($draft['proposal_text'] ?? '');
        $failures = [];

        $words = self::wordCount($text);
        $min = $rules['word_min'] ?? $this->settings->int('word_min');
        $max = $rules['word_max'] ?? $this->settings->int('word_max');
        if (! empty($rules['client_word_limit']) && $words > $rules['client_word_limit']) {
            $failures[] = "Word count is {$words}; the client asked for at most {$rules['client_word_limit']} words.";
        } elseif ($words < $min || $words > $max) {
            $failures[] = "Word count is {$words}; must be {$min}-{$max} for this job.";
        }

        if (! empty($rules['phrase_opens']) && $testPhrase && ! ClientInstructions::opensWith($text, $testPhrase)) {
            $failures[] = "Must begin with the client's required phrase \"{$testPhrase}\", before anything else.";
        }

        $failures = array_merge($failures, self::genericLanguage($text, $testPhrase));

        if ($phrase = self::overpromise($text, (string) $factBankText)) {
            $failures[] = "Promises more than can be known before looking at the site (\"{$phrase}\").";
        }
        if ($phrase = self::unsupportedClaim($text)) {
            $failures[] = "Makes a general claim or statistic with no source (\"{$phrase}\").";
        }
        if ($factBankText !== null && ($phrase = self::unverifiedAvailability($text, $factBankText))) {
            $failures[] = "Claims availability the fact bank does not state (\"{$phrase}\").";
        }
        if ($phrase = self::conflictingDelivery($text, $draft['delivery_days'] ?? null)) {
            $failures[] = "Delivery in the text (\"{$phrase}\") differs from the bid's delivery time.";
        }
        if ($missing = self::missingDeliverables($text, $rules['deliverables'] ?? [])) {
            $failures[] = 'Does not mention these requested deliverables: '.implode('; ', $missing).'. Cover each one briefly, grouped naturally.';
        }
        if ($phrase = self::credentialRequest($text)) {
            $failures[] = "Asks for passwords or logins in chat (\"{$phrase}\"); ask for a staging copy or a temporary user instead.";
        }
        if (self::stepList($text)) {
            $failures[] = 'Reads like a step list (First... Then... Finally...); describe the work in a sentence or two.';
        }

        foreach ($this->settings->list('banned_phrases') as $phrase) {
            if (mb_stripos($text, $phrase) !== false) {
                $failures[] = "Contains banned phrase \"{$phrase}\".";
            }
        }

        if ($testPhrase !== null && $testPhrase !== '' && mb_stripos($text, $testPhrase) === false) {
            $failures[] = "Missing the client's test phrase \"{$testPhrase}\".";
        }

        $failures = array_merge($failures, self::contactViolations($text, $briefText));

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

        if ($phrase = self::linkRestrictionPhrase($text)) {
            $failures[] = "Says links can't be shared (\"{$phrase}\"); point to the Freelancer portfolio or offer samples in chat instead.";
        }

        if ($fragment = self::statsFragment($text)) {
            $failures[] = "Stats written as a fragment (\"{$fragment}\"); work them into a sentence.";
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

    /** Meeting tools that are also products a client can ask to integrate (e.g. Zoom classes in LearnDash). */
    private const MEETING_TOOLS = ['zoom', 'google meet'];

    /**
     * @param  string|null  $briefText  When the client's brief names a meeting tool, the proposal may name it as
     *                                  the thing being integrated ("Zoom integration", "your Zoom account"), never as
     *                                  a way to talk ("a quick Zoom call", "discuss it on Zoom").
     * @return list<string>
     */
    public static function contactViolations(string $text, ?string $briefText = null): array
    {
        $out = [];
        foreach (self::MEETING_TOOLS as $tool) {
            if ($briefText === null || ! preg_match('/\b'.preg_quote($tool, '/').'\b/i', $briefText)) {
                continue;
            }
            $t = preg_quote($tool, '/');
            $asContact = '/\b(?:call|chat|talk|speak|discuss\w*|catch up|hop on|jump on|get on|meet(?:ing)? (?:with )?(?:me|you)|let\'?s)\b[^.?!]{0,30}\b'.$t.'\b'
                .'|\b'.$t.'\b(?:\s+\w+){0,2}?\s+(?:call|calls|chat|with me|with you|to (?:discuss|talk|chat|go over))\b/i';
            if (preg_match($asContact, $text, $m)) {
                $out[] = "Mentions off-platform contact (\"{$m[0]}\").";

                return $out;
            }
            // The client's own integration: the tool followed by what is being integrated, or after "connect/integrate ... with".
            $asTech = '/\b'.$t.'\b(?=\s+(?:integration|api|apps?|account|plan|meetings?|class(?:es)?|sessions?|webinars?|join (?:links?|buttons?)|links?|registration|waiting rooms?|rooms?|licen[cs]e|sdk|recordings?|scheduling|events?)\b)'
                .'|\b(?:integrat\w*|connect\w*|sync\w*|link\w*)\s+(?:\w+\s+){0,3}?(?:with|to|into)\s+(?:your\s+|the\s+)?'.$t.'\b/i';
            $text = preg_replace_callback($asTech, fn ($m) => preg_replace('/'.$t.'/i', 'MEETINGTOOL', $m[0]), $text);
        }

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

    /** Filler that claims expertise instead of showing it. Always checked, on top of the Settings banned list. */
    private const GENERIC_PHRASES = [
        'as an accomplished', 'ideal candidate', 'perfect candidate', 'suitable candidate', 'perfect fit for',
        'wealth of experience', 'fundamental skills', 'necessary skills', 'I possess', 'rest easy', 'rest assured',
        'work tirelessly', 'passionate about', 'bring your vision to life', 'in safe hands', 'I would be delighted',
        "I'd be delighted", 'highest quality', 'I understand the importance', 'TLC', 'my expertise allows',
        'look forward to working with you', "I'd love to work", 'I would love to work', 'sounds interesting',
        'state-of-the-art', 'above and beyond', 'exceed your expectations', 'tailored to your needs',
        'I am confident that I', "I'm confident that I", 'I believe I can', 'I think I might',
    ];

    /** Openings that read like a routine application. Checked on the first sentence after any required phrase. */
    private const GENERIC_OPENER = '/^(?:(?:hi|hello|hey)(?: there)?[,.!]?\s+)?(?:I understand (?:that )?you(?:\'re| are)? (?:need|looking|want)|I can help(?: you)? with|I have (?:experience|worked)|I\'ve (?:got )?experience|For your project|As an? (?:experienced|accomplished|skilled|expert|professional)|I(?:\'d| would) love to|Your project (?:sounds|looks|is) (?:interesting|great)|I am writing|I\'m writing|Thank(?:s| you) for (?:posting|sharing)|I(?: have|\'ve)? read your|I am (?:a|an) |I\'m (?:a|an) )/iu';

    /** @return list<string> */
    public static function genericLanguage(string $text, ?string $testPhrase = null): array
    {
        $out = [];
        foreach (self::GENERIC_PHRASES as $phrase) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($phrase, '/').'(?![\p{L}])/iu', $text)) {
                $out[] = "Generic filler (\"{$phrase}\"); show the expertise instead.";
            }
        }

        $opening = ltrim($text);
        if ($testPhrase && ClientInstructions::opensWith($opening, $testPhrase)) {
            $opening = ltrim(preg_replace('/^[^\p{L}\p{N}]*'.preg_quote(trim($testPhrase, " .,!\"'"), '/').'[^\p{L}\p{N}]*/iu', '', $opening));
        }
        if (preg_match(self::GENERIC_OPENER, $opening, $m)) {
            $out[] = 'Generic opening ("'.trim($m[0]).'..."); open with the client\'s problem, a recommendation or a diagnosis.';
        }

        return $out;
    }

    /** Guarantees and certainty nobody has before seeing the site. A guarantee in the fact bank is allowed. */
    public static function overpromise(string $text, string $factBankText = ''): ?string
    {
        $pattern = '/\bguarantee[sd]?\b|\b100\s?%|\bI (?:already )?know exactly (?:what|why)\b|\bdefinitely (?:fix|solve)\b|\bno matter what\b|\bwithin minutes\b|\bwill never (?:break|fail|happen)\b/iu';
        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        return stripos($m[0], 'guarantee') !== false && stripos($factBankText, 'guarantee') !== false ? null : $m[0];
    }

    /** Invented statistics and sweeping claims ("most membership sites fail"), and put-downs of other bidders. */
    public static function unsupportedClaim(string $text): ?string
    {
        $pattern = '/\b(?:most|many|the majority of|\d+\s?%\s+of)\s+(?:[\w-]+\s+){0,3}?(?:sites|websites|stores|shops|businesses|owners|projects|checkouts|customers|users|visitors|carts)\s+(?:fail|lose|leak|abandon|never|break|struggle)\w*\b'
            .'|\b(?:studies|research|data) (?:show|shows|suggest|suggests)\b|\bstatistic(?:s|ally)\b'
            .'|\b(?:most|other|many) (?:freelancers|developers|bidders)\b/iu';

        return preg_match($pattern, $text, $m) ? $m[0] : null;
    }

    /** "I can start today", "same-day delivery": only when the fact bank's availability notes say so. */
    public static function unverifiedAvailability(string $text, string $factBankText): ?string
    {
        $pattern = '/\b(?:start|begin|available|availability|free)\b[^.?!]{0,25}?\b(?:today|tonight|now|immediately|right away|straight away)\b|\bsame[- ]day\b|\bimmediately available\b/iu';
        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        return preg_match('/\b(?:today|same[- ]day|immediately|right away|straight away|start now)\b/iu', $factBankText) ? null : $m[0];
    }

    /** A delivery time in the text that is not the bid's delivery time ("deliver within 5 days" on a 1-day bid). */
    public static function conflictingDelivery(string $text, mixed $deliveryDays): ?string
    {
        // Future delivery wording only, so past work from the fact bank ("finished it in 2 days") is not caught.
        $pattern = '/\b(?:deliver(?:y|ing)?|turnaround|timeline|ready|within)\s+(?:in\s+|within\s+)?(?:about\s+|around\s+|roughly\s+)?(\d+)\s+(?:working\s+|business\s+)?days?\b/iu';
        if (! preg_match_all($pattern, $text, $m, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($m as $match) {
            if ($deliveryDays === null || (int) $match[1] !== (int) $deliveryDays) {
                return $match[0];
            }
        }

        return null;
    }

    /** Words that say nothing about which deliverable is meant ("page", "setup"). */
    private const DELIVERABLE_FILLER = [
        'page', 'pages', 'site', 'website', 'section', 'sections', 'setup', 'with', 'from', 'that', 'this', 'into',
        'using', 'build', 'create', 'custom', 'full', 'main', 'only', 'your', 'their', 'need', 'needs', 'new', 'and',
        'for', 'the', 'add', 'integration', 'system', 'feature', 'features', 'option', 'options', 'functionality',
    ];

    /** Same meaning, different words: normalised on both sides before matching. */
    private const SYNONYMS = [
        '/\b(?:sign|log)[\s-]?in\b/i' => 'login',
        '/\b(?:sign[\s-]?up|register|registration)\b/i' => 'signup',
        '/\baffiliates?\b/i' => 'partner',
        '/\bpartners?\b/i' => 'partner',
        '/\bfaqs?\b/i' => 'faq',
        '/\bterms(?: and conditions| of service| of use)?\b/i' => 'terms',
    ];

    /**
     * Requested deliverables (from scoring) with no recognisable word in the proposal.
     * Loose on purpose: one distinctive word (first five letters) is enough, so wording can vary.
     *
     * @param  list<string>  $deliverables
     * @return list<string>
     */
    public static function missingDeliverables(string $text, array $deliverables): array
    {
        $stems = array_flip(self::stems($text));
        $missing = [];
        foreach ($deliverables as $deliverable) {
            $wanted = self::stems((string) $deliverable);
            if ($wanted !== [] && array_intersect_key(array_flip($wanted), $stems) === []) {
                $missing[] = trim((string) $deliverable);
            }
        }

        return $missing;
    }

    /** @return list<string> */
    private static function stems(string $text): array
    {
        $text = preg_replace(array_keys(self::SYNONYMS), array_values(self::SYNONYMS), $text);
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);

        return array_values(array_unique(array_map(
            fn ($w) => mb_substr($w, 0, 5),
            array_filter($m[0], fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::DELIVERABLE_FILLER, true)),
        )));
    }

    /** "Send me your admin password": access should come as a staging copy or a temporary user, never the owner's login. */
    public static function credentialRequest(string $text): ?string
    {
        $pattern = '/\b(?:send|share|give|provide|post|need)\b[^.?!]{0,30}?\b(?:(?:admin|wp-admin|cpanel|hosting|ftp|your)\s+)?(?:passwords?|credentials|login details)\b/iu';

        return preg_match($pattern, $text, $m) ? $m[0] : null;
    }

    /** Three or more sentences opening with First / Then / Next / Finally reads like a school assignment. */
    public static function stepList(string $text): bool
    {
        $count = preg_match_all('/(?:^|[.?!:]\s+|\n\s*)(?:First(?:ly)?|Then|Next|After that|Finally|Lastly)\b/u', $text);

        return $count >= 3;
    }

    /** "Links aren't allowed in bids" and similar: draws attention to a limit instead of giving proof. */
    public static function linkRestrictionPhrase(string $text): ?string
    {
        $pattern = '/\b(?:links?|urls?|portfolio links?)\s+(?:are|is)(?:n\'t| not)\s+(?:allowed|permitted)\b'
            .'|\b(?:I\s+)?(?:can\'t|cannot|can not)\s+(?:share|include|send|post|add)\s+(?:a\s+|any\s+)?(?:links?|urls?)\b'
            .'|\bno\s+links?\s+(?:are\s+)?(?:allowed|permitted)\b/iu';

        return preg_match($pattern, $text, $m) ? $m[0] : null;
    }

    /** A stats line with no verb, like "Rating 4.9 from 409 reviews." standing alone. */
    public static function statsFragment(string $text): ?string
    {
        $pattern = '/(?:^|[.?!]\s+|\n)\s*((?:Rating|Rated)\s+[\d.]+(?:\s*\/\s*5)?\s+(?:from|with|on|across)\s+[\d,]+\s+reviews?\.?)\s*(?=$|\n)/imu';

        return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
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
