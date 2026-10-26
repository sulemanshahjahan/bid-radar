<?php

namespace App\Services\Claude;

use RuntimeException;

class ClaudeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
        public readonly ?string $errorType = null,
        public readonly string $detail = '',
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Out of credit / billing problem: retrying will not help until credit is added. */
    public function isBilling(): bool
    {
        return $this->errorType === 'billing_error'
            || $this->httpStatus === 402
            || preg_match('/credit balance|billing|insufficient (?:credit|funds)|purchase credits/i', $this->detail) === 1;
    }
}
