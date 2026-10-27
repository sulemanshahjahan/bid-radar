<?php

namespace App\Services\Drafting;

/**
 * Submission instructions read from the client's brief by code, so they can be
 * checked literally: whether the test phrase must open the bid, and a word limit.
 */
class ClientInstructions
{
    /** True when the brief asks bidders to start their bid with the phrase ("Begin your response with I Got This"). */
    public static function phraseMustOpen(string $brief, ?string $phrase): bool
    {
        $phrase = self::cleanPhrase($phrase);
        if ($phrase === '') {
            return false;
        }

        $words = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY);
        $quoted = implode('[\W_]+', array_map(fn ($w) => preg_quote($w, '/'), $words));
        $start = '\b(?:start|begin|open|lead)\w*\b[^.?!\n]{0,60}?\bwith\b|\b(?:first|opening)\s+(?:word|words|line|sentence)\b[^.?!\n]{0,40}?';

        $label = '(?:\s*(?:the|these|this)?\s*(?:exact\s+)?(?:words?|phrase|line|sentence))?';

        return (bool) preg_match('/(?:'.$start.')'.$label.'[\s"\'“‘:(\-]*'.$quoted.'/iu', $brief);
    }

    /** A word limit the client set for proposals ("under 100 words", "max 150 words"), or null. */
    public static function wordLimit(string $brief): ?int
    {
        $pattern = '/\b(?:under|below|less than|fewer than|no more than|not more than|maximum(?: of)?|max\.?|at most|up to|limit(?:ed)? to|within)\s+(\d{2,4})\s+words\b'
            .'|\b(\d{2,4})\s+words?\s+(?:max(?:imum)?|or less|or fewer|limit)\b/iu';
        if (! preg_match($pattern, $brief, $m)) {
            return null;
        }
        $limit = (int) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? 0));

        return $limit >= 20 ? $limit : null;
    }

    /** True when the proposal starts with the phrase: same words in order, any case, punctuation free. */
    public static function opensWith(string $text, ?string $phrase): bool
    {
        $words = preg_split('/[^\p{L}\p{N}\']+/u', self::cleanPhrase($phrase), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === []) {
            return true;
        }
        $pattern = '/^[^\p{L}\p{N}]*'.implode('[^\p{L}\p{N}]+', array_map(fn ($w) => preg_quote($w, '/'), $words)).'(?![\p{L}\p{N}])/iu';

        return (bool) preg_match($pattern, ltrim($text));
    }

    private static function cleanPhrase(?string $phrase): string
    {
        return trim((string) $phrase, " \t\n\r\0\x0B\"'“”‘’.,;:!");
    }
}
