<?php

namespace App\Support\Email;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * A message body becomes a SAFE BLOCK STRUCTURE. No HTML ever reaches the client.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THE DECISION, AND WHY IT IS NOT NEGOTIABLE
 *
 *  Rendering arbitrary markup from strangers inside our own origin is
 *  the worst cross-site-scripting surface this application could
 *  have, and it is worse than every other one in a way worth stating
 *  precisely: everywhere else the hostile input is a CUSTOMER'S TYPED
 *  TEXT — a job note, a contract clause — written by somebody who
 *  already has an account and can be identified afterwards. Here it
 *  is whatever anybody in the world chose to email info@. There is no
 *  account, no audit trail, and no cost to trying again tomorrow with
 *  a different payload.
 *
 *  What an XSS in this module would reach: the session of an employee
 *  who can read every shared inbox, in an app that also holds the
 *  organization's customers, jobs, contracts and payroll onboarding.
 *  A single `<img onerror>` in a message body is a full account
 *  takeover triggered by nothing more than opening an email.
 *
 *  So the server produces {@see Block}s and the client draws blocks.
 *  This is exactly what the application already does for contract
 *  documents — App\Support\Contracts\DocumentRenderer produces the
 *  document and client/lib/core/document_html.dart reads it into
 *  blocks the page draws — and the vocabulary here is deliberately
 *  the same one, so the client is doing a thing it already knows how
 *  to do.
 *
 *  ── Why a block structure beats an HTML allowlist ─────────
 *
 *  The usual answer is "sanitise the HTML and send safe HTML". That
 *  is a much weaker promise, and the difference is structural rather
 *  than a matter of care: an allowlist ships HTML, so the client
 *  still has to PARSE and RENDER attacker-influenced markup, and
 *  every mutation-XSS bug ever found lives in the gap between what
 *  the sanitiser's parser thought the markup meant and what the
 *  browser's parser thinks it means. Those two parsers are not the
 *  same program and never will be.
 *
 *  A block structure closes that gap by not having one. What crosses
 *  the wire is a list of {kind, depth, spans}, and a span is text
 *  plus three booleans and a checked href. There is no markup on the
 *  wire for a second parser to disagree about. An element this file
 *  has never heard of cannot survive as an unknown tag, because there
 *  is nowhere in the output to put a tag.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Remote images are never fetched, for two reasons ─────────────
 *
 * The first is the reader's: a remote image in an email is a TRACKING PIXEL.
 * Fetching it tells the sender that this message was opened, at what time, from
 * what address — and for a shared inbox that is worse than for a personal one,
 * because it also reveals the business's working hours and its office IP to
 * anybody who mails it, including whoever is deciding whether the address is
 * worth spamming.
 *
 * The second is ours, and it is the same threat {@see RemoteHostGuard} exists
 * for: an `<img src>` is an outbound request to a host the message's AUTHOR
 * chose. `<img src="http://169.254.169.254/latest/meta-data/iam/security-
 * credentials/">` in a message body is an attempt to make our server fetch its
 * own cloud credentials. It does not matter that an image request would not
 * usually leak the response — a server that can be made to issue requests from
 * inside its own network is the whole of SSRF, and the answer is not to fetch.
 *
 * So an image becomes {@see BlockKind::BlockedImage} carrying its alt text, and
 * the client says on screen that an image was not loaded. Saying so is part of
 * the decision rather than a nicety: a message that quietly loses its pictures
 * looks broken, and somebody will go looking for the bug.
 *
 * ── What is dropped, whole ───────────────────────────────────────
 *
 * Everything in {@see DROPPED_WHOLE} goes with its contents. `<script>` is the
 * obvious one; `<style>` is there because CSS is code — `expression()`,
 * `url(javascript:…)`, `@import`, and `background:url(http://tracker/)` is the
 * tracking pixel again wearing a different hat. There is no CSS anywhere in the
 * output, from any source, so none of that has anywhere to land.
 *
 * `<svg>` and `<math>` are dropped for a reason that is easy to miss: both are
 * FOREIGN CONTENT to an HTML parser, with their own parsing rules, and both can
 * carry script — `<svg><script>`, `<svg><animate onbegin>`, `<math><maction
 * xlink:href="javascript:...">`. They are the classic mutation-XSS vector, and
 * they are the reason "we strip script tags" is not a sanitiser.
 *
 * `<form>`, `<input>` and `<button>` are dropped because a form rendered inside
 * the application's own chrome is a phishing page wearing our logo, and the
 * reader has every reason to trust it.
 *
 * ── Event handlers and styles are not stripped. They are unread ──
 *
 * There is no `onerror` removal step in this file, and looking for one is the
 * wrong mental model. This walker reads exactly two attributes in the entire
 * document — `href` on a link and `alt` on an image — and constructs output from
 * text and a small enum. `onerror`, `onload`, `style`, `srcset`, `formaction`
 * and every attribute nobody has invented yet are not stripped, blocked or
 * filtered: they are never looked at, and there is no field in {@see Span} or
 * {@see Block} that could carry one if they were. That is a stronger guarantee
 * than a blocklist, because it does not have to be kept up to date.
 *
 * ── The one attribute that IS read carefully ─────────────────────
 *
 * See {@see safeHref}. `javascript:` has more spellings than anybody expects.
 */
