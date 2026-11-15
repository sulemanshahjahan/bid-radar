<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Freelancer API
    |--------------------------------------------------------------------------
    |
    | Polling must stay OFF until written permission from Freelancer exists
    | (User Agreement s.33). The Settings page cannot override this flag.
    |
    */

    'freelancer' => [
        'polling_enabled' => (bool) env('FREELANCER_POLLING_ENABLED', false),
        'oauth_token' => env('FREELANCER_OAUTH_TOKEN'),
        'base_url' => env('FREELANCER_BASE_URL', 'https://www.freelancer.com/api/'),
        'project_url' => 'https://www.freelancer.com/projects/{seo_url}',
        'timeout' => 20,
        'page_limit' => 50,
        // Exponential backoff after a 429/5xx: 2, 4, 8 ... minutes, capped.
        'backoff_max_minutes' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    |
    | Alerts go ONLY to TELEGRAM_CHAT_ID. Updates from any other chat are ignored.
    |
    */

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Claude API
    |--------------------------------------------------------------------------
    */

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
        // Server-side refusal fallback for the drafting model (beta).
        'drafting_fallbacks' => (bool) env('ANTHROPIC_DRAFTING_FALLBACKS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model prices (USD per million tokens)
    |--------------------------------------------------------------------------
    |
    | From platform.claude.com/docs/en/about-claude/pricing (checked 2026-10-26).
    | Haiku 5.5 prices are for prompts up to 100K tokens; the long tier is used
    | above that. Thinking tokens are billed as output.
    |
    */

    'prices' => [
        'claude-haiku-5-5' => [
            'input' => 0.10, 'output' => 0.50, 'cache_write' => 0.125, 'cache_read' => 0.01,
            'long_context_threshold' => 100_000,
            'long' => ['input' => 0.50, 'output' => 2.50, 'cache_write' => 0.625, 'cache_read' => 0.05],
        ],
        'claude-sonnet-5-5' => [
            'input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.10,
        ],
        'claude-opus-5-5' => [
            'input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings defaults
    |--------------------------------------------------------------------------
    |
    | Every value here is editable on the Settings page; these are only the
    | values used until the owner saves something different.
    |
    */

    'defaults' => [
        // Rules filter (used by the Phase 2 poller)
        'target_skills' => [
            'WordPress', 'WooCommerce', 'Shopify', 'PHP', 'MySQL', 'Plugin',
            'Payment Gateway Integration', 'Shopping Cart Integration', 'CMS',
            'Website Optimization', 'Website Management', 'Web Security', 'SEO',
        ],
        'exclude_keywords' => [
            'backlinks', 'Google Business Profile', 'GMB', 'local SEO', // basic SEO is offered
            'AI', 'ChatGPT', 'OpenAI', 'chatbot', 'GPT', 'LLM', 'automation', 'Make.com', 'n8n', 'Zapier',
            'adult', 'porn', 'gambling', 'casino', 'betting', 'crypto exchange', 'money transfer',
            'clone of', 'fake reviews', 'captcha bypass',
        ],
        'new_build_phrases' => [
            'build a website from scratch', 'build a store from scratch', 'from scratch',
            'new Shopify store', 'ecommerce website development',
        ],
        'min_budget_usd' => 25,
        'max_bid_count' => 150,
        'new_build_max_bid_count' => 100,
        'max_age_hours' => 24,

        // Scoring thresholds
        'threshold_high' => 75,
        'threshold_medium' => 50,
        'quiet_alerts' => false,
        'push_min_score' => 1, // browser push for live projects scored at or above this (owner decision 2026-11-07: score > 0)
        'rank_rebuild' => false, // extra score adjustments to rebuild ranking with quick wins
        'skill_rank_scoring' => true, // +10/+5 for projects tagged with my top-10% skills, -10 when none of its skills is in my top 25% (ScoreAdjuster::skillRanks)

        // Models
        'scoring_model' => 'claude-haiku-5-5',
        'scoring_effort' => 'low',
        'drafting_model' => 'claude-sonnet-5-5',
        'drafting_effort' => 'medium', // Draft / Regenerate tapped in the app
        'alert_drafting_effort' => 'low', // automatic drafts after scoring: one pass, ready to bid within minutes

        // Cost and volume
        'daily_spend_cap_usd' => 2.00,
        'weekly_bid_cap' => 50,
        'sponsor_min_budget_usd' => 100, // "Worth sponsoring" needs a budget top at or above this
        'polling_enabled' => false,
        'poll_interval_seconds' => 30, // owner's choice 2026-10-27, within Freelancer's written permission
        'paused' => false, // Telegram /pause and /resume

        // Pricing
        'hourly_rate_usd' => 25,
        'floor_price_usd' => 25,
        'avg_bid_factor_low' => 0.90,
        'avg_bid_factor_high' => 1.00,
        'platform_fee_percent' => 10, // Freelancer's fee on fixed-price work; only used to show take-home pay

        // Delivery: working hours a day I can give one project (Turnaround::plan)
        'delivery_hours_per_day' => 6,

        // Writing
        'word_min' => 80,
        'word_max' => 250, // outer limits; each draft also gets a target range for its size (ProposalLength)
        'banned_phrases' => [
            'As an experienced', 'I have years', 'years of experience', 'Dear Sir', 'Dear Madam',
            'Dear Sir/Madam', 'I am interested in your project', "I'm interested in your project",
            'Hello there', 'I hope this message finds you well', 'look no further', 'cutting-edge',
            'seamless', 'leverage', 'synergy', 'top-notch', 'world-class', 'dedicated team',
        ],

        // Fact bank (the ONLY source of facts about the owner)
        'positioning' => '',
        'stats_rating' => '',
        'stats_reviews' => '',
        'stats_completion' => '',
        'stats_on_time' => '',
        'services_offered' => '',
        'services_not_offered' => '',
        'turnaround_notes' => '',
        'style_notes' => '',
        'portfolio_notes' => '', // what is in my Freelancer profile portfolio (no links)
        'availability_notes' => '', // e.g. "Usually free to start the same day". Drafts only claim what is written here.

        // Opportunity Intelligence only (never sent to the drafting prompt). Owner-reported Freelancer skill
        // rankings, "Skill | top %". Not verified against the live profile; update them from the profile and
        // set the date they were read. Undated or old rankings count as weaker evidence.
        'skill_rankings' => [
            'Newsletters | 1', 'Umbraco | 1', 'HTML5 | 2', 'Templates | 2', 'Blog Design | 3', 'Google Webmaster Tools | 3',
            'LESS/Sass/SCSS | 3', 'CSS3 | 4', 'Plugin | 4', 'API | 5', 'JavaScript | 5', 'Odd Jobs | 5',
            'Payment Gateway Integration | 5', 'PSD to HTML | 5', 'Web Services | 5', 'Blog Install | 6', 'Coding | 6',
            'Design | 6', 'Mailchimp | 6', 'Bootstrap | 7', 'CSS | 7', 'Email Handling | 7', 'Google Chrome | 7',
            'jQuery / Prototype | 7', 'Magento | 7', 'Programming | 7', 'Drupal | 8', 'Frontend Development | 8',
            'Shopping Cart Integration | 8', 'Website Testing | 8', 'Format and Layout | 9', 'Web Development | 9',
            'Web Security | 9', 'WordPress | 9', 'Blog | 10', 'CMS | 10', 'Database Development | 10', 'Blog Writing | 11',
            'MySQL | 12', 'eBay | 13', 'Website Management | 14', 'PHP | 15', 'Email Marketing | 17', 'Java | 18',
            'Word | 18', 'Software Architecture | 19', 'PDF | 20', 'HTML | 21', 'Icon Design | 21', 'UX / User Experience | 21',
            'WooCommerce | 22', 'eCommerce | 23', 'Shopify Templates | 23', 'Banner Design | 24', 'Shopify | 24',
            'Web Hosting | 24', 'SEO | 25', 'Website Design | 25', 'Article Writing | 26', 'Content Writing | 26',
            'Mobile App Development | 26', 'Python | 29', 'User Interface / IA | 30', 'Data Entry | 33', 'Android | 34',
            'Photoshop Design | 34', 'Graphic Design | 44', 'Logo Design | 46', 'Photoshop | 47',
            'API Integration | 100', 'Cloudflare | 100', 'Elementor | 100', 'Page Speed Optimization | 100',
            'Web Application | 100', 'Web Design | 100', 'Website Optimization | 100', 'WordPress Security | 100',
        ],
        'skill_rankings_as_of' => '2026-11-15', // Y-m-d the rankings were read from the profile; empty = unknown
    ],

    /*
    |--------------------------------------------------------------------------
    | Opportunity Intelligence (shadow mode)
    |--------------------------------------------------------------------------
    |
    | A second, personal priority score shown next to the existing one on an
    | experimental page. It never changes scoring, filters, drafting or alerts,
    | stores nothing and makes no AI calls. OPPORTUNITY_SHADOW=false hides it.
    | Class thresholds are provisional until shadow results are reviewed.
    |
    */

    'opportunity' => [
        'enabled' => (bool) env('OPPORTUNITY_SHADOW', true),
        'weights' => ['technical' => 25, 'experience' => 25, 'client' => 15, 'profit' => 15, 'competition' => 15, 'clarity' => 5],
        // A was 75 when early competition earned full marks; it is neutral now (about 7 points lower for nearly
        // every live project at scoring time), so 70 keeps the same bar. A's evidence gates are unchanged.
        'class_a' => 70,
        'class_b' => 55,
        // Class A needs estimated net earnings of at least this share of the hourly target (owner decision 2026-10-28).
        'a_min_profit_ratio' => 0.75,
        // The scoring model's base fit under this caps the class at C (B with strong comparable work in the brief).
        'low_fit_below' => 20,
        // Under this base fit (or a weak technical factor) experience credit is limited to technical + 0.2.
        'weak_fit_below' => 40,
        // Profitability uses min + budget_point x (max - min): an evaluation assumption, not the accepted price.
        'budget_point' => 0.25,
        'ranking_stale_days' => 180,
    ],

    // Fixed-price scope check: warn (and stop matching the average bid) when the
    // estimated hours x hourly rate is more than this many times the price.
    'scope_warning_factor' => 2,

    // Effective hourly rate (take-home after the fee / estimated hours) against my hourly rate.
    // Under the rate: an information note. Below warn_below x rate: a warning with options.
    // Below reduce_scope_below x rate on a job over reduce_scope_min_hours, the draft never
    // offers the full scope (Drafter::scopeMode): a first build phase only if the bid covers
    // at least min_build_phase_hours, else a paid discovery phase if it covers at least
    // min_discovery_hours, else skip. A client demanding the complete scope at a fixed budget
    // also means skip. Nothing here changes the bid.
    'profitability' => [
        'warn_below' => 0.5,
        'reduce_scope_below' => 0.25,
        'reduce_scope_min_hours' => 16,
        'min_build_phase_hours' => 16,
        'min_discovery_hours' => 3,
    ],

    // Delivery from the scoring estimate: hours x buffer / delivery_hours_per_day working
    // days, plus dependency time, as calendar days. The category ranges in Settings are
    // advisory once there is an estimate (Turnaround::plan).
    'delivery' => [
        'buffer' => 1.25,
    ],

    // A draft that passes the code checks but rates itself below this (weighted
    // self-review, ProposalQuality) gets one revision. A self-rating, not a win predictor.
    'min_draft_quality' => 60,

    'categories' => [
        'fix' => 'Fix',
        'security' => 'Security',
        'speed' => 'Speed',
        'checkout_payment' => 'Checkout / Payment',
        'maintenance' => 'Maintenance',
        'b2b_wholesale' => 'B2B / Wholesale',
        'new_build' => 'New build',
        'other' => 'Other',
    ],

];
