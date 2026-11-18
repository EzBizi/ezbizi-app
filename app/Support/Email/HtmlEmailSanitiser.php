<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use DOMDocument;
use DOMElement;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Hostile HTML mail becomes a document we can put in a sandboxed iframe.
 *
 * ── Why this exists, after the block structure ───────────────────
 *
 * Blocks cannot look like Gmail. Marketing mail is tables, sized images,
 * buttons, and columns. Flattening that into a reading-order stack was a
 * safety property, and it made every newsletter look broken. The owner
 * asked for Gmail-close display. So the HTML is kept, but only after
 * HTMLPurifier has taken out script, forms, foreign content and CSS that
 * can run code or fetch. Pictures are then fetched through
 * {@see RemotePictureFetcher} / Gmail CID and inlined as data URIs, so the
 * iframe never dials a host the sender chose and never needs our session
 * cookie on `<img>`.
 *
 * `<style>` blocks are lifted out before purify, have `url()`, `@import`
 * and expressions stripped, and put back in the wrapper — Gmail keeps
 * class-based layout; we keep the subset that cannot fetch.
 *
 * The block structure is still produced and still the fallback (plain
 * text, and any client that is not the web app).
 */
final class HtmlEmailSanitiser
{
    public const MAX_INPUT_BYTES = 524288;

    public const MAX_IMAGES = 24;

    public function __construct(
        private readonly EmailPictureLoader $pictures,
    ) {}

    public function prepare(string $html, Mailbox $mailbox, string $messageId): string
    {
        [$html, $css] = $this->pullStyleBlocks($html);
        $clean = $this->inlinePictures($this->purify($html), $mailbox, $messageId);

        return $this->wrap($clean, $css);
    }

    /**
     * Layout without fetching pictures — so a test can assert tables and
     * classes without dialling a host.
     */
    public function layout(string $html): string
    {
        [$html, $css] = $this->pullStyleBlocks($html);

        return $this->wrap($this->purify($html), $css);
    }

