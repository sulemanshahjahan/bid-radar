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
            'Website Optimization', 'Website Management', 'Web Security',
        ],
        'exclude_keywords' => [
            'SEO', 'backlinks', 'Google Business Profile', 'GMB', 'local SEO',
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

        // Models
        'scoring_model' => 'claude-haiku-5-5',
        'scoring_effort' => 'low',
        'drafting_model' => 'claude-sonnet-5-5',
        'drafting_effort' => 'medium',

        // Cost and volume
        'daily_spend_cap_usd' => 2.00,
        'weekly_bid_cap' => 50,
        'polling_enabled' => false,
        'poll_interval_minutes' => 2,
        'paused' => false, // Telegram /pause and /resume

        // Pricing
        'hourly_rate_usd' => 25,
        'floor_price_usd' => 25,
        'avg_bid_factor_low' => 0.90,
        'avg_bid_factor_high' => 1.00,

        // Writing
        'word_min' => 80,
        'word_max' => 150,
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
    ],

    // Fixed-price scope check: warn (and stop matching the average bid) when the
    // estimated hours x hourly rate is more than this many times the price.
    'scope_warning_factor' => 2,

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
