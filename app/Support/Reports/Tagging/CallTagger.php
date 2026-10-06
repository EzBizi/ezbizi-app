<?php

namespace App\Support\Reports\Tagging;

/**
 * Reads one call's transcript and answers with one label from a fixed list.
 *
 * Two implementations, Claude and Grok, because Jose wants both running on the
 * same calls to compare (2026-10-06: "build for both, so i can try both and
 * see which one i like more"). A tagger never invents a label: anything
 * outside the list comes back as null.
 */
interface CallTagger
{
    /** The provider's name as stored: 'claude' or 'grok'. */
    public function name(): string;

    /** Whether a key is configured, so the command can skip one that is not. */
    public function configured(): bool;

    /**
     * @param  array<string, string>  $labels  label => what it means
     * @return string|null one of the label keys, or null when it gave none
     */
    public function tag(string $transcript, string $question, array $labels): ?string;
}
