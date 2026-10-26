<?php

namespace App\Services\Claude;

use RuntimeException;

class ClaudeException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
