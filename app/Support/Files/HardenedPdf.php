<?php

namespace App\Support\Files;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * A dompdf that fetches nothing, executes nothing, and proves both.
 *
 * ── Why this is one class and not one per caller ─────────────────
 *
 * It was written for the contract copy (see Contracts\PdfRenderer, which still
 * makes the argument for why a contract may be converted at all) and moved here
 * the moment a second module wanted a PDF. The reason is the reason for the
 * locks themselves: every document this application converts is TENANT INPUT —
 * a contract body somebody wrote, an inspection whose field labels and answers
 * came off a phone on a roof — and a converter that resolves references in it
 * is a request-forger standing inside the network. A second copy of this
 * configuration would be a second chance to get one of the four locks wrong,
 * and the second copy is always the weaker one, because whoever wrote it was
 * thinking about their feature rather than about SSRF.
 *
 * So: one home, and the callers differ only in what HTML they hand it.
 *
 * ── The four locks ───────────────────────────────────────────────
 *
 * Each is set EXPLICITLY even where it is already the library's default, so a
 * release that re-arms one has a line here to argue with:
 *
 *   isRemoteEnabled(false)      no http/https. Default, written anyway — the day
 *                               somebody sets it from config, every document
 *                               body becomes an SSRF against this network.
 *   isPhpEnabled(false)         dompdf EXECUTES `<script type="text/php">` with
 *                               the process's own privileges when this is on.
 *   isJavascriptEnabled(false)  NOT a default — dompdf ships this ON. It copies
 *                               `<script type="text/javascript">` into the PDF
 *                               as PDF-level JavaScript, which then runs in
 *                               whatever reader opens the file. No document here
 *                               has script, and none must be able to carry one
 *                               to somebody else's machine.
 *   allowedProtocols(refusing)  the belt to those braces. Every scheme dompdf
 *                               knows is listed with one rule that says no, so
 *                               file://, http://, https:// and phar:// are each
 *                               refused before anything is opened, and a scheme
 *                               not on the list is refused for not being on it.
 *
 *                               An EMPTY list is the obvious way to write that
 *                               and it is wrong: dompdf's own CSS url() resolver
 *                               warns about the unknown scheme and then indexes
 *                               the list anyway (Css/Stylesheet::_image), so an
 *                               empty array turns a refusal into an
 *                               undefined-key error and a 500.
 *
 * `data:image/png;base64,` and `data:image/jpeg;base64,` are the one exception,
 * and it is narrow on purpose. Every other scheme is refused because it makes
 * the converter GO SOMEWHERE on the strength of text a customer wrote. A `data:`
 * URI goes nowhere: it carries its own bytes, already inside the document being
 * converted. The prefixes are matched exactly rather than by scheme, because
 * `data:` is a container for anything — `data:text/html` and
 * `data:image/svg+xml` are the ones that matter, SVG being a document format
 * with scripts and external references of its own.
 *
 * JPEG is admitted alongside PNG because an inspection photograph comes off a
 * camera, and a camera does not produce PNG. It is the same inert container of
 * bytes the cover already relies on.
 *
 * ── And then it is CHECKED, which is not the same as set ─────────
 *
 * `dompdf/dompdf` is required as `^3.1`, so a minor upgrade installs on a deploy
 * without anybody reading its changelog, and every lock above is a value this
 * application reads back out of a library it does not control. The protocol half
 * RUNS THE RULES rather than counting them, because "there is a rule" and "the
 * rule refuses" are different facts and only the second is the guarantee.
 */
final class HardenedPdf
{
    private const FETCHES_NOTHING = 'This converter does not fetch anything.';

    /**
     * Convert self-contained HTML and hand back the bytes.
     *
     * For callers with nothing to add to the canvas. PdfRenderer does not use
     * this — it stamps the page between render and output — and takes
     * `options()` alone.
     */
    /**
     * @param  string|null  $pageNumbers  drawn at the foot of every page, right, e.g. "Page {PAGE_NUM} of {PAGE_COUNT}":
     *                                    CSS counter(pages) reads 0 in dompdf, the canvas knows the count
     */
    public static function render(string $html, string $paper, ?string $pageNumbers = null): string
    {
        $dompdf = new Dompdf(self::options($paper));

        // Named rather than sniffed. A converter that guessed would mangle every
        // accented name in a Spanish document on the day a meta tag moved.
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        if ($pageNumbers !== null) {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
            $width = $dompdf->getFontMetrics()->getTextWidth(str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], '99', $pageNumbers), $font, 8);
            $canvas->page_text($canvas->get_width() - 40 - $width, $canvas->get_height() - 28, $pageNumbers, $font, 8, [0.61, 0.64, 0.69]);
        }

        return (string) $dompdf->output();
    }

    public static function options(string $paper): Options
    {
        $refuse = static fn (string $uri): array => [false, self::FETCHES_NOTHING];

        $inlineImage = static fn (string $uri): array => str_starts_with($uri, 'data:image/png;base64,')
            || str_starts_with($uri, 'data:image/jpeg;base64,')
                ? [true, null]
                : [false, self::FETCHES_NOTHING];

        $options = new Options();

        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols([
            'file://' => ['rules' => [$refuse]],
            'http://' => ['rules' => [$refuse]],
            'https://' => ['rules' => [$refuse]],
            'data://' => ['rules' => [$inlineImage]],
            'phar://' => ['rules' => [$refuse]],
        ]);

        // Pinned rather than left to whatever extensions the box has: which
        // renderer produces a document is not something a deploy should change.
        $options->setPdfBackend('CPDF');
        $options->setDefaultPaperSize(self::assertKnownPaper($paper));

        if ($options->getIsRemoteEnabled()
            || $options->getIsPhpEnabled()
            || $options->getIsJavascriptEnabled()) {
            throw new RuntimeException(
                'The PDF converter would not stay locked down: after being told to fetch '
                .'nothing and execute nothing, it still reports one of those as available. '
                .'Refusing to convert, because the document is written by a customer and a '
                .'converter that resolves references in it can be aimed at this network. '
                .'Check what changed in dompdf.'
            );
        }

        foreach ($options->getAllowedProtocols() as $protocol => $config) {
            $refused = false;

            foreach ($config['rules'] ?? [] as $rule) {
                [$permitted] = $rule($protocol.'ezbizi.invalid/probe');

                if ($permitted !== true) {
                    $refused = true;
                    break;
                }
            }

            if (! $refused) {
                throw new RuntimeException(sprintf(
                    'The PDF converter would fetch "%s" from a document body. Every scheme it '
                    .'knows is supposed to be refused outright, because the body is written by '
                    .'a customer and this server is inside the network. Refusing to convert '
                    .'anything until that is true again.',
                    $protocol,
                ));
            }
        }

        return $options;
    }

    /**
     * An unknown paper size REFUSES rather than falling back.
     *
     * dompdf's own behaviour is to silently substitute letter for anything it
     * does not recognise, which turns a typo into a mystery about margins six
     * months later.
     */
    public static function assertKnownPaper(string $paper): string
    {
        $paper = strtolower(trim($paper));

        if (! array_key_exists($paper, CPDF::$PAPER_SIZES)) {
            throw new RuntimeException(sprintf(
                'The paper size is set to "%s", which is not a size this converter knows. '
                .'Set it to one of: %s. It is refused rather than approximated because the '
                .'alternative is a document that quietly prints on the wrong paper.',
                $paper,
                implode(', ', array_slice(array_keys(CPDF::$PAPER_SIZES), 0, 12)).', …',
            ));
        }

        return $paper;
    }
}
