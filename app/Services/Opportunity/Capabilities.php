<?php

namespace App\Services\Opportunity;

/**
 * The owner's capability areas and the words that signal them. Used to tag a project
 * (title, description, skills), each fact-bank past project and each skill ranking
 * with the same vocabulary, so they can be compared.
 *
 * Specialist areas are where the owner can show real differentiation; general areas
 * (WordPress, PHP, "fix") match most of the feed and prove little on their own.
 * Tags are a set: a brief that repeats a word ten times, or lists five CSS words,
 * still earns the tag once.
 */
final class Capabilities
{
    /** tag => [label, specialist, patterns (case-insensitive, whole words)] */
    public const TAGS = [
        'elementor' => ['Elementor', true, ['elementor']],
        'divi' => ['Divi', true, ['divi']],
        'css' => ['CSS', true, ['css3?', 'scss', 'sass', 'styling', 'stylesheet']],
        // Layout words appear in most page work (Shopify, page builders): general, not proof of CSS work.
        'responsive' => ['Responsive layout', false, ['responsive', 'layout', 'spacing', 'alignment', 'overlaps?', 'overlapping', 'breakpoints?', 'mobile view', 'front[- ]?end']],
        'html' => ['HTML5 / HTML', true, ['html5?', 'markup', '(psd|figma|xd|design) to html', 'design to code']],
        'landing_page' => ['Landing pages', true, ['landing pages?']],
        'woocommerce' => ['WooCommerce', true, ['woocommerce', 'woo commerce', 'woo']],
        'payment' => ['Payments / checkout', true, ['payment gateways?', 'payments?', 'checkout', 'stripe', 'paypal', 'razorpay', 'authorize\.net', 'shopping cart', 'cart page']],
        'shopify_theme' => ['Shopify themes', true, ['liquid', 'dawn theme', 'shopify themes?', 'shopify templates?', 'theme sections?']],
        'caching' => ['Caching', true, ['cache', 'caching', 'cached', 'litespeed', 'wp rocket', 'w3 total cache', 'cloudflare', 'redis']],
        'performance' => ['Speed / performance', true, ['page ?speed', 'site speed', 'speed up', 'slow', 'performance', 'core web vitals', 'lighthouse', 'gtmetrix', 'load(ing)? time', 'website optimi[sz]ation']],
        'security' => ['Security / malware', true, ['malware', 'hacked', 'virus', 'web security', 'website security', 'security', 'spam injection', 'blacklisted', 'firewall', 'wordfence']],
        'membership' => ['Membership sites', true, ['membership', 'memberpress', 'armember', 'members[- ]only', 'restrict content', 'paid members?']],
        'newsletter' => ['Newsletters / email templates', true, ['newsletters?', 'email templates?', 'html emails?', 'mailchimp']],
        'blog' => ['Blog design', true, ['blog design', 'blog']],
        'plugin' => ['WordPress plugins', true, ['plugins?']],
        'testing' => ['Website testing', true, ['website testing', 'cross[- ]browser', 'quality assurance', 'qa']],
        'templates' => ['Templates / themes', false, ['templates?', 'themes?']],
        'wordpress' => ['WordPress', false, ['wordpress', 'wp', 'cms', 'gutenberg', 'wpbakery']],
        'shopify' => ['Shopify', false, ['shopify']],
        'maintenance' => ['Website management', false, ['maintenance', 'website management', 'ongoing support']],
        'troubleshooting' => ['Troubleshooting', false, ['fix', 'fixes', 'fixing', 'fixed', 'bugs?', 'errors?', 'broken', 'not working', 'issues?', 'troubleshoot\w*', 'debug\w*', 'conflicts?']],
        'javascript' => ['JavaScript / jQuery', false, ['javascript', 'jquery', 'js']],
        'php' => ['PHP', false, ['php']],
    ];

    /**
     * Related areas that describe the same kind of work. A comparison credits each family once, so
     * "CSS + HTML + responsive" is one piece of front-end evidence, not three.
     */
    public const FAMILIES = [
        'frontend' => ['css', 'html', 'responsive', 'templates', 'landing_page', 'blog', 'newsletter'],
        'commerce' => ['woocommerce', 'payment', 'shopify', 'shopify_theme'],
        'speed' => ['performance', 'caching'],
        'wordpress' => ['wordpress', 'plugin', 'maintenance'],
    ];

    /** Scoring categories that say the same thing as a tag (fact-bank past projects carry a category). */
    public const CATEGORY_TAGS = [
        'fix' => 'troubleshooting',
        'security' => 'security',
        'speed' => 'performance',
        'checkout_payment' => 'payment',
        'maintenance' => 'maintenance',
    ];

    /** @return list<string> tags found in the text, in TAGS order */
    public static function tag(string $text): array
    {
        $found = [];
        foreach (self::TAGS as $tag => [, , $patterns]) {
            $regex = '/(?<![\p{L}\p{N}])(?:'.implode('|', $patterns).')(?![\p{L}\p{N}])/iu';
            if (preg_match($regex, $text)) {
                $found[] = $tag;
            }
        }

        return $found;
    }

    /** The family a tag belongs to, or the tag itself when it stands alone (Elementor, Divi, security...). */
    public static function family(string $tag): string
    {
        foreach (self::FAMILIES as $family => $tags) {
            if (in_array($tag, $tags, true)) {
                return $family;
            }
        }

        return $tag;
    }

    public static function label(string $tag): string
    {
        return self::TAGS[$tag][0] ?? $tag;
    }

    public static function isSpecialist(string $tag): bool
    {
        return self::TAGS[$tag][1] ?? false;
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function specialist(array $tags): array
    {
        return array_values(array_filter($tags, self::isSpecialist(...)));
    }
}
