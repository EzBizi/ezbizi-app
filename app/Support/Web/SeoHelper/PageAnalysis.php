<?php

namespace App\Support\Web\SeoHelper;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * One page, checked the way a search engine will read it.
 *
 * The familiar checks (keyphrase in the title, description length, text
 * length, subheadings, images, links, sentence and paragraph length, ease of
 * reading) — with two differences that are the point of building it:
 *
 *  - It reads the LIVE page, the HTML a search engine gets, not the editor's
 *    text. Most of these sites are built in Elementor, whose text never reaches
 *    the editor box a WordPress SEO plugin reads.
 *  - It knows the rest of the site: a keyphrase another page already targets,
 *    and a title or description another page repeats, are problems no
 *    single-page check can see.
 *
 * Pure: every input is passed in, so each check is a unit.
 */
final class PageAnalysis
{
    public const GOOD = 'good';

    public const OK = 'ok';

    public const BAD = 'bad';

    /**
     * Bumped when a rule changes, so a stored or cached result can be told from
     * a current one. The overview and the page view run the same rules.
     */
    public const RULES = 2;

    /** Checks bad enough that nothing else on the page can make it look healthy. */
    private const BLOCKERS = ['title_malformed', 'description_malformed'];

    /** Checks read from the live page, which the overview never fetches. */
    private const LIVE = ['live', 'h1', 'text_length', 'intro_keyphrase', 'subheading_keyphrase', 'density', 'images', 'internal_links', 'outbound_links'];

