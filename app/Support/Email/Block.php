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
    ) {}

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
        return [
            'kind'  => $this->kind->value,
            'depth' => $this->depth,
            'spans' => array_map(fn (Span $s) => $s->toArray(), $this->spans),
        ];
    }
}