final class BodySanitiser
{
    /**
     * Dropped with everything inside them.
     *
     * Contrast the default for an UNKNOWN element, which is to descend and keep
     * the text: losing a sentence because it arrived wrapped in something this
     * file has not met would be the one failure a mail reader must not have.
     * These are the elements whose CONTENT is not prose.
     */
    private const DROPPED_WHOLE = [
        // Code, in the three languages a browser will run.
        'script', 'style', 'noscript',

        // Foreign content — separate parsing rules, and both can carry script.
        'svg', 'math',

        // Other documents, loaded from somewhere the author chose.
        'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'portal',

        // Things that fetch. Every one of them is an outbound request to a host
        // named in the message, which is the SSRF half of the image argument.
        'link', 'audio', 'video', 'source', 'track', 'picture', 'canvas',

        // A form inside our own chrome is a phishing page with our logo on it.
        'form', 'input', 'button', 'select', 'option', 'optgroup', 'textarea',
        'label', 'fieldset', 'legend', 'datalist', 'output', 'progress', 'meter',

        // Document plumbing that has no business in a message body.
        'meta', 'base', 'title', 'head', 'template', 'slot', 'dialog',
        'map', 'area', 'marquee', 'xml',
    ];

    /** Anything here starts a new block, and its content becomes that block. */
    private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * Elements after which the current run of text is finished.
     *
     * Table cells are in the list because HTML email is laid out in tables — a
     * newsletter is typically a `<table>` per row of the design — and treating a
     * cell as a block turns that layout into a readable sequence rather than one
     * enormous run-on paragraph.
     */
    private const BLOCK_BOUNDARIES = [
        'p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside',
        'nav', 'address', 'figure', 'figcaption', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'col',
        'colgroup', 'ul', 'ol', 'center', 'details', 'summary', 'hgroup',
    ];

    /**
     * The only URL schemes that survive as a destination.
     *
     * `mailto:` is in the list because a mail reader without clickable addresses
     * is missing the point. `tel:` is deliberately out: it is harmless, and this
     * list is the one place where "harmless, so why not" is the wrong instinct —
     * it grows a scheme at a time until somebody adds one that is a handler on
     * somebody's desktop.
     */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Caps, so a hostile message cannot exhaust the box or the client.
     *
     * A mail body is attacker-controlled in SIZE as well as in content. Each of
     * these is the point past which more input stops producing more meaning:
     *
     *   INPUT  — half a megabyte of HTML is an enormous newsletter. Past it the
     *            body is truncated and the reader is TOLD, because silently
     *            showing three quarters of a message is worse than showing all
     *            of it or none.
     *   BLOCKS — two thousand paragraphs is a very long message; a hundred
     *            thousand empty `<p>` is a denial of service against whatever
     *            has to draw them.
     *   DEPTH  — a reply chain six deep is already unusual. `<blockquote>` a
     *            thousand deep is a stack overflow in a recursive walker and an
     *            unreadable indent in a client, and this is what makes the walk
     *            bounded rather than merely usually-shallow.
     */
    public const MAX_INPUT_BYTES = 524288;

