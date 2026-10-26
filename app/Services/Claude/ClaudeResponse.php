<?php

namespace App\Services\Claude;

final class ClaudeResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly ?string $stopReason,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly ?string $refusalCategory = null,
    ) {}

    public function refused(): bool
    {
        return $this->stopReason === 'refusal';
    }

    public function truncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
