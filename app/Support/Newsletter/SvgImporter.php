<?php

namespace App\Support\Newsletter;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;

/**
 * An SVG design, split into what email can keep editable and what it cannot.
 *
 * Audit 2026-11-10, item 5, and deliberately a limited subset: `<text>` becomes
 * a heading or text block, an `<a>` with words in it becomes a button, and
 * everything else (shapes, gradients, filters, masks, embedded pictures) is
 * the ARTWORK. Inboxes do not draw SVG reliably, so the artwork goes back to
 * the composer as an SVG with the text taken out, which the composer
 * rasterises to PNG and stores; the image block carries alt text from the
 * SVG's `<title>` so a client that blocks images still says what was there.
 *
 * Nothing here promises arbitrary SVG-to-editable-HTML fidelity, and the
 * warnings say what was flattened.
 */
final class SvgImporter
{
    public const MAX_BYTES = 512 * 1024;

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** Elements that flatten into the artwork with a note. */
    private const FLATTENED = ['filter', 'mask', 'clipPath', 'pattern', 'textPath', 'use'];

    /**
     * @return array{blocks: list<array<string, mixed>>, artwork_svg: ?string, alt: string, warnings: list<string>}
     */
    public function import(string $svg): array
    {
        $this->refuseUnlessSafe($svg);

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: nothing is fetched while parsing. No entity substitution flag is passed.
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->documentElement;
        if (! $loaded || $root === null || $root->localName !== 'svg') {
            $this->refuse('That file is not an SVG this can read.');
        }

        $this->refuseUnsafeNodes($doc);

        $xpath = new DOMXPath($doc);
        $alt = trim((string) $xpath->evaluate('string(/*[local-name()="svg"]/*[local-name()="title"][1])'));

        $blocks = [];
        $warnings = [];
        $editable = [];

        foreach ($xpath->query('//*[local-name()="text" or local-name()="a"]') as $node) {
            /** @var DOMElement $node */
            if ($this->hasAncestor($node, ['a', 'defs', 'clipPath', 'mask', 'pattern'])
                || ($node->localName === 'text' && $this->hasAncestor($node, ['a']))) {
                continue;
            }

            $words = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            if ($words === '') {
                continue;
            }

            if ($node->localName === 'a') {
                $url = trim($node->getAttribute('href') ?: $node->getAttributeNS(self::XLINK_NS, 'href'));
                $blocks[] = ['type' => 'button', 'text' => $words, 'url' => $url, 'align' => 'center'];
            } else {
                $size = (float) ($node->getAttribute('font-size') ?: $this->styleSize($node->getAttribute('style')));
                $blocks[] = $size >= 20
                    ? ['type' => 'heading', 'text' => $words, 'size' => (int) min(max(round($size), 20), 48), 'align' => 'center']
                    : ['type' => 'text', 'text' => $words, 'align' => 'center'];
            }

            if ($node->getElementsByTagName('textPath')->length > 0) {
                $warnings[] = 'Text on a path became plain text.';
            }
            $editable[] = $node;
        }

        foreach ($editable as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (self::FLATTENED as $tag) {
            if ($xpath->query('//*[local-name()="'.$tag.'"]')->length > 0) {
                $warnings[] = "The design uses <{$tag}>, which email cannot draw, so it is part of the picture.";
            }
        }
        if (preg_match('/font-family/i', $svg)) {
            $warnings[] = 'Custom fonts are not carried over; editable text uses the email font.';
        }

        $drawn = $xpath->query('//*[local-name()="path" or local-name()="rect" or local-name()="circle"'
            .' or local-name()="ellipse" or local-name()="line" or local-name()="polyline"'
            .' or local-name()="polygon" or local-name()="image" or local-name()="text" or local-name()="use"]');
        $artwork = $drawn->length > 0 ? $doc->saveXML($root) : null;

        return [
            'blocks' => $blocks,
            'artwork_svg' => $artwork === false ? null : $artwork,
            'alt' => $alt !== '' ? mb_substr($alt, 0, 200) : 'Newsletter artwork',
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** Checks on the raw text, before any parser sees it. */
    private function refuseUnlessSafe(string $svg): void
    {
        if (trim($svg) === '') {
            $this->refuse('That SVG is empty.');
        }
        if (strlen($svg) > self::MAX_BYTES) {
            $this->refuse('That SVG is larger than 512 KB. Simplify or compress it and try again.');
        }
        // A DTD can define entities that expand or reach files; an SVG has no need of one.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            $this->refuse('That SVG declares a DOCTYPE or entities, which this does not accept.');
        }
    }

    private function refuseUnsafeNodes(DOMDocument $doc): void
    {
        $xpath = new DOMXPath($doc);

        if ($xpath->query('//*[local-name()="script" or local-name()="foreignObject"'
            .' or local-name()="iframe" or local-name()="object" or local-name()="embed"]')->length > 0) {
            $this->refuse('That SVG contains scripts or embedded pages. Remove them and try again.');
        }

        foreach ($xpath->query('//@*') as $attr) {
            $name = strtolower($attr->localName ?? '');
            $value = trim((string) $attr->value);
            $owner = $attr->parentNode instanceof DOMElement ? $attr->parentNode->localName : '';

            if (str_starts_with($name, 'on')) {
                $this->refuse('That SVG contains scripts (an “'.$attr->nodeName.'” handler). Remove them and try again.');
            }
            if (preg_match('/javascript\s*:/i', $value)) {
                $this->refuse('That SVG contains a javascript: link. Remove it and try again.');
            }
            if ($name === 'href' || $name === 'src') {
                $this->refuseExternal($value, $owner);
            }
            if (preg_match_all('/url\(\s*["\']?([^"\')]*)/i', $value, $m)) {
                foreach ($m[1] as $ref) {
                    $this->refuseExternal(trim($ref), 'url');
                }
            }
        }

        foreach ($xpath->query('//*[local-name()="style"]') as $style) {
            if (preg_match('/@import|url\(\s*["\']?(?!#|data:image\/)/i', $style->textContent)) {
                $this->refuse('That SVG loads styles or fonts from elsewhere. Embed or remove them and try again.');
            }
        }
    }

    /**
     * A reference inside the SVG is fine; one the inbox or this server would
     * have to fetch is not. Links on `<a>` are the exception: they become
     * button addresses, which is what links in an email are.
     */
    private function refuseExternal(string $value, string $owner): void
    {
        if ($value === '' || str_starts_with($value, '#')
            || preg_match('/^data:image\/(png|jpe?g|gif);base64,/i', $value)) {
            return;
        }
        if ($owner === 'a' && preg_match('/^(https?:|mailto:|tel:)/i', $value)) {
            return;
        }

        $this->refuse('That SVG refers to a file outside itself ('.mb_substr($value, 0, 80).'). '
            .'Embed the picture in the SVG, or remove the reference, and try again.');
    }

    /** @param list<string> $tags */
    private function hasAncestor(DOMElement $node, array $tags): bool
    {
        for ($p = $node->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            if (in_array($p->localName, $tags, true)) {
                return true;
            }
        }

        return false;
    }

    private function styleSize(string $style): string
    {
        return preg_match('/font-size\s*:\s*([0-9.]+)/i', $style, $m) ? $m[1] : '0';
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['svg' => $message]);
    }
}
