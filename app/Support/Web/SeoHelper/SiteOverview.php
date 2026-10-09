<?php

namespace App\Support\Web\SeoHelper;

/**
 * A whole website's search health, from what each page stores — no page is
 * fetched, so a site of a thousand pages answers in the time one read takes.
 *
 * The part no single-page check can do: two pages chasing one keyphrase, two
 * pages sharing a title or a description, and settings that spoil every page
 * at once (a title template that still says "UPDATED 2024").
 */
final class SiteOverview
{
    /** A "shared" check → the value it compares. */
    private const SHARED = ['keyphrase_unique' => 'keyphrase', 'title_unique' => 'title', 'description_unique' => 'description'];

    /**
     * @param  list<array<string, mixed>>  $pages  rows from the site: seo (stored), final (printed), url, slug
     * @param  array<string, mixed>  $site  the site's SEO settings
     * @return array{summary: array<string, int>, site_issues: list<array{key: string, status: string, text: string}>, pages: list<array<string, mixed>>}
     */
    public static function build(array $pages, array $site, ?int $year = null): array
    {
        $year ??= (int) date('Y');
        $counts = ['title' => [], 'description' => [], 'keyphrase' => []];
        // Which pages share each value, so a "shared" issue can name them.
        $members = ['title' => [], 'description' => [], 'keyphrase' => []];

        foreach ($pages as $page) {
            foreach (self::fingerprints($page) as $kind => $print) {
                if ($print !== '') {
                    $counts[$kind][$print] = ($counts[$kind][$print] ?? 0) + 1;
                    $members[$kind][$print][] = $page['id'];
                }
            }
        }

        $groups = [];

        foreach ($members as $kind => $byValue) {
            $groups[$kind] = [];

            foreach ($byValue as $ids) {
                if (count($ids) > 1) {
                    $first = array_values(array_filter($pages, fn ($p) => $p['id'] === $ids[0]))[0];
                    $groups[$kind][] = ['value' => (string) ($kind === 'keyphrase' ? $first['seo']['keyphrase'] : $first['final'][$kind]), 'ids' => $ids];
                }
            }
        }

        $rows = [];
        $summary = ['pages' => count($pages), 'good' => 0, 'ok' => 0, 'bad' => 0, 'missing_description' => 0, 'missing_keyphrase' => 0,
            'shared_title' => 0, 'shared_description' => 0, 'shared_keyphrase' => 0, 'hidden' => 0];

        foreach ($pages as $page) {
            $prints = self::fingerprints($page);
            $shared = [];

            foreach ($prints as $kind => $print) {
                $shared[$kind] = $print === '' ? 0 : $counts[$kind][$print] - 1;
            }

            $checks = PageAnalysis::metadataChecks(PageAnalysis::run(self::analysable($page), null, $shared)['seo']);
            $score = PageAnalysis::score($checks);
            $summary[$score]++;
            $summary['missing_description'] += (int) (trim((string) ($page['final']['description'] ?? '')) === '');
            $summary['missing_keyphrase'] += (int) (trim((string) ($page['seo']['keyphrase'] ?? '')) === '');
            $summary['shared_title'] += (int) ($shared['title'] > 0);
            $summary['shared_description'] += (int) ($shared['description'] > 0);
            $summary['shared_keyphrase'] += (int) ($shared['keyphrase'] > 0);
            $summary['hidden'] += (int) ! empty($page['seo']['noindex']);

            $rows[] = [
                'id' => $page['id'],
                'type' => $page['type'] ?? 'page',
                'name' => $page['name'] ?? '',
                'url' => $page['url'] ?? '',
                'seo' => $page['seo'] ?? [],
                'final' => $page['final'] ?? [],
                'score' => $score,
                // Stored and printed values only; the page view adds the live page.
                'score_scope' => 'metadata',
                // Only what needs doing; the good checks are for the page view.
                'issues' => array_values(array_map(
                    fn (array $c) => ['key' => $c['key'], 'status' => $c['status'], 'text' => $c['text']]
                        + (isset(self::SHARED[$c['key']]) ? ['with' => array_values(array_diff($members[self::SHARED[$c['key']]][$prints[self::SHARED[$c['key']]]] ?? [], [$page['id']]))] : []),
                    array_filter($checks, fn (array $c) => $c['status'] !== PageAnalysis::GOOD),
                )),
            ];
        }

        return ['summary' => $summary, 'groups' => $groups, 'site_issues' => self::siteIssues($site, $year), 'pages' => $rows,
            'rules' => PageAnalysis::RULES, 'analyzed_at' => now()->toIso8601String()];
    }

