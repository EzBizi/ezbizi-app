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
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array{company: string, address: string, unsubscribe_url: string}  $footer
     */
    public function render(array $blocks, array $footer): string
    {
        $body = implode('', array_map(fn (array $b) => $this->block($b), $blocks));

        return '<!DOCTYPE html>'
            .'<html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            // Columns stack on a phone. The inline widths stay for clients that strip <style>.
            .'<style>@media only screen and (max-width:620px){'
            .'.ez-col{display:block!important;width:100%!important;max-width:100%!important;box-sizing:border-box;}'
            .'}</style>'
            .'</head>'
            .'<body style="margin:0;padding:0;background:#EDF1F4;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            .'style="background:#EDF1F4;padding:18px 0;"><tr><td align="center">'
            .'<table role="presentation" width="'.self::WIDTH.'" cellpadding="0" cellspacing="0" '
            .'style="width:'.self::WIDTH.'px;max-width:100%;background:#ffffff;'
            .'border-radius:6px;overflow:hidden;font-family:system-ui,-apple-system,'
            .'\'Segoe UI\',sans-serif;">'
            .$body
            .$this->footer($footer)
            .'</table></td></tr></table></body></html>';
    }

    /** @param array<string, mixed> $b */
    private function block(array $b): string
    {
        $type = (string) ($b['type'] ?? '');

        // A block somebody hand-edited in HTML mode. Kept exactly as written —
        // rebuilding it from fields is the one thing that would lose their work.
        if (isset($b['html']) && is_string($b['html']) && $b['html'] !== '') {
            return $this->row($b['html']);
        }

        return match ($type) {
            'heading'  => $this->row('<h1 style="margin:0;font-size:'
                            .$this->int($b, 'size', 24).'px;line-height:1.25;color:#060808;'
                            .'text-align:'.$this->align($b).';">'.$this->text($b).'</h1>'),
            'text'     => $this->row('<p style="margin:0;font-size:15px;line-height:1.55;'
                            .'color:#3a4650;text-align:'.$this->align($b).';">'
                            .nl2br($this->text($b)).'</p>'),
            'image'    => $this->row('<img src="'.$this->esc($this->link((string) ($b['src'] ?? ''), ['http', 'https']))
                            .'" alt="'.$this->esc((string) ($b['alt'] ?? ''))
                            .'" width="'.(self::WIDTH - 40).'" style="display:block;width:100%;'
                            .'max-width:100%;height:auto;border:0;">'),
            'button'   => $this->row('<table role="presentation" cellpadding="0" cellspacing="0" '
                            .'align="'.$this->align($b).'"><tr><td style="background:#00708F;'
                            .'border-radius:6px;"><a href="'.$this->esc($this->link((string) ($b['url'] ?? ''), ['http', 'https', 'mailto', 'tel']))
                            .'" style="display:inline-block;padding:11px 20px;color:#ffffff;'
                            .'text-decoration:none;font-weight:600;font-size:15px;">'
                            .$this->text($b).'</a></td></tr></table>'),
            'divider'  => '<tr><td style="padding:8px 20px;"><table role="presentation" width="100%"'
                            .' cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid'
                            .' #E9EDF1;font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>',
            'spacer'   => '<tr><td style="height:'.$this->int($b, 'height', 16).'px;font-size:0;'
                            .'line-height:0;">&nbsp;</td></tr>',
            'columns'  => $this->columns($b),
            default    => '',
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
     * @param array<string, mixed> $b
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

        $width = (int) floor(100 / count($columns));

        $cells = implode('', array_map(function (array $column) use ($width) {
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

            return '<td class="ez-col" width="'.$width.'%" valign="top" '
                .'style="width:'.$width.'%;"><table role="presentation" width="100%" '
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

    private function row(string $inner): string
    {
        return '<tr><td style="padding:14px 20px;">'.$inner.'</td></tr>';
    }

    /**
     * The footer, and the unsubscribe link is not optional.
     *
     * Marketing mail without a way out is unlawful in every market this product
     * sells into, so it is rendered here rather than being a block somebody can
     * delete. A tenant can style the rest; they cannot remove this.
     *
     * @param array{company: string, address: string, unsubscribe_url: string} $f
     */
    private function footer(array $f): string
    {
        return '<tr><td style="padding:16px 20px;background:#F5F8FA;text-align:center;'
            .'font-size:11.5px;line-height:1.5;color:#5B6672;">'
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
