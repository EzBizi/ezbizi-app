<?php

namespace App\Support\Email;

/**
 * What a piece of a sanitised message IS, as far as drawing it goes.
 *
 * Named for the SHAPE rather than for the tag that produced it, exactly as
 * `client/lib/core/document_html.dart` names the contract document's blocks: a
 * client drawing these must never have to know that `blockquote` and `<div
 * class="gmail_quote">` both mean "the bit somebody is replying to".
 *
 * ── This list is deliberately short ──────────────────────────────
 *
 * It is not an HTML subset and it is not trying to become one. Every kind here
 * is something a client can draw with a Text widget and some padding, which is
 * the property that matters: a block structure a renderer can exhaust is one
 * that cannot carry a surprise. Tables, forms, iframes, embedded video, web
 * fonts and CSS all have no representation here at all — not stripped down to a
 * safe version, but absent, which is a much stronger statement.
 */
enum BlockKind: string
{
    case Paragraph = 'paragraph';

    /** Any of h1–h6, flattened. A message is not a document with an outline. */
    case Heading = 'heading';

    /** One line of a bulleted or numbered list. Which of the two is not kept. */
    case ListItem = 'list_item';

    /**
     * The part of the message that is quoting an earlier one.
     *
     * Worth its own kind rather than being folded into Paragraph, because a
     * client can then collapse it — which is what everybody expects of a reply
     * chain, and what stops a two-line answer arriving as forty screens.
     */
    case Quote = 'quote';

    /** Preformatted text — a code block, a log paste, an ASCII table. */
    case Preformatted = 'preformatted';

    /** A horizontal rule. Carries no spans. */
    case Rule = 'rule';

    /**
     * A picture that will be shown, fetched through EzBizi's origin.
     *
     * The original URL never goes to the client — see {@see PictureTokens}.
     * A `src` that could not be turned into a fetch stays {@see BlockedImage}.
     */
    case Image = 'image';

    /**
     * An image that could not be turned into a fetch.
     *
     * Pictures load. This is the leftover for a `src` that is not http(s), cid,
     * or a data URI of an image — so there is nothing safe to retrieve. The
     * spans carry the alt text.
     */
    case BlockedImage = 'blocked_image';

    /**
     * Something was removed and the reader is being told.
     *
     * Reserved for removals a person would otherwise NOTICE — an attachment
     * reference, an embedded form. Not used for scripts and styles, which are
     * dropped in silence: telling somebody that a `<script>` was removed from
     * their newsletter is noise about a thing they were never meant to see.
     */
    case Notice = 'notice';
}
