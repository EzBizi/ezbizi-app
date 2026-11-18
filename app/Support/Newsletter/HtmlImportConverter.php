<?php

namespace App\Support\Newsletter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Imported HTML, read for what email blocks can keep (newsletter P1c).
 *
 * Deliberately a small, honest subset: h1-h3 become headings, paragraphs and
 * lists become text (bold, italic and http/mailto/tel links kept), pictures
 * become image blocks with their alt text and link, a button-styled link or a
 * table cell holding one link becomes a button, `hr` a divider, and a one-row
 * table of two or three cells becomes columns. Anything else is kept as a
 * hand-written HTML block (it sends as written, it is not editable as
 * fields), and what cannot be carried at all (stylesheets, wrapper
 * backgrounds) is listed under `lost` so the composer can refuse to apply
 * until somebody accepts the losses.
 *
 * Nothing here saves; nothing here throws on malformed HTML. Bad input comes
 * back as issues.
 */
final class HtmlImportConverter
{
    public const MAX_BYTES = 1024 * 1024;

    public const UNSAFE = '/<\s*script\b|<[^>]*\son[a-z]+\s*=|javascript\s*:/i';

    public const UNSAFE_MESSAGE = 'This HTML contains scripts, which email cannot run and Mailchimp refuses. '
        .'Remove them and import it again.';

    /** Containers whose children are read one by one. */
    private const WRAPPERS = ['html', 'body', 'div', 'center', 'section', 'article', 'main', 'header', 'footer',
        'tbody', 'thead', 'tfoot', 'tr', 'td', 'th', 'span', 'font', 'blockquote', 'aside', 'nav', 'figure'];

    /** Elements that end an inline run. */
    private const BLOCKISH = ['p', 'div', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'img', 'hr',
        'center', 'section', 'article', 'header', 'footer', 'blockquote', 'form', 'iframe', 'video', 'audio',
        'object', 'embed', 'svg', 'canvas', 'figure', 'main', 'nav', 'aside', 'tr', 'td'];

    /** Elements inboxes do not run. */
    private const INBOX_UNSUPPORTED = ['form', 'input', 'select', 'textarea', 'button', 'iframe', 'video', 'audio',
        'object', 'embed', 'svg', 'canvas'];

    /** @var list<array{kind: string, message: string}> */
    private array $issues = [];

    /** @var list<string> */
    private array $lost = [];

    private int $preserved = 0;

    private bool $styleDropped = false;

    public static function unsafe(string $html): bool
    {
        return preg_match(self::UNSAFE, $html) === 1;
    }

    /**
     * @return array{blocks: list<array<string, mixed>>, issues: list<array{kind: string, message: string}>, lost: list<string>, preserved: int}
     */
    public function convert(string $html): array
    {
        $this->reset();
        $doc = $this->load($html);
        $body = $doc?->getElementsByTagName('body')->item(0);
        if ($body instanceof DOMElement) {
            $this->noteBackground($body);
        }
        if (($doc?->getElementsByTagName('style')->length ?? 0) > 0) {
            $this->styleDropped = true;
            $this->lost[] = 'The stylesheet (<style>): blocks carry their own styling, so class-based styles are not kept.';
        }
        $blocks = $body === null ? [] : $this->children($body);

        if ($blocks === []) {
            $this->issue('nothing', 'Nothing in this HTML could become blocks.');
        }

        return ['blocks' => $blocks, 'issues' => $this->issues, 'lost' => array_values(array_unique($this->lost)), 'preserved' => $this->preserved];
    }

    /**
     * Issues for HTML kept as it is: unreachable pictures and elements
     * inboxes ignore. Keeping loses nothing, so `lost` is always empty.
     *
     * @return list<array{kind: string, message: string}>
     */
    public function keepIssues(string $html): array
    {
        $this->reset();
        $doc = $this->load($html);
        if ($doc === null || trim($doc->textContent) === '' && $doc->getElementsByTagName('img')->length === 0) {
            $this->issue('nothing', 'This HTML has nothing in it an inbox would show.');
        }
        foreach ($doc?->getElementsByTagName('img') ?? [] as $img) {
            $this->checkImage((string) $img->getAttribute('src'), (string) $img->getAttribute('alt'), $img->hasAttribute('alt'));
        }
        foreach (self::INBOX_UNSUPPORTED as $tag) {
            if (($doc?->getElementsByTagName($tag)->length ?? 0) > 0) {
                $this->issue('unsupported', "<{$tag}> does not work in most inboxes.");
            }
        }

        return $this->issues;
    }

    private function reset(): void
    {
        $this->issues = [];
        $this->lost = [];
        $this->preserved = 0;
        $this->styleDropped = false;
    }

