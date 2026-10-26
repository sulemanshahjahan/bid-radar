<?php

namespace App\Services\Claude;

/**
 * Parses a JSON reply from Claude. Structured outputs should already make it
 * valid, but we never trust that blindly: strip stray code fences, decode,
 * and fail loudly when it is not a JSON object.
 */
class JsonReply
{
    /** @return array<string, mixed> */
    public static function decode(string $text): array
    {
        $text = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) {
            $text = $m[1];
        }

        if (! str_starts_with($text, '{')) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start === false || $end === false || $end < $start) {
                throw new InvalidReplyException('Reply is not a JSON object.');
            }
            $text = substr($text, $start, $end - $start + 1);
        }

        try {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidReplyException('Reply is not valid JSON: '.$e->getMessage());
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidReplyException('Reply is not a JSON object.');
        }

        return $data;
    }
}
