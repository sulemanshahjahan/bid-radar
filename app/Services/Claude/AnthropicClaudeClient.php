<?php

namespace App\Services\Claude;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;

/**
 * Talks to the Claude API through the official Anthropic PHP SDK.
 *
 * - Structured outputs (output_config.format) so replies are schema-valid JSON.
 * - Adaptive thinking is the default on the 5.5 models; depth is set via effort.
 * - No temperature (rejected since 2026-10-21) and no assistant prefill.
 * - The SDK retries 408/409/429/5xx twice with backoff.
 */
class AnthropicClaudeClient implements ClaudeClient
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(private readonly Client $client) {}

    public function send(ClaudeRequest $request): ClaudeResponse
    {
        $system = array_map(fn (array $block) => array_filter([
            'type' => 'text',
            'text' => $block['text'],
            'cacheControl' => ($block['cache'] ?? false) ? ['type' => 'ephemeral'] : null,
        ]), $request->system);

        $params = [
            'maxTokens' => $request->maxTokens,
            'model' => $request->model,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $request->userContent]],
            'outputConfig' => [
                'effort' => $request->effort,
                'format' => ['type' => 'json_schema', 'schema' => $request->schema],
            ],
        ];

        try {
            if ($request->fallbacks) {
                $message = $this->client->beta->messages->create(
                    ...$params,
                    fallbacks: 'default',
                    betas: [self::FALLBACK_BETA],
                );
            } else {
                $message = $this->client->messages->create(...$params);
            }
        } catch (APIStatusException $e) {
            throw new ClaudeException(
                'Claude API error '.$e->status.': '.($e->type?->value ?? 'unknown'),
                $e->status,
                $e,
                $e->type?->value,
                mb_substr($e->getMessage(), 0, 2000), // status + response body; never contains the API key
            );
        } catch (APIConnectionException $e) {
            throw new ClaudeException('Claude API connection failed: '.$e->getMessage(), null, $e);
        } catch (APIException $e) {
            throw new ClaudeException('Claude API error: '.$e->getMessage(), null, $e);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        $usage = $message->usage;

        return new ClaudeResponse(
            text: $text,
            model: $message->model,
            stopReason: $message->stopReason,
            inputTokens: (int) $usage->inputTokens,
            outputTokens: (int) $usage->outputTokens,
            cacheWriteTokens: (int) ($usage->cacheCreationInputTokens ?? 0),
            cacheReadTokens: (int) ($usage->cacheReadInputTokens ?? 0),
            refusalCategory: $message->stopDetails?->category ?? null,
        );
    }
}
