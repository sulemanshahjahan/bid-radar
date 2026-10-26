<?php

namespace App\Services\Claude;

/**
 * One structured-output request. System blocks are sent in order; blocks
 * flagged `cache` get a cache_control breakpoint (fixed prompt parts).
 */
final class ClaudeRequest
{
    /**
     * @param  list<array{text: string, cache?: bool}>  $system
     * @param  array<string, mixed>  $schema  JSON schema the reply must match
     */
    public function __construct(
        public readonly string $model,
        public readonly array $system,
        public readonly string $userContent,
        public readonly array $schema,
        public readonly string $effort = 'medium',
        public readonly int $maxTokens = 8000,
        public readonly bool $fallbacks = false,
    ) {}
}
