<?php

namespace App\Support\Newsletter;

/**
 * Blocks into an email somebody's mail client will actually draw.
 *
 * ── Why tables, in 2026 ─────────────────────────────────────────
 *
 * Because Outlook on Windows renders with Word's engine, which has no flexbox,
 * no grid, and no reliable `max-width`. A newsletter that only survives Gmail is
 * not a newsletter — it is a newsletter for the half of the list that does not
 * include the customers most likely to be reading it on a work machine. Nested
 * tables and inline styles are ugly and they are what arrives intact.
 *
 * ── Why the server renders it and not the client ────────────────
 *
 * The preview and the thing that gets sent have to be the same document. If the
 * Flutter side built the HTML for display and the server built it again for
 * sending, the preview would eventually be a picture of a different email —
 * which is the failure the composer's whole left-right split exists to prevent.
 * So the client asks for this HTML and shows it; nothing renders twice.
 *
 * ── Escaping ────────────────────────────────────────────────────
 *
 * Every value out of the block JSON is a tenant's words and is escaped. The one
 * exception is a block carrying `html`, which is a hand-edit somebody made on
 * purpose in HTML mode — see `Newsletter::$html_override` for why that survives
 * rather than being rebuilt.
 */
class BlockRenderer
{
    /** Email clients agree on 600px more than they agree on anything else. */
    private const WIDTH = 600;

    /**
     * The applied brand (P1b) while a document renders: defaults for every
     * block property a block does not set itself. Null: the house defaults.
     *
     * @var array<string, mixed>|null
     */
    private ?array $brand = null;

    /** Per-block mobile rules collected while rendering (P1b). */
    private string $mobileCss = '';

    private int $counter = 0;

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array{company: string, address: string, unsubscribe_url: string}  $footer
     * @param  array<string, mixed>|null  $brand
     */
    public function render(array $blocks, array $footer, ?array $brand = null): string
    {
        $this->brand = NewsletterBrandSettings::clean($brand);
        $this->mobileCss = '';
        $this->counter = 0;

        try {
            $body = implode('', array_map(fn (array $b) => $this->block($b), array_values(array_filter($blocks, 'is_array'))));
            $page = $this->brandValue('background', '#EDF1F4');
            $font = NewsletterBrandSettings::fontStack($this->brand['font'] ?? null);

            return '<!DOCTYPE html>'
                .'<html><head><meta charset="utf-8">'
                .'<meta name="viewport" content="width=device-width,initial-scale=1">'
                // Phones (P1b): the 600 table becomes the screen's width, pictures
                // never overflow, long words and links wrap, columns stack unless
                // a block turned stacking off, and each block's own mobile
                // overrides apply. The inline widths stay for clients that strip <style>.
                .'<style>@media only screen and (max-width:620px){'
                .'.ez-col{display:block!important;width:100%!important;max-width:100%!important;box-sizing:border-box;}'
                .'.ez-wrap{width:100%!important;max-width:100%!important;}'
                .'.ez-wrap img{max-width:100%!important;height:auto!important;}'
                .'.ez-wrap td{word-break:break-word;overflow-wrap:anywhere;}'
                .$this->mobileCss
                .'}</style>'
                .'</head>'
                .'<body style="margin:0;padding:0;background:'.$page.';">'
                .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
                .'style="background:'.$page.';padding:18px 0;"><tr><td align="center">'
                .'<table role="presentation" class="ez-wrap" width="'.self::WIDTH.'" cellpadding="0" cellspacing="0" '
                .'style="width:'.self::WIDTH.'px;max-width:100%;background:#ffffff;'
                .'border-radius:6px;overflow:hidden;font-family:'.$font.';">'
                .$this->logo()
                .$body
                .$this->footer($footer)
                .'</table></td></tr></table></body></html>';
        } finally {
            $this->brand = null;
        }
    }

