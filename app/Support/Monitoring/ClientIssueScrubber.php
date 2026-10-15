<?php

namespace App\Support\Monitoring;

/**
 * The privacy line for client error reports: what code failed where, never
 * what anybody typed or held. Applied on the server so an app that forgets to
 * scrub (or an old build) still cannot store a token or an address.
 *
 * ponytail: patterns, not a parser. They err towards over-redacting; an error
 * that loses a number to "[number]" is still findable by its stack.
 */
final class ClientIssueScrubber
{
    public static function scrub(?string $text, int $max): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $text = preg_replace([
            '/\b(?:bearer|token|authorization|password|secret|code)\s*[:=]\s*\S+/i', // credentials in "key: value"
            '/[?#][^\s\'"]*/',                              // query strings and fragments carry ids and tokens
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', // email addresses
            '/\b[A-Za-z0-9_\-]{32,}\b/',                     // long opaque strings: tokens, keys, signatures
            '/\+?\d[\d\s().\-]{6,}\d/',                      // phone numbers and other long digit runs
        ], ['[redacted]', '', '[email]', '[redacted]', '[number]'], $text) ?? '';

        return mb_substr($text, 0, $max);
    }

    /**
     * What makes two reports "the same problem": the kind, the first line of
     * the message with numbers removed, and the first few frames of the stack.
     */
    public static function fingerprint(string $kind, string $message, ?string $detail): string
    {
        $head = preg_replace('/\d+/', '#', strtok($message, "\n") ?: '');
        $frames = implode("\n", array_slice(preg_split('/\R/', (string) $detail) ?: [], 0, 4));
        $frames = preg_replace('/:\d+(:\d+)?/', '', $frames); // line numbers shift between builds

        return sha1($kind."\n".$head."\n".$frames);
    }
}
