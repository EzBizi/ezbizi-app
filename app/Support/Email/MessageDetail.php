<?php

namespace App\Support\Email;

/**
 * One message, opened — the header plus a body that has already been sanitised.
 *
 * ── The body is blocks, not markup, all the way in ───────────────
 *
 * The type of `$blocks` is what enforces the decision rather than a rule
 * somebody has to remember: there is no `string $html` on this object, so a
 * reader implementation cannot hand raw markup up to a controller "just for
 * now". A provider adapter fetches whatever the provider gives it, runs it
 * through {@see BodySanitiser}, and constructs this. Whatever markup arrived
 * stops there.
 *
 * ── Attachments are LISTED and not fetched ───────────────────────
 *
 * Decision 2 says bodies and attachments are never stored, and the list is how a
 * reader still knows there is a file without one being written to disk here.
 * What is published per attachment is the name, the type the provider claims and
 * the size — enough to say "there is a 2 MB PDF called invoice.pdf". Downloading
 * it is a separate act against the provider, and it is not built: doing it
 * safely means streaming somebody else's file through our origin with the right
 * headers, and that is a decision of its own rather than a line of code.
 */
final class MessageDetail
{
    /**
     * @param  Block[]  $blocks
     * @param  array<int, array{name: string, mime: string, bytes: int}>  $attachments
     * @param  string[]  $seenBy
     */
    public function __construct(
        public readonly MessageSummary $summary,
        public readonly array $blocks,
        public readonly array $attachments = [],
        public readonly array $seenBy = [],

        /**
         * True when at least one image was refused.
         *
         * Carried as a fact about the MESSAGE as well as being visible per
         * block, so the client can put one honest line at the top — "Images in
         * this message were not loaded" — instead of repeating itself beside
         * every logo in a newsletter signature.
         */
        public readonly bool $imagesBlocked = false,
    ) {}

    /** @param  Block[]  $blocks */
    public function withBlocks(array $blocks): self
    {
        return new self(
            $this->summary,
            $blocks,
            $this->attachments,
            $this->seenBy,
            $this->imagesBlocked,
        );
    }

    public function toArray(): array
    {
        return [
            ...$this->summary->withSeenBy($this->seenBy)->toArray(),
            'blocks'      => array_map(fn (Block $b) => $b->toArray(), $this->blocks),
            'attachments' => array_values($this->attachments),

            'images_blocked' => $this->imagesBlocked,

            /*
             * Said on screen, in the response, rather than left to the client to
             * word. The reason belongs with the fact: somebody who sees pictures
             * missing and no explanation assumes a fault, and somebody who is
             * told only "images blocked" assumes a setting they can turn off.
             */
            'images_blocked_reason' => $this->imagesBlocked
                ? 'A picture in this message could not be shown. The rest of the message is here.'
                : null,
        ];
    }
}
