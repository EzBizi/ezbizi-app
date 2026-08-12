<?php

namespace App\Support\Email;

/**
 * A run of text with one treatment — the smallest thing a client draws.
 *
 * Modelled on `DocumentSpan` in `client/lib/core/document_html.dart`, and for
 * the same reason: the client already knows how to draw text that is bold, or
 * emphasised, or a link, and does not need to learn a second vocabulary for
 * mail.
 *
 * ── The link is the interesting one ──────────────────────────────
 *
 * `href` is either a URL that survived {@see BodySanitiser}'s scheme check or
 * it is null, and null is a link that was DEFANGED rather than a run of plain
 * text: the words stay, the destination is gone, and `hrefRemoved` says which
 * of the two happened so the client can mark it. `javascript:`, `data:`,
 * `vbscript:` and `file:` never reach here, in any spelling — that is the
 * sanitiser's job, and this object has no way to express one.
 */
final class Span
{
    public function __construct(
        public readonly string $text,
        public readonly bool $strong = false,
        public readonly bool $emphasis = false,

        /**
         * Where this run points, when it points anywhere safe.
         *
         * Only `http:`, `https:` and `mailto:` ever appear here. See
         * BodySanitiser::safeHref for what that check has to survive:
         * mixed case, entity-encoded colons, leading control characters and
         * whitespace inside the scheme are all ways of writing `javascript:`
         * that a naive `str_starts_with` misses.
         */
        public readonly ?string $href = null,

        /**
         * This run WAS a link, and the destination was refused.
         *
         * Distinct from "not a link", because the two must be drawn
         * differently: text that was never a link needs no explanation, and
         * text whose destination was stripped needs to say so or the reader
         * clicks nothing and assumes the message is broken.
         */
        public readonly bool $hrefRemoved = false,
    ) {}

    public function toArray(): array
    {
        return [
            'text'         => $this->text,
            'strong'       => $this->strong,
            'emphasis'     => $this->emphasis,
            'href'         => $this->href,
            'href_removed' => $this->hrefRemoved,
        ];
    }
}