    /** The brand's logo across the top, when the applied brand has one. */
    private function logo(): string
    {
        $src = trim((string) ($this->brand['logo_url'] ?? ''));
        if ($src === '' || $this->link($src, ['https']) === '#') {
            return '';
        }

        return '<tr><td style="padding:18px 20px 4px;text-align:center;"><img src="'.$this->esc($src)
            .'" alt="'.$this->esc((string) ($this->brand['logo_alt'] ?? '')).'" width="160" '
            .'style="display:inline-block;width:160px;max-width:100%;height:auto;border:0;"></td></tr>';
    }

    private function brandValue(string $key, string $fallback): string
    {
        $v = (string) ($this->brand[$key] ?? '');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $fallback;
    }

    /**
     * The brand's defaults for a block, under the block's own values: a
     * property the block sets wins, one it leaves out takes the brand's.
     *
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function withBrand(array $b): array
    {
        if ($this->brand === null) {
            return $b;
        }
        $defaults = match ((string) ($b['type'] ?? '')) {
            'heading', 'text' => ['color' => $this->brand['text'] ?? null],
            'button' => [
                'background' => $this->brand['button_fill'] ?? $this->brand['primary'] ?? null,
                'color' => $this->brand['button_text'] ?? null,
                'full_width' => $this->brand['button_full_width'] ?? null,
                'radius' => $this->brand['button_radius'] ?? null,
            ],
            default => [],
        };

        return $b + array_filter($defaults, fn ($v) => $v !== null);
    }

    /**
     * The whole email for a newsletter's content: custom HTML (with the
     * protected footer ensured) when it has one, else the blocks, and the
     * preheader as hidden text at the top in both modes (P1a).
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array{company: string, address: string, unsubscribe_url: string}  $footer
     * @param  array<string, mixed>|null  $brand
     */
    public function document(array $blocks, ?string $htmlOverride, ?string $preheader, array $footer, ?array $brand = null): string
    {
        $html = is_string($htmlOverride) && $htmlOverride !== ''
            ? $this->withFooter($htmlOverride, $footer)
            : $this->render($blocks, $footer, $brand);

        return $this->withPreheader($html, $preheader);
    }