    public const MAX_BLOCKS = 2000;

    public const MAX_DEPTH = 6;

    public const MAX_SPANS_PER_BLOCK = 400;

    /** A URL longer than this is not a destination anybody typed. */
    public const MAX_HREF_LENGTH = 2048;

    /** @var Block[] */
    private array $blocks = [];

    /** @var Span[] the run being accumulated, flushed at every block boundary */
    private array $pending = [];

    private bool $truncated = false;

    /**
     * The whole job: hostile markup in, drawable blocks out.
     *
     * @return Block[]
     */
    public function sanitise(string $html): array
    {
        $this->blocks    = [];
        $this->pending   = [];
        $this->truncated = false;

        if (strlen($html) > self::MAX_INPUT_BYTES) {
            $html            = substr($html, 0, self::MAX_INPUT_BYTES);
            $this->truncated = true;
        }

        $document = $this->parse($html);

        if ($document !== null) {
            $body = $document->getElementsByTagName('body')->item(0);

            $this->walk($body ?? $document, 0, strong: false, emphasis: false, href: null, hrefRemoved: false);
        }

        $this->flush(0);

        if ($this->truncated) {
            $this->blocks[] = Block::of(
                BlockKind::Notice,
                'This message was too long to show in full, so the end of it is not here. '
                .'Open it in your own mail program to read the rest.',
            );
        }

        return $this->blocks;
    }

    /**
     * The same job for a plain-text body, which is most business mail.
     *
     * Not routed through the HTML path, and that is the safe direction rather
     * than a shortcut: turning text into HTML so it can be parsed back would
     * mean a message containing the characters `<script>` — a perfectly ordinary
     * thing for a developer to email a support address — becoming a script tag
     * that then has to be removed again. Text is text, and the only thing read
     * out of it is where the lines break.
     *
     * `>` quoting is honoured because that is what plain-text mail means by a
     * quote, and it is the same {@see BlockKind::Quote} the HTML path produces —
     * so a client draws a reply chain the same way whichever format it arrived
     * in.
     *
     * @return Block[]
     */
    public function sanitiseText(string $text): array
    {
        $this->blocks    = [];
        $this->pending   = [];
        $this->truncated = false;

        if (strlen($text) > self::MAX_INPUT_BYTES) {
            $text            = substr($text, 0, self::MAX_INPUT_BYTES);
            $this->truncated = true;
        }

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (count($this->blocks) >= self::MAX_BLOCKS) {
                $this->truncated = true;
                break;
            }

            $depth = 0;

            while ($depth < self::MAX_DEPTH && preg_match('/^\s*>\s?/', $line)) {
                $line = (string) preg_replace('/^\s*>\s?/', '', $line, 1);
                $depth++;
            }

            $line = $this->clean($line);

            if (trim($line) === '') {
                continue;
            }

            $this->blocks[] = Block::of(
                $depth > 0 ? BlockKind::Quote : BlockKind::Paragraph,
                $line,
                $depth,
            );
        }

        if ($this->truncated) {
            $this->blocks[] = Block::of(
                BlockKind::Notice,
                'This message was too long to show in full, so the end of it is not here. '
                .'Open it in your own mail program to read the rest.',
            );
        }

