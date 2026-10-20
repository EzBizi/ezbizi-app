<?php

namespace App\Support\Reviews;

use App\Models\Review;
use App\Support\Ai\GrokCli;
use RuntimeException;

/**
 * Writes one reply for one review with AI (Jose, 2026-10-20: "they can't be
 * all the same"). The ideas are A1 Leads': greet by first name when there is a
 * real one, thank a stars-only review without pretending it said anything,
 * mention what the reviewer actually praised, and a style picked at random so
 * a batch does not read alike. Used by the Draft with AI button and by the
 * rule steps that draft or publish.
 */
final class ReviewReplyWriter
{
    /** One is picked per reply, so replies written together differ. */
    public const STYLES = [
        'Open with the thank-you, then mention the specific detail they gave.',
        'Lead with the specific thing they mentioned, then thank them.',
        'Keep it short and conversational, like a quick personal note.',
        'Sound genuinely happy and a little informal.',
        'Be gracious and specific; lightly echo their own words.',
        'Friendly and straightforward, no fluff.',
    ];

    /** @throws RuntimeException when AI is off or does not answer */
    public static function write(Review $review): string
    {
        if (! GrokCli::configured()) {
            throw new RuntimeException('AI drafting is not switched on.');
        }

        $answer = GrokCli::ask(self::prompt($review),
            ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false],
        );

        $reply = trim((string) preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ', ', (string) ($answer['reply'] ?? '')));
        if ($reply === '') {
            throw new RuntimeException('AI returned an empty reply.');
        }

        return mb_substr($reply, 0, 4096);
    }

    /** What the AI is asked about this review, in one of the STYLES picked at random. */
    public static function prompt(Review $review): string
    {
        $business = $review->company?->name ?? $review->franchise?->name ?? $review->organization?->name ?? 'our team';
        $stars = (int) ($review->rating ?? 0);
        $low = $stars >= 1 && $stars <= 3;
        $comment = trim((string) $review->comment);
        $first = self::firstName($review->reviewer_name);

        $rules = [
            "You write short, genuine replies to Google reviews on behalf of {$business}.",
            $first !== null
                ? "Greet the reviewer by first name, spelled exactly: \"{$first}\"."
                : 'There is no real reviewer name: open warmly without a name.',
            $comment === ''
                ? "This review has stars only and NO text. Thank them simply for the {$stars}-star rating. Do not mention their words, feedback or comment: they wrote none."
                : ($low ? 'Apologise once, calmly. Do not argue or detail fault. Invite them to call the office so it can be put right.'
                    : 'Thank them warmly for what they actually praised.'),
            $low ? '3 to 4 sentences, at most 75 words.' : '2 to 3 sentences.',
            'First person plural ("we"). Warm and human, never templated.',
            $low ? 'Do not force the business name in.' : "Work the name \"{$business}\" in naturally once, not as a sign-off.",
            'No placeholders, brackets, hashtags, emojis, em dashes or en dashes. Never invent facts, names, prices or dates.',
            'Style for this one: '.($low ? 'calm, empathetic, accountable, concise.' : self::STYLES[array_rand(self::STYLES)]),
        ];

        return implode("\n- ", $rules)
            ."\n\nReviewer: ".($review->reviewer_name ?: 'unknown')."\nStars: ".($stars ?: '?')
            ."\nReview: ".($comment !== '' ? $comment : '(no text, stars only)');
    }

    /** A real first name, capitalised, or null for blanks, initials and "A Google User". */
    public static function firstName(?string $name): ?string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');
        if (mb_strlen($first) < 2 || str_ends_with($first, '.') || in_array(mb_strtolower(trim((string) $name)), ['a google user', 'google user'], true)
            || preg_match('/^\p{L}[\p{L}\'-]*$/u', $first) !== 1) {
            return null;
        }

        return $first === mb_strtolower($first) || $first === mb_strtoupper($first) ? mb_convert_case($first, MB_CASE_TITLE) : $first;
    }
}
