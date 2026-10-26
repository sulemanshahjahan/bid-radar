<?php

namespace App\Services\Claude;

interface ClaudeClient
{
    /** @throws ClaudeException on transport/API errors */
    public function send(ClaudeRequest $request): ClaudeResponse;
}
