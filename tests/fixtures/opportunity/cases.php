<?php

/**
 * SYNTHETIC evaluation set for Opportunity Intelligence. Nothing here is observed Freelancer data.
 * Used by OpportunityScorerTest and `php artisan bidradar:opportunity-compare --synthetic`.
 *
 * fact_bank restates the owner's examples A-E from the Opportunity Intelligence brief (as a
 * stand-in for the real fact bank, which stays on the server). cases are invented projects;
 * "scoring" mimics the existing scoring reply. "expect" is what a reviewer should look for.
 */
$client = fn (array $c = []) => array_merge(['payment_verified' => true, 'rating' => 4.9, 'reviews' => 12, 'projects' => 15], $c);

return [
    'fact_bank' => [
        ['category' => 'other', 'problem' => 'Client needed a responsive HTML5 section of seven cards built from a design', 'what_i_did' => 'Built the seven-card section in HTML5 and CSS3, responsive on mobile', 'result' => 'Delivered in about two hours, far faster than expected; positive feedback for fast, accurate delivery'],
        ['category' => 'fix', 'problem' => 'Elementor styling conflict: page looked different in the editor and on the live site', 'what_i_did' => 'Found the CSS conflict and fixed the Elementor styling', 'result' => 'Fixed in about 20 minutes; positive client feedback'],
        ['category' => 'fix', 'problem' => 'WordPress caching problem for a repeat client', 'what_i_did' => 'Diagnosed and resolved the cache issue', 'result' => 'Positive feedback'],
        ['category' => 'new_build', 'problem' => 'WordPress membership website', 'what_i_did' => 'Built the membership site with members-only content', 'result' => 'Excellent feedback for execution, communication and responsiveness'],
        ['category' => 'new_build', 'problem' => 'WordPress Divi landing page', 'what_i_did' => 'Designed and built the Divi landing page, responsive', 'result' => 'Positive feedback on delivery speed and design'],
    ],

    'cases' => [
        'S1' => [
            'name' => 'Elementor CSS display bug, $80',
            'title' => 'Elementor page displays differently on the live site',
            'description' => 'The Elementor editor shows the page correctly but the live site has broken spacing and fonts. Need the CSS fixed and checked on mobile.',
            'details' => ['type' => 'fixed', 'budget_min' => 50, 'budget_max' => 110, 'skills' => ['WordPress', 'Elementor', 'CSS'], 'client' => $client()],
            'scoring' => ['fit_score' => 82, 'category' => 'fix', 'estimated_hours' => 2, 'quick_fix' => true, 'large_vague_or_off_niche' => false, 'deliverables' => ['Elementor styling fix'], 'dependencies' => [], 'red_flags' => []],
            'bids' => 8, 'age_minutes' => 3,
            'expect' => 'Class A: comparable Elementor fix, CSS3 ranking, viable hourly.',
        ],
        'S2' => [
            'name' => 'Complete Shopify store, $150',
            'title' => 'Build a complete Shopify store with custom design and payments',
            'description' => 'Need a full Shopify store: custom design, 30 pages, payment gateway setup, product import, apps and custom features.',
            'details' => ['type' => 'fixed', 'budget_min' => 100, 'budget_max' => 200, 'skills' => ['Shopify', 'Website Design'], 'client' => $client()],
            'scoring' => ['fit_score' => 55, 'category' => 'new_build', 'estimated_hours' => 60, 'quick_fix' => false, 'large_vague_or_off_niche' => true, 'deliverables' => ['Store design', 'Custom theme', '30 pages', 'Payment gateway', 'Product import', 'Apps', 'Custom features'], 'dependencies' => ['Payment provider account', 'App subscriptions', 'Product data'], 'red_flags' => ['Unrealistic budget for the scope']],
            'bids' => 40, 'age_minutes' => 30,
            'expect' => 'Class C: weak commercial fit despite Shopify skill.',
        ],
        'S3' => [
            'name' => 'HTML5 responsive component, $120',
            'title' => 'Responsive HTML5 card component from our design',
            'description' => 'Build a responsive HTML5/CSS component (six cards with icons) from our Figma design, to drop into an existing site.',
            'details' => ['type' => 'fixed', 'budget_min' => 80, 'budget_max' => 160, 'skills' => ['HTML5', 'CSS3', 'HTML'], 'client' => $client(['rating' => null, 'reviews' => 0, 'projects' => 0])],
            'scoring' => ['fit_score' => 70, 'category' => 'other', 'estimated_hours' => 3, 'quick_fix' => true, 'large_vague_or_off_niche' => false, 'deliverables' => ['Card component'], 'dependencies' => [], 'red_flags' => []],
            'bids' => 12, 'age_minutes' => 5,
            'expect' => 'Class A: comparable seven-card build, HTML5/CSS3 rankings; new client not penalised.',
        ],
        'S4' => [
            'name' => 'Generic PHP application, $600',
            'title' => 'PHP web application for our business',
            'description' => 'We need a PHP application to manage our operations. Details to be discussed. Should be scalable and modern.',
            'details' => ['type' => 'fixed', 'budget_min' => 400, 'budget_max' => 800, 'skills' => ['PHP', 'MySQL'], 'client' => $client()],
            'scoring' => ['fit_score' => 45, 'category' => 'new_build', 'estimated_hours' => null, 'quick_fix' => false, 'large_vague_or_off_niche' => true, 'deliverables' => [], 'dependencies' => [], 'red_flags' => ['Vague brief']],
            'bids' => 25, 'age_minutes' => 20,
            'expect' => 'Below the specialist projects: vague, no comparable work, effort unknown.',
        ],
        'S5' => [
            'name' => 'WordPress payment gateway troubleshooting, $200',
            'title' => 'WooCommerce Stripe payment gateway failing at checkout',
            'description' => 'Some Stripe payments fail at WooCommerce checkout with an error. Need it diagnosed and fixed.',
            'details' => ['type' => 'fixed', 'budget_min' => 150, 'budget_max' => 250, 'skills' => ['WooCommerce', 'Payment Gateway Integration', 'WordPress'], 'client' => $client()],
            'scoring' => ['fit_score' => 80, 'category' => 'checkout_payment', 'estimated_hours' => 4, 'quick_fix' => true, 'large_vague_or_off_niche' => false, 'deliverables' => ['Checkout fix'], 'dependencies' => ['Stripe dashboard access'], 'red_flags' => []],
            'bids' => 15, 'age_minutes' => 6,
            'expect' => 'Class B (good): strong rate and payment ranking, but no comparable payment project in this fact bank.',
        ],
        'P_A' => [
            'name' => 'Elementor live/editor mismatch, $75 (brief project A)',
            'title' => 'Elementor live vs editor CSS mismatch',
            'description' => 'Fonts and spacing differ between Elementor editor and live page. Quick fix needed.',
            'details' => ['type' => 'fixed', 'budget_min' => 50, 'budget_max' => 100, 'skills' => ['Elementor', 'WordPress'], 'client' => $client(['rating' => null, 'reviews' => 0, 'projects' => 0])],
            'scoring' => ['fit_score' => 80, 'category' => 'fix', 'estimated_hours' => 2, 'quick_fix' => true, 'deliverables' => ['Styling fix'], 'dependencies' => [], 'red_flags' => []],
            'bids' => 20, 'age_minutes' => 15,
            'expect' => 'Class A.',
        ],
        'P_D' => [
            'name' => 'Extensive custom PHP, vague, $100 (brief project D)',
            'title' => 'Custom PHP development',
            'description' => 'Extensive custom PHP development, more details later. Low budget.',
            'details' => ['type' => 'fixed', 'budget_min' => 50, 'budget_max' => 150, 'skills' => ['PHP'], 'client' => $client(['payment_verified' => null, 'rating' => null, 'reviews' => 0, 'projects' => 0])],
            'scoring' => ['fit_score' => 35, 'category' => 'other', 'estimated_hours' => 40, 'quick_fix' => false, 'large_vague_or_off_niche' => true, 'deliverables' => [], 'dependencies' => [], 'red_flags' => ['Vague brief', 'Unrealistic budget']],
            'bids' => 30, 'age_minutes' => 40,
            'expect' => 'Class C.',
        ],
        'X1' => [
            'name' => 'Membership site build, £500',
            'title' => 'WordPress membership site with paid members-only content',
            'description' => 'Need a WordPress membership website: paid sign-up, members-only course pages, login and account pages.',
            'details' => ['type' => 'fixed', 'budget_min' => 400, 'budget_max' => 700, 'currency' => 'GBP', 'exchange_rate' => 1.27, 'skills' => ['WordPress', 'PHP'], 'client' => $client()],
            'scoring' => ['fit_score' => 72, 'category' => 'new_build', 'estimated_hours' => 24, 'quick_fix' => false, 'large_vague_or_off_niche' => false, 'deliverables' => ['Membership setup', 'Sign-up and payment', 'Members-only pages', 'Login and account pages'], 'dependencies' => ['Payment provider account'], 'red_flags' => []],
            'bids' => 22, 'age_minutes' => 25,
            'expect' => 'A or high B: comparable membership build, budget fits a legitimate larger job.',
        ],
        'X2' => [
            'name' => 'Shopify Dawn customisation, $120',
            'title' => 'Shopify Dawn theme: size chart popup and product layout',
            'description' => 'Add a size chart popup to product pages in our Dawn theme and move the description under the add to cart button. Prefer theme code over an app.',
            'details' => ['type' => 'fixed', 'budget_min' => 60, 'budget_max' => 150, 'skills' => ['Shopify', 'Liquid'], 'client' => $client()],
            'scoring' => ['fit_score' => 70, 'category' => 'other', 'estimated_hours' => 4, 'quick_fix' => true, 'large_vague_or_off_niche' => false, 'deliverables' => ['Size chart popup', 'Product layout change'], 'dependencies' => [], 'red_flags' => []],
            'bids' => 18, 'age_minutes' => 8,
            'expect' => 'B: real Shopify theme skill (ranking ~24%), no documented Shopify project.',
        ],
        'X3' => [
            'name' => 'Same Elementor fix, crowded and old',
            'title' => 'Elementor page displays differently on the live site',
            'description' => 'The Elementor editor shows the page correctly but the live site has broken spacing and fonts. Need the CSS fixed.',
            'details' => ['type' => 'fixed', 'budget_min' => 50, 'budget_max' => 110, 'skills' => ['WordPress', 'Elementor', 'CSS'], 'client' => $client()],
            'scoring' => ['fit_score' => 82, 'category' => 'fix', 'estimated_hours' => 2, 'quick_fix' => true, 'deliverables' => ['Elementor styling fix'], 'dependencies' => [], 'red_flags' => []],
            'bids' => 120, 'age_minutes' => 14 * 60,
            'expect' => 'Lower than S1 (competition), but not written off: still B.',
        ],
        'X4' => [
            'name' => 'WordPress fix, client asks for WhatsApp',
            'title' => 'Fix WordPress site errors',
            'description' => 'Several errors on my WordPress site after a plugin update. Contact me on WhatsApp.',
            'details' => ['type' => 'fixed', 'budget_min' => 30, 'budget_max' => 80, 'skills' => ['WordPress', 'PHP'], 'client' => $client(['payment_verified' => false, 'rating' => null, 'reviews' => 0, 'projects' => 0])],
            'scoring' => ['fit_score' => 50, 'category' => 'fix', 'estimated_hours' => 3, 'quick_fix' => false, 'deliverables' => [], 'dependencies' => [], 'red_flags' => ['Asks to contact off-platform on WhatsApp']],
            'bids' => 5, 'age_minutes' => 4,
            'expect' => 'Client factor weak (unverified + off-platform), C or low B.',
        ],
        'X5' => [
            'name' => 'Keyword-stuffed brief, vague',
            'title' => 'Website',
            'description' => 'elementor css html5 woocommerce stripe divi membership newsletter cache speed security. Need a website, details later.',
            'details' => ['type' => 'fixed', 'budget_min' => 30, 'budget_max' => 250, 'skills' => ['PHP'], 'client' => $client(['payment_verified' => null, 'rating' => null, 'reviews' => 0, 'projects' => 0])],
            'scoring' => ['fit_score' => 30, 'category' => 'new_build', 'estimated_hours' => null, 'quick_fix' => false, 'large_vague_or_off_niche' => true, 'deliverables' => [], 'dependencies' => [], 'red_flags' => ['Vague brief']],
            'bids' => 10, 'age_minutes' => 10,
            'expect' => 'Not A: keyword overlap cannot replace budget, effort and clarity.',
        ],
    ],
];