    /** The elements a run of text belongs to. */
    private const BLOCKS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'div', 'td', 'th', 'blockquote', 'figcaption', 'dd', 'dt', 'section', 'article', 'body', 'main'];

    /** Words a keyphrase match ignores: "chimney repair in newark" matches "Chimney repair, Newark". */
    private const STOP = ['a', 'an', 'the', 'in', 'on', 'of', 'for', 'to', 'and', 'or', 'near', 'me', 'with', 'at', 'by', 'your', 'our'];

    /**
     * @param  array{title: string, description: string, keyphrase: string, slug: string, noindex?: bool, canonical?: string, url?: string}  $page
     *                                                                                                                                              the FINAL title and description, as printed
     * @param  array{keyphrase?: int, title?: int, description?: int}  $shared  how many OTHER pages share each
     * @return array{score: string, seo: list<array{key: string, status: string, text: string}>, readability: list<array{key: string, status: string, text: string}>, words: int}
     */
    public static function run(array $page, ?string $html, array $shared = []): array
    {
        $key = trim($page['keyphrase']);
        $words = self::words($key);
        $content = $html === null ? null : self::content($html, (string) ($page['url'] ?? ''));
        $seo = [];

        // ── The keyphrase and where it appears ──
        if ($key === '') {
            $seo[] = self::check('keyphrase', self::BAD, 'Set a focus keyphrase: the search this page should be found for.');
        } else {
            $seo[] = count(self::words($key, false)) > 4
                ? self::check('keyphrase', self::OK, 'The keyphrase is long. Four words or fewer is what people type.')
                : self::check('keyphrase', self::GOOD, 'The keyphrase is set.');

            if (($shared['keyphrase'] ?? 0) > 0) {
                $n = $shared['keyphrase'];
                $seo[] = self::check('keyphrase_unique', self::BAD, "{$n} other ".($n === 1 ? 'page targets' : 'pages target').' the same keyphrase, so they compete with each other in search. Give each page its own.');
            } else {
                $seo[] = self::check('keyphrase_unique', self::GOOD, 'No other page targets this keyphrase.');
            }

            $seo[] = match (true) {
                self::starts($page['title'], $words) => self::check('title_keyphrase', self::GOOD, 'The title begins with the keyphrase.'),
                self::has($page['title'], $words) => self::check('title_keyphrase', self::OK, 'The keyphrase is in the title. Moving it to the start helps.'),
                default => self::check('title_keyphrase', self::BAD, 'Put the keyphrase in the search title.'),
            };
            $seo[] = self::has($page['description'], $words)
                ? self::check('description_keyphrase', self::GOOD, 'The description mentions the keyphrase.')
                : self::check('description_keyphrase', self::BAD, 'Mention the keyphrase in the description; search engines bold it there.');
            $seo[] = self::has(str_replace('-', ' ', $page['slug']), $words)
                ? self::check('slug_keyphrase', self::GOOD, 'The page address contains the keyphrase.')
                : self::check('slug_keyphrase', self::OK, 'The page address does not contain the keyphrase.');
        }

        // ── Title and description as they print ──
        $t = mb_strlen($page['title']);
        $seo[] = match (true) {
            self::malformed($page['title']) => self::check('title_malformed', self::BAD, 'The title prints as "'.$page['title'].'", which is not a title. Write one for this page.'),
            $t === 0 => self::check('title_length', self::BAD, 'This page has no search title.'),
            $t < 30 => self::check('title_length', self::OK, "The title is short ({$t} characters). There is room to say more."),
            $t <= 60 => self::check('title_length', self::GOOD, "The title is a good length ({$t} characters)."),
            $t <= 70 => self::check('title_length', self::OK, "The title may be cut off in results ({$t} characters; about 60 show)."),
            default => self::check('title_length', self::BAD, "The title will be cut off in results ({$t} characters; about 60 show)."),
        };
        $d = mb_strlen($page['description']);
        $seo[] = match (true) {
            // A lone "%" printed on 257 pages before anyone saw it (Rodrigo's audit, 2026-10-09).
            self::malformed($page['description']) => self::check('description_malformed', self::BAD, 'The description prints as "'.$page['description'].'", which is not a description. Write one for this page.'),
            $d === 0 => self::check('description_length', self::BAD, 'Write a description, or search engines pick a sentence from the page themselves.'),
            $d < 70 => self::check('description_length', self::BAD, "The description is short ({$d} characters). Aim for 120 to 156."),
            $d < 120 => self::check('description_length', self::OK, "The description could be longer ({$d} characters). Aim for 120 to 156."),
            $d <= 156 => self::check('description_length', self::GOOD, "The description is a good length ({$d} characters)."),
            $d <= 170 => self::check('description_length', self::OK, "The description may be cut off ({$d} characters; about 156 show)."),
            default => self::check('description_length', self::BAD, "The description will be cut off ({$d} characters; about 156 show)."),
        };

        if (($shared['title'] ?? 0) > 0) {
            $seo[] = self::check('title_unique', self::BAD, 'Another page has the same search title. Each page should have its own.');
        }

        if (($shared['description'] ?? 0) > 0) {
            $seo[] = self::check('description_unique', self::BAD, 'Another page has the same description. Each page should have its own.');
        }

        if (! empty($page['noindex'])) {
            $seo[] = self::check('indexed', self::BAD, 'This page is hidden from search engines. Turn that off unless it is meant to be.');
        }

        if (($page['canonical'] ?? '') !== '' && ($page['url'] ?? '') !== '' && rtrim($page['canonical'], '/') !== rtrim($page['url'], '/')) {
            $seo[] = self::check('canonical', self::OK, 'This page tells search engines to rank a different address instead.');
        }

        // ── What social networks and X show ──
        $social = trim((string) ($page['social_title'] ?? ''));
        $x = trim((string) ($page['twitter_title'] ?? ''));

        if ($x !== '' && $social !== '' && $x !== $social) {
            $seo[] = self::check('twitter_title', self::OK, "X shows a different title from other networks: \"{$x}\". Clear the X title to use the social one, or keep it on purpose.");
        }

        $year = (int) ($page['year'] ?? date('Y'));

        foreach (['title' => 'search title', 'social_title' => 'social title', 'twitter_title' => 'X title', 'description' => 'description', 'social_description' => 'social description', 'twitter_description' => 'X description'] as $field => $label) {
            if (preg_match('/\b(19|20)\d{2}\b/', (string) ($page[$field] ?? ''), $m) === 1 && (int) $m[0] < $year) {
                $seo[] = self::check('old_year', self::OK, "The {$label} still says {$m[0]}.");

                break;
            }
        }

        $readability = [];

        // ── The page itself, as served ──
        if ($content === null) {
            $seo[] = self::check('live', self::OK, 'The live page could not be read, so its content was not checked.');
        } else {
            $count = count($content['words']);
            $h1 = count($content['h1']);
            $seo[] = match (true) {
                $h1 === 1 => self::check('h1', self::GOOD, 'The page has one main heading.'),
                $h1 === 0 => self::check('h1', self::BAD, 'The page has no main heading (H1).'),
                default => self::check('h1', self::OK, "The page has {$h1} main headings; one is clearer."),
            };
            $seo[] = match (true) {
                $count >= 300 => self::check('text_length', self::GOOD, "The page has {$count} words."),
                $count >= 200 => self::check('text_length', self::OK, "The page has {$count} words; 300 or more ranks better."),
                default => self::check('text_length', self::BAD, "The page has only {$count} words; aim for 300 or more."),
            };

            if ($key !== '') {
                $seo[] = self::has($content['intro'], $words)
                    ? self::check('intro_keyphrase', self::GOOD, 'The keyphrase appears in the first paragraph.')
                    : self::check('intro_keyphrase', self::BAD, 'Use the keyphrase in the first paragraph.');
                // Half the keyphrase is enough in a subheading: "Why chimney repair matters" covers "chimney repair newark".
                $seo[] = array_filter($content['subheadings'], fn (string $h) => self::has($h, $words, 0.5)) !== []
                    ? self::check('subheading_keyphrase', self::GOOD, 'A subheading mentions the keyphrase.')
                    : self::check('subheading_keyphrase', self::OK, 'No subheading mentions the keyphrase.');
                // A sentence holding every word of the keyphrase, in any order, is one use.
                $hits = count(array_filter($content['sentences'], fn (array $s) => self::has(implode(' ', $s), $words)));
                $density = $count > 0 ? round($hits * 100 / $count, 1) : 0.0;
                $seo[] = match (true) {
                    $density > 3 => self::check('density', self::BAD, "The keyphrase is used {$hits} times ({$density}%). That reads as stuffing; use it less."),
                    $hits === 0 => self::check('density', self::BAD, 'The keyphrase never appears in the page text.'),
                    $density < 0.5 => self::check('density', self::OK, "The keyphrase appears {$hits} ".($hits === 1 ? 'time' : 'times').' in the text; a few more would help.'),
                    default => self::check('density', self::GOOD, "The keyphrase appears {$hits} times ({$density}%)."),
                };
            }

            $images = count($content['alts']);
            $missing = count(array_filter($content['alts'], fn (string $a) => trim($a) === ''));
            $seo[] = match (true) {
                $images === 0 => self::check('images', self::OK, 'The page has no images.'),
                $missing === 0 => self::check('images', self::GOOD, 'Every image describes itself (alt text).'),
                $missing === $images => self::check('images', self::BAD, 'No image has alt text. Describe them for search and screen readers.'),
                default => self::check('images', self::OK, "{$missing} of {$images} images have no alt text."),
            };
            $seo[] = $content['internal'] > 0
                ? self::check('internal_links', self::GOOD, "The page links to {$content['internal']} other ".($content['internal'] === 1 ? 'page' : 'pages').' of the site.')
                : self::check('internal_links', self::BAD, 'Link to other pages of the site.');
            $seo[] = $content['external'] > 0
                ? self::check('outbound_links', self::GOOD, 'The page links to other websites.')
                : self::check('outbound_links', self::OK, 'The page links to no other website.');

            // ── Readability ──
            $sentences = $content['sentences'];
            $long = count(array_filter($sentences, fn (array $s) => count($s) > 20));
            $share = $sentences === [] ? 0 : round($long * 100 / count($sentences));
            $readability[] = $share <= 25
                ? self::check('sentence_length', self::GOOD, 'Sentences are a comfortable length.')
                : self::check('sentence_length', $share <= 35 ? self::OK : self::BAD, "{$share}% of sentences have more than 20 words. Shorter reads easier.");
            $longParas = count(array_filter($content['paragraphs'], fn (int $n) => $n > 150));
            $readability[] = $longParas === 0
                ? self::check('paragraph_length', self::GOOD, 'Paragraphs are a comfortable length.')
                : self::check('paragraph_length', self::BAD, "{$longParas} ".($longParas === 1 ? 'paragraph has' : 'paragraphs have').' more than 150 words. Break them up.');
            $readability[] = $content['longest_run'] <= 300
                ? self::check('subheadings', self::GOOD, 'Subheadings break the text up well.')
                : self::check('subheadings', self::BAD, "One stretch runs {$content['longest_run']} words without a subheading. Add one every 300 words.");
            $ease = self::flesch($sentences);
            $readability[] = match (true) {
                $ease === null => self::check('ease', self::OK, 'Too little text to judge how easy it reads.'),
                $ease >= 60 => self::check('ease', self::GOOD, "Easy to read (score {$ease})."),
                $ease >= 50 => self::check('ease', self::OK, "Fairly hard to read (score {$ease}). Shorter words and sentences help."),
                default => self::check('ease', self::BAD, "Hard to read (score {$ease}). Shorter words and sentences help."),
            };
        }

        return [
            'score' => self::score([...$seo, ...$readability]),
            'seo' => $seo,
            'readability' => $readability,
            'words' => $content === null ? 0 : count($content['words']),
        ];
    }

    /**
     * Mostly-good checks score good, but never over a red one: a page with a bad
     * check is at best ok, and a malformed title or description is bad outright.
     *
     * @param  list<array{key?: string, status: string}>  $checks
     */
    public static function score(array $checks): string
    {
        $statuses = array_column($checks, 'status', 'key');

        if (array_intersect_key($statuses, array_flip(self::BLOCKERS)) !== []) {
            return self::BAD;
        }

        $points = array_sum(array_map(fn (array $c) => ['good' => 2, 'ok' => 1, 'bad' => 0][$c['status']], $checks));
        $share = $checks === [] ? 0 : $points / (2 * count($checks));
        $score = $share >= 0.8 ? self::GOOD : ($share >= 0.55 ? self::OK : self::BAD);

        return $score === self::GOOD && in_array(self::BAD, array_column($checks, 'status'), true) ? self::OK : $score;
    }

    /**
     * The checks the overview scores: what the page stores and prints, without
     * reading the live page. The page view shows this too, beside its full
     * score, so the two never disagree about the same thing.
     *
     * @param  list<array{key: string, status: string, text: string}>  $seo
     * @return list<array{key: string, status: string, text: string}>
     */
    public static function metadataChecks(array $seo): array
    {
        return array_values(array_filter($seo, fn (array $c) => ! in_array($c['key'], self::LIVE, true)));
    }

    /** No letter or digit at all ("%", "-"), or a %%variable%% the SEO plugin did not fill in. */
    public static function malformed(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && (preg_match('/[\p{L}\p{N}]/u', $text) !== 1 || preg_match('/%%[a-z_]+%%/i', $text) === 1);
    }

    private static function check(string $key, string $status, string $text): array
    {
        return ['key' => $key, 'status' => $status, 'text' => $text];
    }

    /**
     * The page's main content: what a reader gets, without the site header,
     * footer, menus and scripts that are the same on every page.
     *
     * @return array{words: list<string>, h1: list<string>, subheadings: list<string>, intro: string, alts: list<string>,
     *     internal: int, external: int, sentences: list<list<string>>, paragraphs: list<int>, longest_run: int}
     */
    private static function content(string $html, string $url): array
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        foreach ($xp->query('//script|//style|//noscript|//svg|//template|//header|//footer|//nav'
            .'|//*[contains(@class,"elementor-location-header") or contains(@class,"elementor-location-footer")]') as $n) {
            $n->parentNode?->removeChild($n);
        }

        $root = $xp->query('//main')->item(0) ?? $xp->query('//body')->item(0);
        $out = ['words' => [], 'h1' => [], 'subheadings' => [], 'intro' => '', 'alts' => [], 'internal' => 0, 'external' => 0,
            'sentences' => [], 'paragraphs' => [], 'longest_run' => 0];

        if (! $root instanceof DOMElement) {
            return $out;
        }

        $host = strtolower(preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)));
        $run = 0;

        foreach ($xp->query('.//img|.//a[@href]', $root) as $e) {
            /** @var DOMElement $e */
            if (strtolower($e->tagName) === 'img') {
                $out['alts'][] = (string) $e->getAttribute('alt');

                continue;
            }

            $href = $e->getAttribute('href');

            if (preg_match('~^(mailto|tel|javascript|#)~i', $href) === 1) {
                continue;
            }

            $to = strtolower(preg_replace('/^www\./', '', (string) parse_url($href, PHP_URL_HOST)));
            $to === '' || $to === $host ? $out['internal']++ : $out['external']++;
        }

        /*
         * Every piece of visible text, grouped by its nearest block: a heading,
         * or a paragraph-like box. Elementor prints a one-paragraph text widget
         * as bare text in a <div>, so reading only <p> and <li> misses the
         * opening of most pages on these sites.
         */
        $blocks = [];

        foreach ($xp->query('.//text()[normalize-space()]', $root) as $node) {
            $owner = $node->parentNode;

            while ($owner instanceof DOMElement && ! in_array(strtolower($owner->tagName), self::BLOCKS, true)) {
                $owner = $owner->parentNode;
            }

            if (! $owner instanceof DOMElement) {
                continue;
            }

            $id = spl_object_id($owner);
            // The node is kept, not only its id: PHP reuses the id of a freed DOM wrapper.
            $blocks[$id] ??= ['node' => $owner, 'tag' => strtolower($owner->tagName), 'text' => ''];
            $blocks[$id]['text'] .= ' '.$node->textContent;
        }

        foreach ($blocks as $block) {
            $line = trim(preg_replace('/\s+/u', ' ', $block['text']) ?? '');
            $tag = $block['tag'];

            if ($tag === 'h1') {
                $out['h1'][] = $line;

                continue;
            }

            if (in_array($tag, ['h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $out['subheadings'][] = $line;
                $out['longest_run'] = max($out['longest_run'], $run);
                $run = 0;

                continue;
            }

            $words = self::words($line, false);

            if ($words === []) {
                continue;
            }

            $out['words'] = [...$out['words'], ...$words];
            $run += count($words);

            // A paragraph is a block of prose, not a button or a label.
            if (count($words) >= 8) {
                $out['paragraphs'][] = count($words);
                $out['intro'] = $out['intro'] === '' ? $line : $out['intro'];
            }

            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                if (($w = self::words($sentence, false)) !== []) {
                    $out['sentences'][] = $w;
                }
            }
        }

        $out['longest_run'] = max($out['longest_run'], $run);

        return $out;
    }

    /** @return list<string> lowercased words, stop words dropped unless $all is false */
    private static function words(string $text, bool $content = true): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[\'’][\p{L}]+)?/u', mb_strtolower($text), $m);

        return array_values($content ? array_diff($m[0], self::STOP) : $m[0]);
    }

    /** The content words of the keyphrase appear in the text, in any order: all of them, or the share asked for. */
    private static function has(string $text, array $words, float $share = 1.0): bool
    {
        if ($words === []) {
            return false;
        }

        $in = array_flip(self::words($text, false));
        $found = count(array_filter($words, fn (string $w) => isset($in[$w]) || isset($in[rtrim($w, 's')]) || isset($in[$w.'s'])));

        return $found >= (int) ceil($share * count($words));
    }

    /** The keyphrase's content words are all in the first half of the text. */
    private static function starts(string $text, array $words): bool
    {
        $all = self::words($text, false);

        return $words !== [] && self::has(implode(' ', array_slice($all, 0, max(count($words) + 1, (int) ceil(count($all) / 2)))), $words);
    }

    /** Flesch reading ease, 0–100, or null with too little text to say. */
    private static function flesch(array $sentences): ?int
    {
        $words = array_merge(...($sentences ?: [[]]));

        if (count($words) < 100) {
            return null;
        }

        $syllables = array_sum(array_map(function (string $w): int {
            $n = preg_match_all('/[aeiouy]+/', preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $w) ?? $w);

            return max(1, (int) $n);
        }, $words));

        return (int) round(max(0, min(100, 206.835 - 1.015 * (count($words) / count($sentences)) - 84.6 * ($syllables / count($words)))));
    }
}