        return $this->blocks;
    }

    // ── parsing ──────────────────────────────────────────────────

    /**
     * Markup into a tree, with the parser's own dangerous features off.
     *
     * ── The doctype is cut off first, and that is an XXE guard ───
     *
     * `<!DOCTYPE x [<!ENTITY p SYSTEM "file:///etc/passwd">]>` is a document
     * type declaration carrying an external entity, and a parser that expands it
     * reads that file and puts the contents in the output — which here would
     * mean emailing a support address and getting the server's own files back in
     * the rendered message. Two things stop it: LIBXML_NOENT is NOT passed, so
     * entities are not substituted, and the declaration is removed before the
     * parser ever sees it. Both, because the first is a default somebody could
     * change while adding an unrelated flag, and this is not a failure anybody
     * would notice from the outside.
     *
     * LIBXML_NONET is passed for the same belt-and-braces reason: with entities
     * unsubstituted nothing should be fetched, and a parser that cannot open a
     * socket at all cannot be argued with.
     */
    private function parse(string $html): ?DOMDocument
    {
        // Also removes processing instructions, which some parsers treat as the
        // start of a tag and this one has no use for either way.
        $html = (string) preg_replace('/<!DOCTYPE[^>]*(\[[^\]]*\])?[^>]*>/is', '', $html);
        $html = (string) preg_replace('/<\?[^>]*\?>/s', '', $html);

        if (trim($html) === '') {
            return null;
        }

        $document = new DOMDocument;

        /*
         * The meta is prepended rather than relying on detection, because
         * libxml guesses the encoding from the bytes when nothing says — and a
         * message whose charset it guesses wrong is one whose accented
         * characters come out as mojibake, which is a bug report rather than a
         * security hole but a very common one.
         */
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    // ── the walk ─────────────────────────────────────────────────

    /**
     * One node, and everything under it.
     *
     * The formatting state — bold, emphasis, the enclosing link — is carried
     * DOWN as arguments rather than kept on the object, because a message can
     * close its tags in any order it likes (or not at all, and the parser will
     * close them for it) and a stack this walker maintained itself would get out
     * of step with the tree it is walking. Passing state down means the state at
     * any node is exactly the path from the root to it.
     */
    private function walk(
        DOMNode $node,
        int $depth,
        bool $strong,
        bool $emphasis,
        ?string $href,
        bool $hrefRemoved,
    ): void {
        if (count($this->blocks) >= self::MAX_BLOCKS) {
            $this->truncated = true;

            return;
        }

        if ($node instanceof DOMText) {
            $this->addText($node->textContent, $strong, $emphasis, $href, $hrefRemoved);

            return;
        }

        if (! $node instanceof DOMElement) {
            // A comment, a CDATA section, the document itself. Comments carry no
            // text anybody meant to send, and conditional comments — the old
            // `<!--[if IE]><script>` trick — are exactly why they are not read.
            if ($node->hasChildNodes() && $node->nodeType === XML_DOCUMENT_NODE) {
                $this->children($node, $depth, $strong, $emphasis, $href, $hrefRemoved);
            }

            return;
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::DROPPED_WHOLE, strict: true)) {
            return;     // and its contents with it
        }

        switch (true) {
            case $tag === 'br':
                // A line break ends the run. Two `<br>` in a row therefore
                // produce one paragraph break rather than an empty block, which
                // is what flush() declining to write an empty block buys.
                $this->flush($depth);

                return;

            case $tag === 'hr':
                $this->flush($depth);
                $this->push(new Block(BlockKind::Rule, [], $depth));

                return;

            case $tag === 'img':
                $this->flush($depth);
                $this->push(new Block(
                    BlockKind::BlockedImage,
                    [new Span($this->clean($node->getAttribute('alt')))],
                    $depth,
                ));

                return;

            case in_array($tag, self::HEADINGS, strict: true):
                $this->flush($depth);
                $this->children($node, $depth, $strong, $emphasis, $href, $hrefRemoved);
                $this->flushAs(BlockKind::Heading, $depth);

                return;

            case $tag === 'li':
                $this->flush($depth);
                $this->children($node, $depth, $strong, $emphasis, $href, $hrefRemoved);
                $this->flushAs(BlockKind::ListItem, $depth);

                return;

            case $tag === 'pre':
                $this->flush($depth);
                $this->push(new Block(
                    BlockKind::Preformatted,
                    [new Span(rtrim($this->collapseNothing($node->textContent)))],
                    $depth,
                ));

                return;

            case $tag === 'blockquote':
                $this->flush($depth);

                /*
                 * Capped rather than refused. A message nested past the cap is
                 * almost always a long reply chain rather than an attack, and
                 * the right outcome is to draw the deep part at the deepest
                 * indent this understands — not to lose it.
                 */
                $inner = min($depth + 1, self::MAX_DEPTH);

                $this->children($node, $inner, $strong, $emphasis, $href, $hrefRemoved);
                $this->flushAs(BlockKind::Quote, $inner);

                return;

            case $tag === 'a':
                $checked = $this->safeHref($node->getAttribute('href'));

                $this->children(
                    $node,
                    $depth,
                    $strong,
                    $emphasis,
                    $checked,
                    // A link whose destination was refused is marked so the
                    // client can say so. A link with no href at all was never a
                    // destination and needs no explanation.
                    hrefRemoved: $checked === null && trim($node->getAttribute('href')) !== '',
                );

                return;

            case in_array($tag, ['b', 'strong'], strict: true):
                $this->children($node, $depth, true, $emphasis, $href, $hrefRemoved);

                return;

            case in_array($tag, ['i', 'em', 'cite', 'var', 'dfn'], strict: true):
                $this->children($node, $depth, $strong, true, $href, $hrefRemoved);

                return;

            case in_array($tag, self::BLOCK_BOUNDARIES, strict: true):
                $this->flush($depth);
                $this->children($node, $depth, $strong, $emphasis, $href, $hrefRemoved);
                $this->flush($depth);

                return;

            default:
                /*
                 * An element nobody here has heard of: descend, keep the text.
                 *
                 * The same call document_html.dart makes, and the same reason —
                 * a sentence that arrives wrapped in a tag this file predates
                 * must not vanish. It is safe precisely because of the design
                 * note above: descending produces text and nothing else, and the
                 * unknown element's attributes are not read on this path any
                 * more than on any other.
                 *
                 * Anything genuinely dangerous is in DROPPED_WHOLE and never
                 * reaches here.
                 */
                $this->children($node, $depth, $strong, $emphasis, $href, $hrefRemoved);
        }
    }

    private function children(
        DOMNode $node,
        int $depth,
        bool $strong,
        bool $emphasis,
        ?string $href,
        bool $hrefRemoved,
    ): void {
        foreach (iterator_to_array($node->childNodes ?? []) as $child) {
            $this->walk($child, $depth, $strong, $emphasis, $href, $hrefRemoved);
        }
    }

    // ── building blocks ──────────────────────────────────────────

    private function addText(
        string $text,
        bool $strong,
        bool $emphasis,
        ?string $href,
        bool $hrefRemoved,
    ): void {
        $text = $this->clean($text);

        if ($text === '' || count($this->pending) >= self::MAX_SPANS_PER_BLOCK) {
            return;
        }

        $this->pending[] = new Span($text, $strong, $emphasis, $href, $hrefRemoved);
    }

    private function flush(int $depth): void
    {
        $this->flushAs($depth > 0 ? BlockKind::Quote : BlockKind::Paragraph, $depth);
    }

    /**
     * Write the accumulated run as a block — or write nothing.
     *
     * The empty check is what stops a table-laid-out newsletter, which is nested
     * twenty divs deep with nothing but whitespace between the tags, producing a
     * block per level.
     */
    private function flushAs(BlockKind $kind, int $depth): void
    {
        $spans = $this->pending;

        $this->pending = [];

        if ($spans === [] || trim(implode('', array_map(fn (Span $s) => $s->text, $spans))) === '') {
            return;
        }

        $this->push(new Block($kind, $spans, $depth));
    }

    private function push(Block $block): void
    {
        if (count($this->blocks) >= self::MAX_BLOCKS) {
            $this->truncated = true;

            return;
        }

        $this->blocks[] = $block;
    }

    /**
     * Whitespace collapsed, control characters removed.
     *
     * The control characters matter beyond tidiness: U+202E RIGHT-TO-LEFT
     * OVERRIDE is how a sender makes `annexe-fdp.exe` read as `annexe-exe.pdf`
     * on screen, and the zero-width characters are how text is made to look
     * different to a person than to anything matching on it.
     */
    private function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /** The same removals, keeping the line breaks — for preformatted text. */
    private function collapseNothing(string $text): string
    {
        $text = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    // ── the one attribute that is read ───────────────────────────

    /**
     * A destination, or null.
     *
     * ── `javascript:` has more spellings than anybody expects ────
     *
     * Every one of these is the same URL as far as a browser is concerned, and
     * a `str_starts_with($url, 'javascript:')` check catches the first only:
     *
     *     javascript:alert(1)
     *     JaVaScRiPt:alert(1)              case
     *     &#106;avascript:alert(1)         a numeric entity
     *     &#x6A;avascript:alert(1)         a hex entity
     *     java&Tab;script:alert(1)         a named entity inside the word
     *     \tjavascript:alert(1)            a leading control character
     *     java\nscript:alert(1)            a newline inside the word
     *     " javascript:alert(1)"           leading whitespace
     *     javascript\x00:alert(1)          an embedded NUL
     *
     * They are handled in two steps rather than by a list of patterns, because a
     * list of patterns is a list of the ones somebody thought of.
     *
     *   1. THE ENTITIES ARE ALREADY GONE. The DOM parser decoded them when it
     *      read the attribute, so by the time this method sees the value there
     *      is no `&#106;` left to miss. This is a real dependency on the parser
     *      and it is stated here so that nobody "hardens" this method by adding
     *      an html_entity_decode call — decoding a SECOND time is how a piece of
     *      harmless text like `&amp;#106;avascript:` becomes an executable URL.
     *
     *   2. EVERYTHING UNPRINTABLE IS REMOVED, then the scheme is compared
     *      against an ALLOWLIST. Not a blocklist: the question is never "is this
     *      one of the dangerous schemes", because `data:`, `vbscript:`, `file:`,
     *      `blob:`, `intent:` and whatever a platform invents next are all
     *      dangerous, and the list of them is not knowable. The question is "is
     *      this http, https or mailto", and everything else is refused without
     *      being understood.
     *
     * ── A relative URL is refused too ────────────────────────────
     *
     * `/settings/roles` in a message body would resolve against EzBizi's own
     * origin, so a link in an email could point at any page of the application —
     * including one that acts on a GET. There is nothing a relative URL in
     * somebody else's email could legitimately mean, so there is no case to
     * weigh.
     */
    private function safeHref(string $raw): ?string
    {
        // Whitespace, NULs and every other control character, wherever they sit.
        // `\s` in unicode mode also removes U+00A0 and friends, which is the
        // point: a non-breaking space inside "java script:" is invisible.
        $url = (string) preg_replace('/[\x00-\x20\x7F]|\s/u', '', $raw);

        if ($url === '' || strlen($url) > self::MAX_HREF_LENGTH) {
            return null;
        }

        /*
         * The scheme is what appears before the first colon, and only when that
         * colon comes before any `/`, `?` or `#`. Without that condition,
         * `/path/to:thing` reads as a scheme of `/path/to` — which is refused
         * anyway, so the condition is not what makes this safe. What it buys is
         * that the refusal below is about the right thing.
         */
        if (! preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $found)) {
            return null;    // relative, or no scheme at all
        }

        $scheme = strtolower($found[1]);

        if (! in_array($scheme, self::SAFE_SCHEMES, strict: true)) {
            return null;
        }

        /*
         * THE STRING THAT WAS JUDGED IS THE STRING THAT IS PUBLISHED.
         *
         * An earlier draft returned `trim($raw)` — the original — on the
         * reasoning that removing characters might change where a legitimate
         * link goes. That reopens the whole hole: the value that passed the
         * check and the value that a client would eventually open would be two
         * different strings, and every attack in the list above is exactly the
         * art of making those two differ. Deciding on one string and handing
         * over another is the bug, whichever direction it points.
         *
         * Nothing legitimate is lost. A URL cannot contain a raw space or a
         * control character and remain a URL — they are percent-encoded, and
         * `%20` is untouched by the strip above, because it is three ordinary
         * printable characters.
         */
        return $url;
    }
}