    public function purify(string $html): string
    {
        if (strlen($html) > self::MAX_INPUT_BYTES) {
            $html = substr($html, 0, self::MAX_INPUT_BYTES);
        }

        $cache = storage_path('app/htmlpurifier');

        if (! is_dir($cache)) {
            mkdir($cache, 0755, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cache);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('HTML.Nofollow', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.EnableID', true);
        $config->set('CSS.AllowImportant', true);
        // border-radius lives here; display:inline-block (mail buttons) is
        // "tricky". Trusted is left off so position/z-index cannot overlay
        // a fake login on the pane.
        $config->set('CSS.Proprietary', true);
        $config->set('CSS.AllowTricky', true);
        $config->set('CSS.MaxImgLength', null);
        $config->set('HTML.MaxImgLength', null);
        $config->set('URI.AllowedSchemes', [
            'http'   => true,
            'https'  => true,
            'mailto' => true,
            'cid'    => true,
            'data'   => true,
        ]);
        // Transitional's own table/img/font set, minus the things that run
        // or fetch. A hand-built Allowed list rejected `table height` and
        // would have kept rejecting attributes mail actually uses.
        $config->set('HTML.ForbiddenElements', [
            'script', 'iframe', 'object', 'embed', 'form', 'input', 'button',
            'textarea', 'select', 'applet', 'base', 'link', 'meta', 'svg',
            'math', 'video', 'audio', 'source', 'track', 'frame', 'frameset',
            'style', 'noscript',
        ]);
        $config->set('CSS.AllowedProperties', [
            'font', 'font-size', 'font-weight', 'font-family', 'font-style', 'font-variant',
            'color', 'background-color',
            'text-align', 'text-decoration', 'text-transform', 'text-indent',
            'vertical-align',
            'line-height', 'letter-spacing', 'word-spacing', 'white-space',
            'width', 'height', 'max-width', 'min-width', 'max-height', 'min-height',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
            'border-collapse', 'border-spacing', 'border-radius',
            'border-color', 'border-width', 'border-style',
            'display', 'float', 'clear',
            'list-style', 'list-style-type', 'list-style-position',
            'table-layout', 'overflow', 'opacity', 'direction',
        ]);
        // The shorthand and the image properties would let CSS fetch a
        // host the sender chose. Colour stays; pictures go through <img>.
        $config->set('CSS.ForbiddenProperties', [
            'background',
            'background-image',
            'list-style-image',
        ]);

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($html);
    }

    public function wrap(string $body, string $css = ''): string
    {
        $css = $this->sanitiseCss($css);

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            // A second lock behind the purifier: nothing in a received mail can
            // run or load anything but pictures, styles and fonts. The desktop
            // and phone apps draw this in a WebView, not a sandboxed iframe
            // (Jose 11-12: mail "lacking the proper formatting" on Windows).
            .'<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; '
            .'img-src data: https: http:; style-src \'unsafe-inline\' https:; font-src data: https:; media-src data: https:">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .($css !== '' ? '<style>'.$css.'</style>' : '')
            .'<style>'
            // Gmail's reading pane: white page, the sender's table as wide as
            // it asked (capped at the pane), pictures shrinking with it.
            // border-collapse is NOT forced — cellspacing is how a lot of
            // mail makes gaps, and collapsing them stacks the columns.
            .'html,body{margin:0;padding:20px 24px 32px;background:#fff;color:#202124;'
            .'font-family:Arial,Helvetica,sans-serif;font-size:small;line-height:1.5;}'
            .'img{max-width:100%;height:auto;}'
            .'table{max-width:100%;}'
            .'a{color:#1a73e8;}'
            .'</style></head><body>'
            .$body
            .'</body></html>';
    }

    /**
     * @return array{0: string, 1: string} remaining markup, concatenated CSS
     */
    private function pullStyleBlocks(string $html): array
    {
        $css = '';

        $stripped = preg_replace_callback(
            '#<style\b[^>]*>(.*?)</style>#is',
            function (array $match) use (&$css): string {
                $css .= $match[1]."\n";

                return '';
            },
            $html,
        );

        return [$stripped ?? $html, $this->sanitiseCss($css)];
    }

    private function sanitiseCss(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string) preg_replace('/@import\b[^;{]*;?/i', '', $css);
        $css = (string) preg_replace('/@charset\b[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/expression\s*\(/i', 'invalid(', $css);
        $css = (string) preg_replace('/javascript\s*:/i', 'invalid:', $css);
        $css = (string) preg_replace('/-moz-binding\s*:/i', 'invalid:', $css);
        $css = (string) preg_replace('/behavior\s*:/i', 'invalid:', $css);
        // url() in CSS is a fetch the iframe would make to a host the
        // sender chose — tracking, and the same SSRF shape as <img>.
        $css = (string) preg_replace('/url\s*\(\s*[\'"]?[^)]*[\'"]?\s*\)/i', 'none', $css);

        return trim($css);
    }

    private function inlinePictures(string $html, Mailbox $mailbox, string $messageId): string
    {
        if (trim($html) === '' || ! str_contains(strtolower($html), '<img')) {
            return $html;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Pictures are FETCHED TOGETHER, in batches, then written in. This loop
        // used to fetch one, write it, fetch the next -- so an email with 24
        // pictures waited for 24 downloads in a row inside the open. Measured
        // on production 2026-10-06: median 318 ms, worst 2.5 s, while
        // sanitising the HTML itself took 7 ms. See RemotePictureFetcher::fetchMany.
        //
        // The cap means the same as before: up to MAX_IMAGES pictures that
        // actually LOADED. A batch is as many as are still wanted, so in the
        // ordinary case it is one batch; when some fail, the next pictures in
        // the email get their chance exactly as they did one at a time.
        /** @var list<DOMElement> $pending */
        $pending = iterator_to_array($document->getElementsByTagName('img'));
        $done = 0;

        while ($pending !== [] && $done < self::MAX_IMAGES) {
            $batch = array_splice($pending, 0, self::MAX_IMAGES - $done);

            $data = $this->pictureDataUris(
                array_map(fn (DOMElement $img) => trim($img->getAttribute('src')), $batch),
                $mailbox,
                $messageId,
            );

            foreach ($batch as $i => $img) {
                if ($data[$i] === null) {
                    $img->removeAttribute('src');

                    continue;
                }

                $img->setAttribute('src', $data[$i]);
                $done++;
            }
        }

        // Past the cap: removed, as before.
        foreach ($pending as $img) {
            $img->parentNode?->removeChild($img);
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return $html;
        }

        $inner = '';

        foreach ($body->childNodes as $child) {
            $inner .= $document->saveHTML($child);
        }

        return $inner;
    }

    /**
     * Data URIs for these sources, fetched together. Same keys; null = dropped.
     *
     * @param  array<int, string>  $srcs
     * @return array<int, string|null>
     */
    private function pictureDataUris(array $srcs, Mailbox $mailbox, string $messageId): array
    {
        $out = [];
        $fetch = [];

        foreach ($srcs as $i => $src) {
            if ($src === '') {
                $out[$i] = null;
            } elseif (str_starts_with(strtolower($src), 'data:image/')) {
                $out[$i] = $src;
            } else {
                $fetch[$i] = $src;
            }
        }

        foreach ($this->pictures->loadMany($mailbox, $messageId, $fetch) as $i => $picture) {
            $out[$i] = $picture === null
                ? null
                : 'data:'.$picture->mime.';base64,'.base64_encode($picture->bytes);
        }

        ksort($out);

        return $out;
    }
}
