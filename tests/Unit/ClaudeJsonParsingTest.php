<?php

namespace Tests\Unit;

use App\Services\Claude\InvalidReplyException;
use App\Services\Claude\JsonReply;
use App\Services\Drafting\Drafter;
use App\Services\Scoring\Scorer;
use Tests\TestCase;

class ClaudeJsonParsingTest extends TestCase
{
    public function test_plain_json_object_decodes(): void
    {
        $this->assertSame(['a' => 1], JsonReply::decode('{"a":1}'));
    }

    public function test_code_fences_and_surrounding_text_are_tolerated(): void
    {
        $this->assertSame(['a' => 1], JsonReply::decode("```json\n{\"a\":1}\n```"));
        $this->assertSame(['a' => 1], JsonReply::decode("Here you go:\n{\"a\":1}\nThanks"));
    }

    public function test_invalid_json_throws(): void
    {
        $this->expectException(InvalidReplyException::class);
        JsonReply::decode('{"a":1');
    }

    public function test_json_list_is_rejected(): void
    {
        $this->expectException(InvalidReplyException::class);
        JsonReply::decode('[1,2]');
    }

    public function test_scoring_reply_is_clamped_and_cleaned(): void
    {
        $s = Scorer::normalize([
            'fit_score' => 140, 'category' => 'unknown', 'reason' => ' ok ',
            'client_test_phrase' => 'null', 'client_questions' => ['Q1', '', ['x']], 'red_flags' => 'not a list',
        ]);

        $this->assertSame(100, $s['fit_score']);
        $this->assertSame('other', $s['category']);
        $this->assertSame('ok', $s['reason']);
        $this->assertNull($s['client_test_phrase']);
        $this->assertSame(['Q1'], $s['client_questions']);
        $this->assertSame([], $s['red_flags']);
    }

    public function test_scoring_reply_without_score_throws(): void
    {
        $this->expectException(InvalidReplyException::class);
        Scorer::normalize(['category' => 'fix']);
    }

    public function test_draft_reply_takes_price_from_code_not_the_model(): void
    {
        $price = ['amount' => 171.0, 'currency' => 'USD', 'is_hourly' => false, 'minimum' => 150.0, 'reason' => 'Near average'];

        $d = Drafter::normalize($this->draftReply(['bid_amount' => 5, 'currency' => 'EUR']), $price);

        $this->assertSame(171.0, $d['bid_amount']);
        $this->assertSame('USD', $d['currency']);
        $this->assertSame(171.0, array_sum(array_column($d['milestones'], 'amount')));
    }

    public function test_draft_reply_without_text_throws(): void
    {
        $this->expectException(InvalidReplyException::class);
        Drafter::normalize(['proposal_text' => '  '], ['amount' => 1.0, 'currency' => 'USD', 'is_hourly' => false, 'minimum' => 0.0, 'reason' => '']);
    }
}
