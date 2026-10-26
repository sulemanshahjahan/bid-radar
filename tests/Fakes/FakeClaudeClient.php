<?php

namespace Tests\Fakes;

use App\Services\Claude\ClaudeClient;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\ClaudeRequest;
use App\Services\Claude\ClaudeResponse;
use RuntimeException;

/**
 * Returns queued replies in order and records every request, so tests can
 * inspect exactly what would have been sent to Claude. Never hits the network.
 */
class FakeClaudeClient implements ClaudeClient
{
    /** @var list<ClaudeResponse|ClaudeException> */
    private array $replies = [];

    /** @var list<ClaudeRequest> */
    public array $requests = [];

    /** @param array<string, mixed>|string $json */
    public function reply(array|string $json, string $model = 'claude-haiku-5-5', int $in = 1000, int $out = 200, ?string $stopReason = 'end_turn'): self
    {
        $this->replies[] = new ClaudeResponse(
            text: is_string($json) ? $json : json_encode($json),
            model: $model,
            stopReason: $stopReason,
            inputTokens: $in,
            outputTokens: $out,
        );

        return $this;
    }

    /** Queue an API error instead of a reply. */
    public function fail(ClaudeException $e): self
    {
        $this->replies[] = $e;

        return $this;
    }

    public function send(ClaudeRequest $request): ClaudeResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->replies) ?? throw new RuntimeException('FakeClaudeClient: no reply queued.');
        if ($next instanceof ClaudeException) {
            throw $next;
        }

        return $next;
    }

    /** Everything the model would read for request $i (system + user). */
    public function prompt(int $i): string
    {
        $r = $this->requests[$i];

        return implode("\n", array_column($r->system, 'text'))."\n".$r->userContent;
    }
}
