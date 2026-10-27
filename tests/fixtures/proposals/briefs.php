<?php

/**
 * Evaluation briefs for the drafting engine (owner's spec, section 21). Used by
 * ProposalStrategyTest (code decisions, no AI) and `php artisan bidradar:try-drafts`
 * (real scoring + drafting, nothing saved). A-C are the owner's real case studies,
 * D-J are synthetic. "expect" is what a reviewer should look for in the output.
 */
return [
    'A' => [
        'name' => 'WordPress membership site',
        'title' => 'WordPress course website with Lemon Squeezy checkout and ARMember',
        'description' => "I need a WordPress website to sell a single online course. Theme: JupiterX or WPBakery.\n"
            ."Homepage with videos, text, photos, testimonials and the course curriculum. FAQ page and terms page.\n"
            ."Checkout through Lemon Squeezy. Membership access with ARMember so only buyers can see the course.\n"
            ."An affiliate / partner program so partners can promote the course, with a partner program page.\n"
            ."A sign up / login page for members.\n"
            .'A members-only course page with embedded videos and a downloadable PDF.',
        'details' => ['budget_min' => 500, 'budget_max' => 1000, 'currency' => 'GBP', 'skills' => ['WordPress', 'PHP', 'Website Design'], 'bid_count' => 40, 'bid_avg' => 700],
        'expect' => 'Hook on the payment-to-access handoff; Lemon Squeezy to ARMember needs a webhook/custom code, not assumed native, with refunds and duplicate events handled; every page named (incl. partner program and login); no invented statistics; realistic delivery (not 28 days).',
    ],
    'B' => [
        'name' => 'HTML5 seven-card section',
        'title' => 'Responsive card section for an existing HTML5 site',
        'description' => "I have an existing HTML5 website. I need 7 cards (image + short text) shown either as a responsive carousel or a responsive grid.\n"
            ."Priorities: lightweight and fast loading, mobile responsive, clean HTML/CSS with minimal JavaScript, easy for me to edit later.\n"
            ."I'd like your expert recommendation: grid or carousel?\n"
            ."I need someone available now who can deliver quickly.\n"
            .'Begin your response with "I Got This" so I know you read this.',
        'details' => ['budget_min' => 30, 'budget_max' => 250, 'skills' => ['HTML', 'CSS', 'JavaScript', 'HTML5'], 'bid_count' => 25, 'bid_avg' => 90],
        'expect' => 'Starts with "I Got This"; clear grid recommendation with reasons; not absolute about carousels; short delivery; no unsupported portfolio or availability claims.',
    ],
    'C' => [
        'name' => 'Elementor editor vs live styling',
        'title' => 'Elementor page looks different on the live site',
        'description' => "My Elementor page looks correct inside the editor but the live website looks different (spacing, fonts, some sections broken).\n"
            ."I need someone to diagnose the styling differences, correct the CSS or Elementor settings, keep it responsive, test in the main browsers,\n"
            .'and send before/after screenshots with a brief explanation of what was wrong.',
        'details' => ['budget_min' => 30, 'budget_max' => 100, 'skills' => ['WordPress', 'Elementor', 'CSS'], 'bid_count' => 18, 'bid_avg' => 60],
        'expect' => 'Diagnostic hook naming plausible causes (generated CSS, caching, theme overrides) without claiming a confirmed cause; browser/mobile testing; screenshots; short delivery.',
    ],
    'D' => [
        'name' => 'WooCommerce checkout error',
        'title' => 'WooCommerce checkout shows "There was an error processing your order"',
        'description' => "Since last week some customers get \"There was an error processing your order. Please check for any charges in your payment method\" at checkout.\n"
            ."It doesn't happen every time. We use Stripe and PayPal, about 30 plugins, and a child theme of Astra. Need this fixed properly, not a reinstall.",
        'details' => ['budget_min' => 50, 'budget_max' => 150, 'skills' => ['WooCommerce', 'WordPress', 'PHP'], 'bid_count' => 12, 'bid_avg' => 110],
        'expect' => 'Diagnosis (gateway logs, plugin conflict, nonce/caching on checkout); no guarantee before logs; tests payment flow on staging; no rebuild.',
    ],
    'E' => [
        'name' => 'Shopify customization',
        'title' => 'Shopify Dawn theme: add size chart popup and change product page layout',
        'description' => "Our store uses the Dawn theme. On product pages we want a 'Size chart' link that opens a popup with a chart that can differ per product type,\n"
            ."and the description moved under the add to cart button. We'd prefer not to pay for another app if it can be done in the theme.",
        'details' => ['budget_min' => 30, 'budget_max' => 120, 'skills' => ['Shopify', 'Liquid', 'HTML', 'CSS'], 'bid_count' => 20, 'bid_avg' => 75],
        'expect' => 'Theme section/metafield approach rather than an app; Liquid/section awareness; clear deliverables; short delivery.',
    ],
    'F' => [
        'name' => 'Urgent small CSS fix',
        'title' => 'URGENT: mobile menu overlaps logo',
        'description' => 'On mobile our header menu button overlaps the logo since yesterday. Need it fixed today. WordPress site, custom theme.',
        'details' => ['budget_min' => 10, 'budget_max' => 30, 'skills' => ['CSS', 'WordPress'], 'bid_count' => 9, 'bid_avg' => 20],
        'expect' => 'Very short; specific diagnosis; competitive delivery; no long introduction.',
    ],
    'G' => [
        'name' => 'Complex project, unknown requirements',
        'title' => 'Build a customer portal for our business',
        'description' => "We need a customer portal where our clients log in and see their orders, invoices and support tickets.\n"
            .'It should connect to our existing systems. More details in chat.',
        'details' => ['budget_min' => 750, 'budget_max' => 1500, 'skills' => ['PHP', 'WordPress', 'API'], 'bid_count' => 30, 'bid_avg' => 1100],
        'expect' => 'No unrealistic commitment; names dependencies (which systems, APIs, auth); qualifies scope; one useful question.',
    ],
    'H' => [
        'name' => 'Strict submission instructions',
        'title' => 'WooCommerce product filter fix',
        'description' => "The product filter on our WooCommerce shop returns no results for some categories.\n"
            ."Instructions for bidders: Start your proposal with the words Ready Today. Include an example of similar work you have done.\n"
            .'Answer this: what would you check first? Keep your proposal under 90 words.',
        'details' => ['budget_min' => 30, 'budget_max' => 100, 'skills' => ['WooCommerce', 'WordPress'], 'bid_count' => 14, 'bid_avg' => 60],
        'expect' => 'Starts with "Ready Today"; answers what to check first; similar work only from the fact bank, no links; under 90 words.',
    ],
    'I' => [
        'name' => 'No comparable past project',
        'title' => 'Set up LearnDash with Zoom live classes',
        'description' => 'We run a WordPress site and want LearnDash courses with scheduled Zoom live classes that only enrolled students can join.',
        'details' => ['budget_min' => 100, 'budget_max' => 300, 'skills' => ['WordPress', 'LearnDash'], 'bid_count' => 10, 'bid_avg' => 220],
        'expect' => 'Names the Zoom integration and passes every check; no invented case study; insight carries it (enrolment-gated join links); general stats only if in the fact bank.',
    ],
    'J' => [
        'name' => 'Conflicting requirements',
        'title' => 'Full multi-vendor WooCommerce marketplace in 2 days, no plugins',
        'description' => "Need a complete multi-vendor marketplace on WooCommerce with vendor dashboards, commissions and payouts.\n"
            .'Must be delivered in 2 days and must not use any plugins at all. Budget is fixed.',
        'details' => ['budget_min' => 100, 'budget_max' => 200, 'skills' => ['WooCommerce', 'WordPress', 'PHP'], 'bid_count' => 22, 'bid_avg' => 160],
        'expect' => 'Names the conflict (2 days and no plugins vs a full marketplace); at USD 200 for about 160 hours: a below-target warning and a first phase only, never the full scope; promises neither.',
    ],
];