    /**
     * The preheader: the line an inbox shows after the subject. Hidden in the
     * body (every common client honours at least one of these properties),
     * padded so the inbox does not pull the first visible words in after it.
     */
    public function withPreheader(string $html, ?string $preheader): string
    {
        $preheader = trim((string) $preheader);
        if ($preheader === '') {
            return $html;
        }

        $hidden = '<div data-ez-preheader="1" style="display:none;font-size:1px;line-height:1px;'
            .'max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;color:transparent;">'
            .$this->esc($preheader).str_repeat('&#847;&zwnj;&nbsp;', 30).'</div>';

        if (preg_match('/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);

            return substr($html, 0, $at).$hidden.substr($html, $at);
        }

        return $hidden.$html;
    }

    /**
     * One block on its own, as the rows it renders to. Preflight reads each
     * block's links from this so a problem names the block it is in.
     *
     * @param  array<string, mixed>  $b
     */
    public function fragment(array $b): string
    {
        return $this->block($b);
    }

    /**
     * A block, with its mobile overrides (P1b) as a class the phone rules
     * in <head> address. Every block renders as `<tr><td`, so the class goes
     * on that cell.
     *
     * @param  array<string, mixed>  $b
     */
    private function block(array $b): string
    {
        $html = $this->blockBody($this->withBrand($b));
        $rules = $this->mobileRules($b);
        if ($rules === [] || ! str_starts_with($html, '<tr><td')) {
            return $html;
        }
        $class = 'ez-b'.(++$this->counter);
        foreach ($rules as $rule) {
            $this->mobileCss .= str_replace('%c', '.'.$class, $rule);
        }

        return '<tr><td class="'.$class.'"'.substr($html, 7);
    }

    /**
     * The phone rules for a block's `mobile` overrides: font size, vertical
     * padding, alignment, button width and hidden. Values are clamped like
     * the desktop ones; `%c` stands for the block's class.
     *
     * @param  array<string, mixed>  $b
     * @return list<string>
     */
    private function mobileRules(array $b): array
    {
        $m = $b['mobile'] ?? null;
        if (! is_array($m) || $m === []) {
            return [];
        }
        if (($m['hidden'] ?? false) === true) {
            return ['%c{display:none!important;max-height:0!important;overflow:hidden!important;}'];
        }
        $rules = [];
        if (isset($m['font_size']) && is_numeric($m['font_size']) && (int) $m['font_size'] >= 8 && (int) $m['font_size'] <= 96) {
            $rules[] = '%c h1,%c p,%c div,%c a,%c li{font-size:'.(int) $m['font_size'].'px!important;}';
        }
        if (isset($m['padding']) && is_numeric($m['padding'])) {
            $px = max(0, min(64, (int) $m['padding']));
            $rules[] = '%c{padding-top:'.$px.'px!important;padding-bottom:'.$px.'px!important;}';
        }
        if (in_array($m['align'] ?? null, ['left', 'center', 'right'], true)) {
            $rules[] = '%c,%c h1,%c p,%c div{text-align:'.$m['align'].'!important;}';
        }
        if (array_key_exists('button_full_width', $m) && is_bool($m['button_full_width'])) {
            $rules[] = $m['button_full_width']
                ? '%c table{width:100%!important;}%c a{display:block!important;text-align:center!important;}'
                : '%c table{width:auto!important;}%c a{display:inline-block!important;}';
        }

        return $rules;
    }

    /** @param array<string, mixed> $b */
    private function blockBody(array $b): string
    {
        $type = (string) ($b['type'] ?? '');

        // A block somebody hand-edited in HTML mode. Kept exactly as written —
        // rebuilding it from fields is the one thing that would lose their work.
        if (isset($b['html']) && is_string($b['html']) && $b['html'] !== '') {
            return $this->row($b['html']);
        }

        return match ($type) {
            'heading' => $this->row('<h1 style="margin:0;font-size:'
                            .$this->int($b, 'size', 24).'px;line-height:'.$this->lineHeight($b, '1.25').';color:'
                            .$this->color($b, 'color', '#060808').';'
                            .'text-align:'.$this->align($b).';">'.$this->words($b).'</h1>', $b),
            'text' => $this->row(($this->isRich($b) ? '<div' : '<p').' style="margin:0;font-size:'
                            .$this->int($b, 'size', 15).'px;line-height:'.$this->lineHeight($b, '1.55').';'
                            .'color:'.$this->color($b, 'color', '#3a4650').';text-align:'.$this->align($b).';">'
                            .$this->words($b).($this->isRich($b) ? '</div>' : '</p>'), $b),
            'image' => $this->image($b),
            'button' => $this->button($b),
            'divider' => '<tr><td style="padding:8px 20px;"><table role="presentation" width="100%"'
                            .' cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid'
                            .' #E9EDF1;font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>',
            'spacer' => '<tr><td style="height:'.$this->int($b, 'height', 16).'px;font-size:0;'
                            .'line-height:0;">&nbsp;</td></tr>',
            'columns' => $this->columns($b),
            default => '',
        };
    }

    /**
     * A row of columns, as a table row of table cells.
     *
     * ── Why the columns stack on a phone, and how ───────────────
     *
     * `width="50%"` cells do not stack — they squeeze, and two 300px columns on
     * a 375px screen is unreadable. The fix that works everywhere is inline
     * blocks with a fixed width and a media query, but Outlook ignores media
     * queries entirely, which is why the widths are ALSO set as attributes: a
     * client that honours the query stacks, and one that does not gets the
     * side-by-side layout it can actually draw.
     *
     * @param  array<string, mixed>  $b
     */
    private function columns(array $b): string
    {
        $columns = array_values(array_filter(
            (array) ($b['columns'] ?? []),
            fn ($c) => is_array($c),
        ));

        if ($columns === []) {
            return '';
        }

        $widths = $this->columnWidths($b, count($columns));
        // The mobile override (P1b) wins over the older block-level switch.
        $mobile = is_array($b['mobile'] ?? null) ? $b['mobile'] : [];
        $stack = (array_key_exists('stack', $mobile) ? $mobile['stack'] : ($b['stack'] ?? true)) !== false;
        $gap = isset($b['padding']) && is_numeric($b['padding']) ? max(0, min(40, (int) $b['padding'])) : null;
        $index = 0;

        $cells = implode('', array_map(function (array $column) use ($widths, $stack, $gap, &$index) {
            $width = $widths[$index++] ?? (int) floor(100 / max(1, count($widths)));
            /*
             * The composer has always saved a column as `{blocks: [...]}`, and
             * this read it as a bare list of blocks - so every column the
             * composer made rendered empty on the server, found when the
             * composer began drawing columns itself (Mia, 2026-10-02). Both
             * shapes are read, so nothing already saved changes meaning.
             */
            if (isset($column['blocks']) && is_array($column['blocks'])) {
                $column = $column['blocks'];
            }

            $inner = implode('', array_map(function (array $child) {
                // Children render as rows, so each column holds its own little
                // table — a column of blocks is the same thing as the email,
                // one level down, and reusing `block()` keeps it that way.
                return $this->block($child);
            }, array_values(array_filter($column, 'is_array'))));

            return '<td'.($stack ? ' class="ez-col"' : '').' width="'.$width.'%" valign="top" '
                .'style="width:'.$width.'%;'.($gap === null ? '' : 'padding:0 '.$gap.'px;').'"><table role="presentation" width="100%" '
                .'cellpadding="0" cellspacing="0">'.$inner.'</table></td>';
        }, $columns));

        return '<tr><td style="padding:0 4px;"><table role="presentation" width="100%" '
            .'cellpadding="0" cellspacing="0"><tr>'.$cells.'</tr></table></td></tr>';
    }

    /**
     * Custom HTML gets the same protected footer the blocks do.
     *
     * An imported document was sent and previewed exactly as pasted, so a
     * mailing with no postal address or way out could leave (audit
     * 2026-11-10). The footer is added unless the document already links to
     * this unsubscribe address or Mailchimp's `*|UNSUB|*`, which the author
     * placed on purpose; adding a second would only double it.
     *
     * @param  array{company: string, address: string, unsubscribe_url: string}  $f
     */
    public function withFooter(string $html, array $f): string
    {
        $targets = array_unique(['*|UNSUB|*', $f['unsubscribe_url'], $this->esc($f['unsubscribe_url'])]);
        foreach ($targets as $target) {
            if (preg_match('/href\s*=\s*["\']\s*'.preg_quote($target, '/').'\s*["\']/i', $html)) {
                return $html;
            }
        }

        $footer = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            .'data-ez-footer="1" style="max-width:'.self::WIDTH.'px;margin:0 auto;">'
            .$this->footer($f).'</table>';
        $at = strripos($html, '</body>');

        return $at === false ? $html.$footer : substr($html, 0, $at).$footer.substr($html, $at);
    }

    /**
     * An image block: optional displayed width (px, at most the content
     * width), alignment, a link around it, and alt text, which is empty when
     * the block is marked decorative so a screen reader skips it.
     *
     * @param  array<string, mixed>  $b
     */
    private function image(array $b): string
    {
        $max = self::WIDTH - 40;
        $width = isset($b['width']) && is_numeric($b['width']) && (int) $b['width'] > 0 ? max(20, min($max, (int) $b['width'])) : $max;
        $alt = ! empty($b['decorative']) ? '' : (string) ($b['alt'] ?? '');
        $align = $this->align($b);
        $margin = match ($align) {
            'center' => 'margin:0 auto;',
            'right' => 'margin:0 0 0 auto;',
            default => '',
        };
        $img = '<img src="'.$this->esc($this->link((string) ($b['src'] ?? ''), ['http', 'https']))
            .'" alt="'.$this->esc($alt).'" width="'.$width.'" style="display:block;'.$margin
            .'width:'.($width === $max ? '100%' : $width.'px').';max-width:100%;height:auto;border:0;">';
        $href = trim((string) ($b['href'] ?? ''));
        if ($href !== '') {
            $img = '<a href="'.$this->esc($this->link($href, ['http', 'https', 'mailto', 'tel'])).'">'.$img.'</a>';
        }

        return $this->row('<div style="text-align:'.$align.';">'.$img.'</div>', $b);
    }

    /** @param array<string, mixed> $b */
    private function row(string $inner, array $b = []): string
    {
        $v = isset($b['spacing']) && is_numeric($b['spacing']) ? max(0, min(64, (int) $b['spacing'])) : 14;

        return '<tr><td style="padding:'.$v.'px 20px;">'.$inner.'</td></tr>';
    }

    /**
     * A button: label, link, its colours, and full width or its own width.
     *
     * @param  array<string, mixed>  $b
     */
    private function button(array $b): string
    {
        $full = ! empty($b['full_width']);
        $bg = $this->color($b, 'background', '#00708F');
        $fg = $this->color($b, 'color', '#ffffff');
        $radius = isset($b['radius']) && is_numeric($b['radius']) ? max(0, min(40, (int) $b['radius'])) : 6;

        return $this->row('<table role="presentation" cellpadding="0" cellspacing="0" '
            .($full ? 'width="100%"' : 'align="'.$this->align($b).'"')
            .'><tr><td style="background:'.$bg.';border-radius:'.$radius.'px;'.($full ? 'text-align:center;' : '').'">'
            .'<a href="'.$this->esc($this->link((string) ($b['url'] ?? ''), ['http', 'https', 'mailto', 'tel']))
            .'" style="display:'.($full ? 'block' : 'inline-block').';padding:11px 20px;color:'.$fg.';'
            .'text-decoration:none;font-weight:600;font-size:15px;">'
            .$this->text($b).'</a></td></tr></table>', $b);
    }

    /**
     * Column widths in percent: equal, or 1:2 / 2:1 for two columns.
     *
     * @param  array<string, mixed>  $b
     * @return list<int>
     */
    private function columnWidths(array $b, int $count): array
    {
        if ($count === 2) {
            return match ($b['ratio'] ?? '') {
                '1:2' => [33, 67],
                '2:1' => [67, 33],
                default => [50, 50],
            };
        }

        return array_fill(0, max(1, $count), (int) floor(100 / max(1, $count)));
    }

    /** @param array<string, mixed> $b */
    private function isRich(array $b): bool
    {
        return ($b['rich'] ?? false) === true;
    }

    /**
     * A heading's or text's words. Plain text is escaped and line breaks
     * kept. Text the composer formatted (`rich: true`) is escaped the same
     * way and then only these tags are let back in: b, strong, i, em, ul, li,
     * and a with an http(s)/mailto/tel href. Anything else stays as the
     * visible characters it was typed as, and every tag is balanced so an
     * unclosed bold cannot run into the rest of the email.
     *
     * @param  array<string, mixed>  $b
     */
    private function words(array $b): string
    {
        if (! $this->isRich($b)) {
            return nl2br($this->text($b));
        }

        $s = $this->esc((string) ($b['text'] ?? ''));
        $s = (string) preg_replace('#&lt;(/?)(b|strong|i|em|ul|li)&gt;#i', '<$1$2>', $s);
        $s = (string) preg_replace_callback('#&lt;a href=&quot;(.*?)&quot;&gt;#i', fn (array $m) => '<a href="'
            .$this->esc($this->link(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ['http', 'https', 'mailto', 'tel']))
            .'" style="color:'.$this->brandValue('link', '#00708F').';">', $s);
        $s = str_ireplace('&lt;/a&gt;', '</a>', $s);
        $s = (string) preg_replace('#\s*(</?(?:ul|li)>)\s*#i', '$1', $s);

        return nl2br($this->balance($s));
    }

    /** Drops stray closing tags and closes anything left open. */
    private function balance(string $html): string
    {
        $parts = preg_split('#(</?(?:b|strong|i|em|ul|li|a)\b[^>]*>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $open = [];
        $out = '';
        foreach ($parts as $part) {
            if (! preg_match('#^<(/?)(b|strong|i|em|ul|li|a)\b#i', $part, $m)) {
                $out .= $part;

                continue;
            }
            $tag = strtolower($m[2]);
            if ($m[1] === '') {
                $open[] = $tag;
                $out .= $part;

                continue;
            }
            if (! in_array($tag, $open, true)) {
                continue;
            }
            while (($top = array_pop($open)) !== null) {
                $out .= '</'.$top.'>';
                if ($top === $tag) {
                    break;
                }
            }
        }
        while (($top = array_pop($open)) !== null) {
            $out .= '</'.$top.'>';
        }

        return $out;
    }

    /** @param array<string, mixed> $b */
    private function color(array $b, string $key, string $fallback): string
    {
        $v = (string) ($b[$key] ?? '');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $fallback;
    }

    /** @param array<string, mixed> $b */
    private function lineHeight(array $b, string $fallback): string
    {
        $v = $b['line_height'] ?? null;

        return is_numeric($v) && (float) $v >= 1 && (float) $v <= 2.5 ? (string) round((float) $v, 2) : $fallback;
    }

    /**
     * The footer, and the unsubscribe link is not optional.
     *
     * Marketing mail without a way out is unlawful in every market this product
     * sells into, so it is rendered here rather than being a block somebody can
     * delete. A tenant can style the rest; they cannot remove this.
     *
     * @param  array{company: string, address: string, unsubscribe_url: string}  $f
     */
    private function footer(array $f): string
    {
        return '<tr><td style="padding:16px 20px;background:#F5F8FA;text-align:center;'
            .'font-size:11.5px;line-height:1.5;color:#5B6672;">'
            .(trim((string) ($this->brand['footer_note'] ?? '')) !== ''
                ? nl2br($this->esc(trim((string) $this->brand['footer_note']))).'<br><br>' : '')
            .$this->esc($f['company']).'<br>'.$this->esc($f['address']).'<br>'
            .'<a href="'.$this->esc($f['unsubscribe_url']).'" style="color:#5B6672;">'
            .'Unsubscribe from these emails</a>'
            .'</td></tr>';
    }

    /** @param array<string, mixed> $b */
    private function text(array $b): string
    {
        return $this->esc((string) ($b['text'] ?? ''));
    }

    /** @param array<string, mixed> $b */
    private function align(array $b): string
    {
        return in_array($b['align'] ?? '', ['center', 'right'], true) ? (string) $b['align'] : 'left';
    }

    /** @param array<string, mixed> $b */
    private function int(array $b, string $key, int $fallback): int
    {
        $v = (int) ($b[$key] ?? $fallback);

        return $v > 0 && $v < 400 ? $v : $fallback;
    }

    /**
     * A block's link or picture address, only when its scheme is one a
     * newsletter has any business with; anything else (`javascript:`,
     * `data:`) becomes `#`. Escaping alone keeps the quotes closed but not the
     * scheme out, and the blocks are stored as the composer sent them.
     *
     * @param  list<string>  $schemes
     */
    private function link(string $url, array $schemes): string
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $url !== '' && in_array($scheme, $schemes, true) ? $url : '#';
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