    private function load(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $errors = count(libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok) {
            $this->issue('malformed', 'This HTML could not be read.');

            return null;
        }
        if ($errors > 0) {
            $this->issue('malformed', 'Some of this HTML is malformed (unclosed or misplaced tags); it was read as a browser would.');
        }

        return $doc;
    }

    /** @return list<array<string, mixed>> */
    private function children(DOMNode $parent): array
    {
        $out = [];
        $run = [];
        $flush = function () use (&$run, &$out) {
            if ($run !== []) {
                $text = $this->inlineOf($run);
                if (trim(strip_tags($text['text'])) !== '') {
                    $out[] = ['type' => 'text', 'text' => trim($text['text'])] + ($text['rich'] ? ['rich' => true] : []);
                }
                $run = [];
            }
        };

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMText || ($node instanceof DOMElement && ! $this->isBlockish($node) && $this->isInline($node))) {
                $run[] = $node;

                continue;
            }
            $flush();
            if ($node instanceof DOMElement) {
                array_push($out, ...$this->element($node));
            }
        }
        $flush();

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function element(DOMElement $el): array
    {
        $tag = strtolower($el->tagName);
        $this->noteBackground($el);

        switch ($tag) {
            case 'head':
            case 'title':
            case 'meta':
            case 'link':
                return [];
            case 'style':
                if (! $this->styleDropped) {
                    $this->styleDropped = true;
                    $this->lost[] = 'The stylesheet (<style>): blocks carry their own styling, so class-based styles are not kept.';
                }

                return [];
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $text = $this->squash($el->textContent);

                return $text === '' ? [] : [array_filter([
                    'type' => 'heading',
                    'text' => $text,
                    'size' => ['h1' => 28, 'h2' => 22, 'h3' => 18][$tag] ?? 16,
                    'align' => $this->alignOf($el),
                ], fn ($v) => $v !== null)];
            case 'p':
                if (($button = $this->buttonOf($el, false)) !== null) {
                    return [$button];
                }
                if ($this->hasBlockChild($el)) {
                    return $this->children($el);
                }
                $text = $this->inlineOf(iterator_to_array($el->childNodes));
                if (trim(strip_tags($text['text'])) === '') {
                    return [];
                }

                return [array_filter(['type' => 'text', 'text' => trim($text['text']), 'rich' => $text['rich'] ?: null,
                    'align' => $this->alignOf($el)], fn ($v) => $v !== null)];
            case 'ul':
            case 'ol':
                $items = [];
                foreach ($el->childNodes as $li) {
                    if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                        $items[] = '<li>'.trim($this->inlineOf(iterator_to_array($li->childNodes))['text']).'</li>';
                    }
                }
                if ($tag === 'ol') {
                    $this->issue('note', 'A numbered list became a bulleted list.');
                }

                return $items === [] ? [] : [['type' => 'text', 'text' => '<ul>'.implode('', $items).'</ul>', 'rich' => true]];
            case 'img':
                return [$this->image($el, null)];
            case 'a':
                $img = $this->onlyChild($el, 'img');
                if ($img !== null) {
                    return [$this->image($img, $this->href($el))];
                }
                if (($button = $this->buttonOf($el, true)) !== null) {
                    return [$button];
                }
                $text = $this->inlineOf([$el]);

                return trim(strip_tags($text['text'])) === '' ? [] : [['type' => 'text', 'text' => trim($text['text']), 'rich' => true]];
            case 'hr':
                return [['type' => 'divider']];
            case 'br':
                return [];
            case 'table':
                return $this->table($el);
        }

        if (in_array($tag, ['td', 'th'], true) && ($button = $this->buttonOf($el, false)) !== null) {
            return [$button];
        }
        if (in_array($tag, self::WRAPPERS, true)) {
            return $this->children($el);
        }

        return [$this->preserve($el, $tag)];
    }

    /** @return list<array<string, mixed>> */
    private function table(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $name = strtolower($child->tagName);
            if ($name === 'tr') {
                $rows[] = $child;
            } elseif (in_array($name, ['tbody', 'thead', 'tfoot'], true)) {
                foreach ($child->childNodes as $tr) {
                    if ($tr instanceof DOMElement && strtolower($tr->tagName) === 'tr') {
                        $rows[] = $tr;
                    }
                }
            }
        }
        $cells = array_map(fn (DOMElement $tr) => array_values(array_filter(
            iterator_to_array($tr->childNodes),
            fn ($c) => $c instanceof DOMElement && in_array(strtolower($c->tagName), ['td', 'th'], true),
        )), $rows);

        $this->noteBackground($table);

        // A layout table: every row one cell, read as the cells' content in order.
        if ($cells !== [] && max(array_map('count', $cells)) <= 1) {
            $out = [];
            foreach ($cells as $row) {
                foreach ($row as $cell) {
                    array_push($out, ...$this->element($cell));
                }
            }

            return $out;
        }

        // One row of two or three cells: columns.
        if (count($cells) === 1 && in_array(count($cells[0]), [2, 3], true)) {
            $columns = [];
            foreach ($cells[0] as $cell) {
                $this->noteBackground($cell);
                $columns[] = ['blocks' => $this->children($cell)];
            }
            if (array_filter($columns, fn ($c) => $c['blocks'] !== []) !== []) {
                return [['type' => 'columns', 'columns' => $columns]];
            }

            return [];
        }

        $width = $cells === [] ? 0 : max(array_map('count', $cells));
        $this->issue('preserved', "A table with {$width} columns and ".count($cells).' rows was kept as HTML: it sends as written but cannot be edited as blocks.');
        $this->preserved++;

        return [['type' => 'text', 'html' => (string) $table->ownerDocument?->saveHTML($table)]];
    }

    /** @return array<string, mixed> */
    private function preserve(DOMElement $el, string $tag): array
    {
        $this->preserved++;
        if (in_array($tag, self::INBOX_UNSUPPORTED, true)) {
            $this->issue('unsupported', "<{$tag}> does not work in most inboxes; it was kept as HTML.");
        } else {
            $this->issue('preserved', "<{$tag}> was kept as HTML: it sends as written but cannot be edited as blocks.");
        }

        return ['type' => 'text', 'html' => (string) $el->ownerDocument?->saveHTML($el)];
    }

    /** @return array<string, mixed> */
    private function image(DOMElement $img, ?string $href): array
    {
        $src = trim((string) $img->getAttribute('src'));
        $alt = trim((string) $img->getAttribute('alt'));
        $this->checkImage($src, $alt, $img->hasAttribute('alt'));
        $width = (int) $img->getAttribute('width');

        return array_filter([
            'type' => 'image',
            'src' => $src,
            'alt' => $alt,
            'href' => $href,
            'width' => $width > 0 && $width <= 600 ? $width : null,
            'decorative' => $img->hasAttribute('alt') && $alt === '' ? true : null,
        ], fn ($v) => $v !== null);
    }

    private function checkImage(string $src, string $alt, bool $hasAlt): void
    {
        $src = trim($src);
        if ($src === '') {
            $this->issue('asset', 'A picture has no address, so it will not show.');
        } elseif (! str_starts_with(strtolower($src), 'https://')) {
            $this->issue('asset', 'A picture is not on a secure https:// web address, so many inboxes will not load it: '
                .mb_substr($src, 0, 120));
        }
        if (! $hasAlt) {
            $this->issue('alt', 'A picture has no alt text'.($src !== '' ? ' ('.mb_substr(basename((string) parse_url($src, PHP_URL_PATH)), 0, 60).')' : '')
                .'; inboxes that hide pictures will say nothing in its place.');
        }
    }

    /** A link styled as a button, or the one link a cell or paragraph holds. */
    private function buttonOf(DOMElement $el, bool $isLink): ?array
    {
        $a = $isLink ? $el : $this->onlyChild($el, 'a');
        if ($a === null || $this->onlyChild($a, 'img') !== null) {
            return null;
        }
        $label = $this->squash($a->textContent);
        if ($label === '' || mb_strlen($label) > 60) {
            return null;
        }
        $style = strtolower($a->getAttribute('style').' '.($isLink ? '' : $el->getAttribute('style')).' '.$el->getAttribute('bgcolor'));
        $buttonish = str_contains($style, 'background') || str_contains($style, 'padding')
            || str_contains($style, 'border-radius') || str_contains($style, 'inline-block') || trim($el->getAttribute('bgcolor')) !== '';
        $cell = ! $isLink && in_array(strtolower($el->tagName), ['td', 'th'], true);
        if (! $buttonish && ! $cell) {
            return null;
        }

        $button = ['type' => 'button', 'text' => $label, 'url' => $this->href($a) ?? ''];
        if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-f]{6})/i', $a->getAttribute('style').' '.($isLink ? '' : $el->getAttribute('style')), $m)) {
            $button['background'] = $m[1];
        } elseif (preg_match('/^#[0-9a-f]{6}$/i', trim($el->getAttribute('bgcolor')))) {
            $button['background'] = trim($el->getAttribute('bgcolor'));
        }
        if (($align = $this->alignOf($el)) !== null) {
            $button['align'] = $align;
        }

        return $button;
    }

    /** The one element child (ignoring blank text) when it is a [tag], else null. */
    private function onlyChild(DOMElement $el, string $tag): ?DOMElement
    {
        $found = null;
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMText && trim($c->textContent) === '') {
                continue;
            }
            if ($c instanceof DOMElement && $found === null && strtolower($c->tagName) === $tag) {
                $found = $c;

                continue;
            }
            if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['span', 'font', 'strong', 'b'], true) && $found === null) {
                $inner = $this->onlyChild($c, $tag);
                if ($inner !== null) {
                    $found = $inner;

                    continue;
                }
            }

            return null;
        }

        return $found;
    }

    /**
     * An inline run as block text: bold, italic and links kept, other tags
     * reduced to their words.
     *
     * @param  list<DOMNode>  $nodes
     * @return array{text: string, rich: bool}
     */
    private function inlineOf(array $nodes): array
    {
        $rich = false;
        $styled = false;
        $walk = function (DOMNode $n) use (&$walk, &$rich, &$styled): string {
            if ($n instanceof DOMText) {
                return (string) preg_replace('/\s+/u', ' ', $n->textContent);
            }
            if (! $n instanceof DOMElement) {
                return '';
            }
            $tag = strtolower($n->tagName);
            $inner = '';
            foreach ($n->childNodes as $c) {
                $inner .= $walk($c);
            }
            if ($tag === 'br') {
                return "\n";
            }
            if (in_array($tag, ['b', 'strong', 'i', 'em'], true)) {
                $rich = true;

                return "<{$tag}>{$inner}</{$tag}>";
            }
            if ($tag === 'a') {
                $href = $this->href($n);
                if ($href === null) {
                    return $inner;
                }
                $rich = true;

                return '<a href="'.str_replace('"', '%22', $href).'">'.$inner.'</a>';
            }
            if ($n->hasAttribute('style') || in_array($tag, ['font', 'u', 'small', 'big', 'sup', 'sub', 'mark'], true)) {
                $styled = true;
            }

            return $inner;
        };

        $text = '';
        foreach ($nodes as $n) {
            $text .= $walk($n);
        }
        if ($styled) {
            $this->issue('note', 'Some inline styling (colours, fonts, underlines) was not kept; the brand styles the text instead.');
        }

        return ['text' => (string) preg_replace("/ *\n */", "\n", $text), 'rich' => $rich];
    }

    private function href(DOMElement $a): ?string
    {
        $href = trim($a->getAttribute('href'));
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return $href !== '' && in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $href : null;
    }

    private function alignOf(DOMElement $el): ?string
    {
        $align = strtolower(trim($el->getAttribute('align')));
        if (preg_match('/text-align\s*:\s*(left|center|right)/i', $el->getAttribute('style'), $m)) {
            $align = strtolower($m[1]);
        }

        return in_array($align, ['center', 'right'], true) ? $align : null;
    }

    private function noteBackground(DOMElement $el): void
    {
        if (trim($el->getAttribute('background')) !== '' || preg_match('/background(-image)?\s*:\s*url\(/i', $el->getAttribute('style'))) {
            $this->lost[] = 'Background pictures behind sections.';
        } elseif (in_array(strtolower($el->tagName), ['body', 'table', 'td', 'div', 'center'], true)
            && (trim($el->getAttribute('bgcolor')) !== '' || preg_match('/background(-color)?\s*:\s*#/i', $el->getAttribute('style')))
            && $this->onlyChild($el, 'a') === null) {
            $this->lost[] = 'Background colours behind sections (the brand colours are used instead).';
        }
    }

    private function hasBlockChild(DOMElement $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && $this->isBlockish($c)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockish(DOMElement $el): bool
    {
        return in_array(strtolower($el->tagName), self::BLOCKISH, true) || in_array(strtolower($el->tagName), ['style', 'head', 'meta', 'link', 'title', 'h1', 'h2', 'h3'], true);
    }

    /** Inline when it holds no block-level element anywhere inside. */
    private function isInline(DOMElement $el): bool
    {
        if (in_array(strtolower($el->tagName), ['a'], true) && $this->onlyChild($el, 'img') !== null) {
            return false;
        }
        if (strtolower($el->tagName) === 'a' && $this->buttonOf($el, true) !== null) {
            return false;
        }
        foreach ($el->getElementsByTagName('*') as $d) {
            if ($this->isBlockish($d)) {
                return false;
            }
        }

        return true;
    }

    private function squash(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private function issue(string $kind, string $message): void
    {
        foreach ($this->issues as $i) {
            if ($i['message'] === $message) {
                return;
            }
        }
        $this->issues[] = ['kind' => $kind, 'message' => $message];
    }
}
