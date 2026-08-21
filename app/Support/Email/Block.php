<?php

namespace App\Support\Email;

/**
 * One paragraph, heading, quoted line or notice of a sanitised message.
 *
 * A block is a KIND and a list of {@see Span}s, and that is the whole grammar.
 * There is no nesting, no attributes and no styling: a structure with no
 * recursion in it is one a client can draw with a loop, and one an attacker
 * cannot hide anything inside.
 */
final class Block
{
    /**
     * @param  Span[]  $spans
     */
    public function __construct(
        public readonly BlockKind $kind,
        public readonly array $spans = [],

        /**
         * How deeply quoted this line is — 0 for the message itself.
         *
         * Kept because a reply chain has layers and a client that draws them all
         * at one indent turns a conversation into a wall. Capped by the
         * sanitiser, so a message with three hundred nested blockquotes cannot
         * become three hundred levels of indent on somebody's phone.
         */
        public readonly int $depth = 0,

        /**
         * A picture's source, on {@see BlockKind::Image} only.
         *
         * Before {@see PictureTokens::seal} this is the original http(s)/cid
         * URL or a `data:` URI. After seal, http(s) and cid are an encrypted
         * token. Raw http(s)/cid are omitted from {@see toArray} so a forgotten
         * seal cannot publish a sender-chosen URL.
         */
        public readonly ?string $src = null,
    ) {}

    public function withSrc(string $src): self
    {
        return new self($this->kind, $this->spans, $this->depth, $src);
    }

    public static function paragraph(array $spans, int $depth = 0): self
    {
        return new self(BlockKind::Paragraph, $spans, $depth);
    }

    /**
     * A block of one plain run.
     *
     * Named `of` rather than `text` because `text()` below is the instance
     * method that reads a block's words back out, and PHP has one namespace for
     * both — a static factory and an accessor cannot share a name, and the two
     * meanings would be confusing at the call site even if they could.
     */
    public static function of(BlockKind $kind, string $text, int $depth = 0): self
    {
        return new self($kind, [new Span($text)], $depth);
    }

    /** Every span's text, joined — what a search or a snippet reads. */
    public function text(): string
    {
        return implode('', array_map(fn (Span $s) => $s->text, $this->spans));
    }

    public function toArray(): array
    {
        $row = [
            'kind'  => $this->kind->value,
            'depth' => $this->depth,
            'spans' => array_map(fn (Span $s) => $s->toArray(), $this->spans),
        ];

        if ($this->kind === BlockKind::Image && $this->src !== null && $this->publishableSrc()) {
            $row['src'] = $this->src;
        }

        return $row;
    }

    /** Raw sender URLs stay off the wire until they have been sealed. */
    private function publishableSrc(): bool
    {
        $src = strtolower($this->src ?? '');

        return str_starts_with($src, 'data:')
            || (! str_contains($src, '://') && ! str_starts_with($src, 'cid:'));
    }
}
