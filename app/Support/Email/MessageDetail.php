<?php

namespace App\Support\Email;

use Illuminate\Support\Facades\Crypt;

/**
 * One message, opened — the header plus a body that has already been sanitised.
 *
 * ── Blocks are still the fallback; HTML is Gmail-close display ───
 *
 * `$blocks` is the reading-order structure {@see BodySanitiser} produces,
 * and it is what a non-web client draws. `$sourceHtml` is the raw HTML
 * part the provider returned — it is never published. `$html` is set only
 * after {@see HtmlEmailSanitiser} has purified it, inlined pictures, and
 * wrapped it as a document. {@see toArray()} sends `$html`, never
 * `$sourceHtml`.
 *
 * ── Attachments are listed, and fetched live on demand ───────────
 *
 * Decision 2 says bodies and attachments are never stored. The list is how a
 * reader still knows there is a file without one being written to disk here.
 * What is published per attachment is the name, the type, the size, and a
 * sealed token. The token is the message id, a newline, and the
 * attachment id, encrypted — Gmail's handle never crosses the wire.
 * Download is a GET through this origin that streams the bytes and
 * forgets them.
 */
final class MessageDetail
{
    /**
     * @param  Block[]  $blocks
     * @param  array<int, array{name: string, mime: string, bytes: int, id?: string}>  $attachments
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

        /**
         * Sanitised HTML for Gmail-close display, or null when the message
         * was only plain text. Never raw markup from the sender — see
         * {@see HtmlEmailSanitiser}. Published on the wire.
         */
        public readonly ?string $html = null,

        /**
         * The HTML part the provider returned, before purify. Never
         * published — {@see toArray()} has no key for it. The controller
         * hands this to {@see HtmlEmailSanitiser::prepare} and puts the
         * result in `$html`.
         */
        public readonly ?string $sourceHtml = null,

        /**
         * The other messages in this conversation, oldest first, when the
         * provider grouped them. Empty for a lone message (IMAP today, or a
         * Gmail mail that is the only one in its thread). Never nested.
         *
         * @var  MessageDetail[]
         */
        public readonly array $thread = [],
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
            $this->html,
            $this->sourceHtml,
            $this->thread,
        );
    }

    public function withHtml(?string $html): self
    {
        return new self(
            $this->summary,
            $this->blocks,
            $this->attachments,
            $this->seenBy,
            $this->imagesBlocked,
            $html,
            sourceHtml: null,
            thread: $this->thread,
        );
    }

    /** @param  MessageDetail[]  $thread */
    public function withThread(array $thread): self
    {
        return new self(
            $this->summary,
            $this->blocks,
            $this->attachments,
            $this->seenBy,
            $this->imagesBlocked,
            $this->html,
            $this->sourceHtml,
            $thread,
        );
    }

    public function toArray(): array
    {
        return [
            ...$this->summary->withSeenBy($this->seenBy)->toArray(),
            'blocks'      => array_map(fn (Block $b) => $b->toArray(), $this->blocks),
            'html'        => $this->html,
            'attachments' => array_map(function (array $file): array {
                $id = (string) ($file['id'] ?? '');
                $handle = $id === '' ? '' : $this->summary->id."\n".$id;

                return [
                    'name'  => $file['name'],
                    'mime'  => $file['mime'] ?? 'application/octet-stream',
                    'bytes' => $file['bytes'] ?? $file['size'] ?? 0,
                    // `{messageId}\n{attachmentId}`, sealed. Never the raw handle.
                    'token' => $handle === '' ? null : Crypt::encryptString($handle),
                ];
            }, array_values($this->attachments)),

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

            'thread' => array_map(function (self $part): array {
                $row = $part->toArray();
                unset($row['thread']);

                return $row;
            }, $this->thread),
        ];
    }
}
