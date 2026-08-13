<?php

namespace App\Support\Email;

/**
 * What one poll of one shared inbox actually did.
 *
 * Seven outcomes rather than a boolean, because the difference between them is
 * the whole diagnosis. "The watcher ran and announced nothing" is true of a
 * quiet mailbox, of a mailbox seen for the first time, of one whose position
 * had gone stale, of one somebody else was already polling, and of one that is
 * broken — and only two of those are fine. A sweep that printed "0" for all
 * five would be a log nobody could debug from, which is exactly the state
 * requirement 6 of this feature exists to prevent.
 */
final class PollResult
{
    /** First sight: a position was taken and NOTHING was announced. */
    public const MARKED = 'marked';

    /** The stored position was unusable. A new one was taken; a gap was skipped. */
    public const REMARKED = 're-marked';

    /** Mail arrived and was announced. */
    public const ANNOUNCED = 'announced';

    /** Polled, and nothing had arrived. The ordinary case. */
    public const QUIET = 'quiet';

    /** Not dialled at all — broken, unconnected, or a provider this box cannot use. */
    public const SKIPPED = 'skipped';

    /** Another sweep held the claim. Nothing was dialled and nothing is owed. */
    public const BUSY = 'busy';

    /** The mail service could not be reached or refused. */
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $verdict,
        public readonly int $announced,
        public readonly string $note,
    ) {}

    public static function marked(): self
    {
        return new self(self::MARKED, 0, 'first sight of this inbox — nothing announced');
    }

    public static function remarked(string $why): self
    {
        return new self(self::REMARKED, 0, $why);
    }

    public static function announced(int $count): self
    {
        return new self(
            $count === 0 ? self::QUIET : self::ANNOUNCED,
            $count,
            $count === 0 ? 'nothing new' : "{$count} new",
        );
    }

    public static function skipped(string $why): self
    {
        return new self(self::SKIPPED, 0, $why);
    }

    public static function busy(): self
    {
        return new self(self::BUSY, 0, 'another sweep is on it');
    }

    public static function failed(string $why): self
    {
        return new self(self::FAILED, 0, $why);
    }
}
