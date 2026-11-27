<?php

namespace App\Support\Newsletter;

use App\Support\Files\FileStore;

/**
 * Preflight for pictures (newsletter P0, 2026-11-17): every image a recipient
 * is sent must load in their inbox. That means an https address, and, for a
 * picture EzBizi hosts, a public artwork file that still exists. A library or
 * editor address (behind a login) is refused here, because the inbox has none.
 *
 * Each problem names where it is, "Block 3 (image)", so the person fixing it
 * does not have to hunt.
 */
final class NewsletterImages
{
    private const ARTWORK = '#^/api/v1/newsletter/artwork/([A-Za-z0-9-]{1,64})/([A-Za-z0-9]{40}\.(?:png|jpg|gif|webp))$#';

    public function __construct(private readonly FileStore $files) {}

    /**
     * @param  array<int, mixed>  $blocks
     * @return list<string>
     */
    public function problems(array $blocks, ?string $htmlOverride): array
    {
        $problems = [];

        if (is_string($htmlOverride) && $htmlOverride !== '') {
            foreach ($this->sourcesIn($htmlOverride) as $n => $src) {
                if (($why = $this->why($src)) !== null) {
                    $problems[] = 'Custom HTML picture '.($n + 1).' '.$why;
                }
            }

            return $problems;
        }

        foreach (array_values($blocks) as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach ($this->sourcesOf($block) as [$where, $src]) {
                if (($why = $this->why($src)) !== null) {
                    $problems[] = 'Block '.($i + 1).' ('.$where.') '.$why;
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array{0: string, 1: string}>
     */
    private function sourcesOf(array $block, string $prefix = ''): array
    {
        if (isset($block['html']) && is_string($block['html']) && $block['html'] !== '') {
            return array_map(fn (string $s) => [$prefix.'HTML', $s], $this->sourcesIn($block['html']));
        }

        $type = (string) ($block['type'] ?? '');
        if ($type === 'image') {
            return [[$prefix.'image', trim((string) ($block['src'] ?? ''))]];
        }
        if ($type !== 'columns') {
            return [];
        }

        $found = [];
        foreach (array_values((array) ($block['columns'] ?? [])) as $c => $column) {
            $children = is_array($column) && isset($column['blocks']) && is_array($column['blocks'])
                ? $column['blocks'] : (is_array($column) ? $column : []);
            foreach ($children as $child) {
                if (is_array($child)) {
                    $found = [...$found, ...$this->sourcesOf($child, 'column '.($c + 1).' ')];
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function sourcesIn(string $html): array
    {
        preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']*)["\']/i', $html, $m);

        return array_map(fn (string $s) => trim(html_entity_decode($s)), $m[1]);
    }

    /** Why a recipient could not load this picture, or null when they can. */
    private function why(string $src): ?string
    {
        if ($src === '') {
            return 'has no picture. Upload or choose one, or remove the block.';
        }
        if (preg_match('/^\*\|[A-Z_]+\|\*$/', $src)) {
            return null;
        }
        if (strtolower((string) parse_url($src, PHP_URL_SCHEME)) !== 'https') {
            return 'is not a secure https address, so many inboxes will not show it.';
        }

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appHost === '' || strtolower((string) parse_url($src, PHP_URL_HOST)) !== $appHost) {
            return null;
        }

        $path = (string) parse_url($src, PHP_URL_PATH);
        if (preg_match(self::ARTWORK, $path, $m) !== 1) {
            return str_starts_with($path, '/api/')
                ? 'points at an EzBizi address that needs a login. Choose the picture again.'
                : null;
        }

        return $this->files->exists($this->files->directoryFor('newsletter-artwork', $m[1]).'/'.$m[2])
            ? null
            : 'points at a picture that no longer exists. Upload or choose it again.';
    }
}