    /**
     * What one page stores and prints, in the shape the analysis reads.
     *
     * @param  array<string, mixed>  $page
     * @return array{title: string, description: string, keyphrase: string, slug: string, noindex: bool, canonical: string, url: string}
     */
    public static function analysable(array $page): array
    {
        return [
            'title' => html_entity_decode((string) ($page['final']['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'description' => html_entity_decode((string) ($page['final']['description'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'keyphrase' => (string) ($page['seo']['keyphrase'] ?? ''),
            'slug' => (string) ($page['slug'] ?? ''),
            'noindex' => (bool) ($page['seo']['noindex'] ?? false),
            'canonical' => (string) ($page['seo']['canonical'] ?? ''),
            'url' => (string) ($page['url'] ?? ''),
            'social_title' => html_entity_decode((string) ($page['final']['social_title'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'social_description' => html_entity_decode((string) ($page['final']['social_description'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'twitter_title' => html_entity_decode((string) ($page['final']['twitter_title'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'twitter_description' => html_entity_decode((string) ($page['final']['twitter_description'] ?? ''), ENT_QUOTES, 'UTF-8'),
        ];
    }

    /**
     * The OTHER pages sharing this page's title, description and keyphrase, as {id, name}.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array{title: list<array{id: int, name: string}>, description: list<array{id: int, name: string}>, keyphrase: list<array{id: int, name: string}>}
     */
    public static function sharingPages(array $page, array $pages): array
    {
        $mine = self::fingerprints($page);
        $shared = ['title' => [], 'description' => [], 'keyphrase' => []];

        foreach ($pages as $other) {
            if (($other['id'] ?? null) === ($page['id'] ?? null)) {
                continue;
            }

            foreach (self::fingerprints($other) as $kind => $print) {
                if ($print !== '' && $print === $mine[$kind]) {
                    $shared[$kind][] = ['id' => $other['id'], 'name' => (string) ($other['name'] ?? '')];
                }
            }
        }

        return $shared;
    }

    /** @return array{title: string, description: string, keyphrase: string} normalised for comparison */
    private static function fingerprints(array $page): array
    {
        $norm = fn ($s) => trim(preg_replace('/\s+/u', ' ', mb_strtolower(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'))) ?? '');

        return [
            'title' => $norm($page['final']['title'] ?? ''),
            'description' => $norm($page['final']['description'] ?? ''),
            'keyphrase' => $norm($page['seo']['keyphrase'] ?? ''),
        ];
    }

    /** A template with each written-out year replaced by the variable that keeps it current. */
    public static function yearless(string $template): string
    {
        return (string) preg_replace('/\b(19|20)\d{2}\b/', '%%currentyear%%', $template);
    }

    /** @return list<array{key: string, status: string, text: string, fix?: array{field: string, value: string}}> */
    private static function siteIssues(array $site, int $year): array
    {
        $issues = [];

        if (! empty($site['search_engines_blocked'])) {
            $issues[] = ['key' => 'blocked', 'status' => PageAnalysis::BAD, 'text' => 'The whole website asks search engines not to index it (WordPress › Settings › Reading).'];
        }

        foreach (['page_title_template' => 'pages', 'post_title_template' => 'posts',
            'social_page_title_template' => 'pages when shared', 'social_post_title_template' => 'posts when shared'] as $key => $what) {
            $template = (string) ($site[$key] ?? '');

            if (preg_match('/\b(19|20)\d{2}\b/', $template, $m) === 1 && (int) $m[0] !== $year) {
                $issues[] = ['key' => 'stale_year_'.$what, 'status' => PageAnalysis::BAD,
                    'text' => "The default title for {$what} says {$m[0]}. Use the year variable so it stays current: \"{$template}\".",
                    'fix' => ['field' => $key, 'value' => self::yearless($template)]];
            } elseif (preg_match('/\b(19|20)\d{2}\b/', $template) === 1) {
                $issues[] = ['key' => 'fixed_year_'.$what, 'status' => PageAnalysis::OK,
                    'text' => "The default title for {$what} names this year in plain text; it will be out of date in January.",
                    'fix' => ['field' => $key, 'value' => self::yearless($template)]];
            }
        }

        return $issues;
    }
}
