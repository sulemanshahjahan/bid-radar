<?php

namespace App\Services\Freelancer;

use RuntimeException;

class FreelancerException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
