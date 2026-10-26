<?php

namespace Tests\Unit;

use App\Services\Drafting\PostChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function check(array $draft, ?string $phrase = null, string $source = '', float $min = 0): array
    {
        return app(PostChecker::class)->check(array_merge($this->draftReply(), ['is_hourly' => false], $draft), $phrase, $source ?: $this->source(), $min);
    }

    private function source(): string
    {
        return 'After updating WooCommerce to 9.3 the checkout page spins forever. Budget USD 150-250. Bids 3 (average 180).';
    }

    public function test_a_good_draft_passes(): void
    {
        $this->assertSame([], $this->check([]));
    }

    public function test_word_count_out_of_range_fails(): void
    {
        $failures = $this->check(['proposal_text' => 'Too short to be a proposal.']);

        $this->assertStringContainsString('Word count', $failures[0]);
    }

    public function test_banned_phrase_fails(): void
    {
        $text = 'As an experienced developer, '.$this->draftReply()['proposal_text'];

        $this->assertContains('Contains banned phrase "As an experienced".', $this->check(['proposal_text' => $text]));
    }

    public function test_missing_test_phrase_fails_and_present_phrase_passes(): void
    {
        $this->assertContains("Missing the client's test phrase \"PURPLE\".", $this->check([], 'PURPLE'));

        $text = 'PURPLE. '.$this->draftReply()['proposal_text'];
        $this->assertSame([], $this->check(['proposal_text' => $text], 'PURPLE'));
    }

    public static function contactCases(): array
    {
        return [
            'email' => ['Reach me at dev.person@gmail.com for details.', 'email address'],
            'url' => ['See my work at https://example.com/portfolio today.', 'link'],
            'bare domain' => ['Check mysite.com for samples.', 'link'],
            'phone' => ['Call +1 (555) 123-4567 any time.', 'phone number'],
            'handle' => ['Ping me @dev_person anytime.', 'chat handle'],
            'whatsapp' => ['Message me on WhatsApp so we can talk faster.', 'off-platform'],
            'telegram' => ['I am on Telegram as well.', 'off-platform'],
            'skype' => ['Add me on Skype please.', 'off-platform'],
            'off platform' => ['We can continue outside Freelancer to save fees.', 'off-platform'],
        ];
    }

    #[DataProvider('contactCases')]
    public function test_contact_info_and_off_platform_requests_fail(string $sentence, string $expected): void
    {
        $failures = PostChecker::contactViolations($sentence);

        $this->assertNotEmpty($failures, $sentence);
        $this->assertStringContainsString($expected, implode(' ', $failures));
    }

    public function test_normal_tech_words_are_not_flagged_as_contact_info(): void
    {
        $this->assertSame([], PostChecker::contactViolations(
            'I will fix the WooCommerce checkout, update Node.js tooling and check the PHP 8.2 error log on staging.'
        ));
    }

    public function test_forbidden_company_name_fails(): void
    {
        $text = $this->draftReply()['proposal_text'].' I did this for Pamlico.';

        $this->assertContains('Mentions a forbidden company name.', $this->check(['proposal_text' => $text]));
    }

    public function test_exclamation_marks_and_emojis_fail(): void
    {
        $base = $this->draftReply()['proposal_text'];

        $this->assertContains('Contains an exclamation mark.', $this->check(['proposal_text' => $base.' Great!']));
        $this->assertContains('Contains an emoji.', $this->check(['proposal_text' => $base." \u{1F680}"]));
    }

    public function test_numbers_must_come_from_fact_bank_or_brief(): void
    {
        $text = 'I have fixed 500 checkouts. '.$this->draftReply()['proposal_text'];

        $failures = $this->check(['proposal_text' => $text]);

        $this->assertStringContainsString('500', implode(' ', $failures));
    }

    public function test_numbers_from_the_brief_bid_and_delivery_are_allowed(): void
    {
        $text = $this->draftReply()['proposal_text'].' I can finish in 2 days for 171 and test on 9.3.';

        $this->assertSame([], PostChecker::unknownNumbers($text, $this->source(), $this->draftReply()));
    }

    public function test_milestones_must_add_up_and_be_two_to_four(): void
    {
        $failures = $this->check(['milestones' => [['title' => 'All', 'amount' => 100]]]);

        $this->assertContains('Has 1 milestones; needs 2-4.', $failures);
        $this->assertContains('Milestones add up to 100, not the bid of 171.', $failures);
    }

    public function test_bid_below_minimum_fails(): void
    {
        $this->assertContains('Bid is below the minimum allowed (200).', $this->check([], null, '', 200));
    }
}
