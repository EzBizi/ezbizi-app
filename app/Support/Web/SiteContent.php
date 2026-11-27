<?php

namespace App\Support\Web;

use App\Models\WebSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The pages and posts a customer's WordPress holds, read through the account
 * that site handed us.
 *
 * ── Why this reads WordPress rather than storing anything ──
 *
 * The site is the record. A copy here would be stale the moment somebody
 * published, and a screen showing yesterday's pages while claiming to show the
 * site's is worse than a screen that waits a second. Nothing is written to our
 * database by this class at all.
 *
 * ── The credential, and the one thing it must never become ──
 *
 * The application password the plugin handed over at pairing, sent as HTTP
 * Basic over the site's own REST URL. That URL was checked at write time to be
 * on the host the token names — and it is checked AGAIN here, because a stored
 * value is only as trustworthy as the last thing that touched the row, and this
 * class is the one that turns it into an outbound request from our server.
 * Without that second check this method is a request forger: anybody who could
 * get a URL into that column could have EzBizi fetch it, from our address, with
 * our network position.
 */
final class SiteContent
{
    /** What WordPress calls them, and what we call them back. */
    public const TYPES = ['page' => 'pages', 'post' => 'posts'];

    /**
     * One page of results, plus how many there are in total.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
     */
    public function list(
        WebSite $site,
        string $type,
        int $page = 1,
        string $search = '',
        array $excludeAuthors = [],
    ): array {
        if (! array_key_exists($type, self::TYPES)) {
            throw new RuntimeException('That is not something a website holds.');
        }

        if (! $site->isServiceLinked() || $site->service_rest_url === null) {
            throw new RuntimeException(
                'This website has not given EzBizi an account yet. Update the plugin on '
                .$site->host.' and open its WordPress admin once.'
            );
        }

        $base = $this->restBase($site);

        $response = Http::withBasicAuth($site->service_user_login, $site->service_password)
            ->withoutRedirecting() // audit 2026-11-27: a redirect would carry the call (and the password) past the host check
            ->accept('application/json')
            ->timeout(12)
            ->get($base.'wp/v2/'.self::TYPES[$type], array_filter([
                'per_page' => 50,
                'page' => max(1, $page),
                // Drafts and private pages are part of what somebody manages, and
                // the role we were given can see them. A list that showed only
                // what is public would be a different list from the one in
                // wp-admin, which is the list they are comparing it to.
                'status' => 'publish,draft,pending,private,future',
                'orderby' => 'modified',
                'order' => 'desc',
                'search' => $search === '' ? null : $search,
                /*
                 * City pages are left out HERE rather than after the fact, and
                 * that is the whole reason this parameter exists.
                 *
                 * call.a-1chimney.com has 609 city pages. Dropping them from a
                 * batch of fifty after WordPress had counted them would leave
                 * a list showing three rows under "page 1 of 13" — a pager
                 * describing a list that is not on screen. WordPress's own
                 * `author_exclude` makes the count and the contents agree,
                 * because the exclusion happens before either is decided.
                 */
                'author_exclude' => $excludeAuthors === []
                    ? null
                    : implode(',', array_map('intval', $excludeAuthors)),
                // Asking for the fields we draw keeps a post's whole rendered
                // body off the wire; some of these run to megabytes.
                // author and categories ride along because a page's screen
                // shows what the WEBSITE already says before EzBizi overrides
                // anything — showing those boxes empty on a page that has an
                // author says something false about the customer's own site.
                '_fields' => 'id,date,modified,slug,status,type,link,title,author,categories',
            ]));

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException(
                'The account on '.$site->host.' was refused. Its application password may '
                .'have been deleted in WordPress — reissue the site token to pair again.'
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                $site->host.' answered '.$response->status().' when asked for its '
                .self::TYPES[$type].'.'
            );
        }

        $body = $response->json();

        return [
            'items' => array_map($this->present(...), is_array($body) ? $body : []),
            'total' => (int) ($response->header('X-WP-Total') ?: 0),
            'pages' => (int) ($response->header('X-WP-TotalPages') ?: 0),
        ];
    }

    /**
     * Who may be named as an author, and what a post may be filed under.
     *
     * Read from the customer's own WordPress rather than typed in EzBizi: a
     * dropdown of names somebody invented here would drift away from the site
     * the moment an author left, and the point of the field is to say something
     * true about the page.
     *
     * NOTHING IS WRITTEN. The account we hold could set a post's author, and
     * deliberately does not — publishing from EzBizi is a decision nobody has
     * taken. These are stored as this page's values, the same as its phone
     * number, and the site remains the only thing that edits the site.
     *
     * @return array{authors: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>}
     */
    public function options(WebSite $site): array
    {
        return [
            'authors' => $this->lookup($site, 'wp/v2/users', 'name'),
            'categories' => $this->lookup($site, 'wp/v2/categories', 'name'),
        ];
    }

    /**
     * The ids of the author accounts this site uses for city pages.
     *
     * Cached for ten minutes because it is asked on every Pages request and the
     * answer changes when somebody adds a WordPress user — which is to say
     * almost never. A miss costs one round trip to the customer's site; without
     * the cache the Pages list would cost two instead of one, for ever, to
     * answer a question whose answer is the same all day.
     *
     * FAILS TO AN EMPTY LIST rather than throwing, and that is deliberate: the
     * consequence of not knowing the authors is that a Pages list also shows
     * city pages, which is what it did last week. The consequence of throwing
     * would be no Pages list at all. One of those is a worse afternoon.
     *
     * @return array<int, int>
     */
    public function cityAuthorIds(WebSite $site): array
    {
        return Cache::remember(
            'web:city-authors:'.$site->getKey(),
            now()->addMinutes(10),
            function () use ($site): array {
                try {
                    $ids = [];

                    foreach ($this->options($site)['authors'] as $author) {
                        if (PageKinds::authorNamesCity((string) ($author['name'] ?? ''))) {
                            $ids[] = (int) $author['id'];
                        }
                    }

                    return $ids;
                } catch (RuntimeException) {
                    return [];
                }
            },
        );
    }

    /**
     * One list of named things from WordPress.
     *
     * 100 is WordPress's own per_page ceiling and there is no paging here on
     * purpose: a dropdown with more than a hundred entries is not a dropdown,
     * and a site with that many authors wants a different screen rather than a
     * longer list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lookup(WebSite $site, string $route, string $nameField): array
    {
        if (! $site->isServiceLinked() || $site->service_rest_url === null) {
            throw new RuntimeException(
                'This website has not given EzBizi an account yet. Update the plugin on '
                .$site->host.' and open its WordPress admin once.'
            );
        }

        $response = Http::withBasicAuth($site->service_user_login, $site->service_password)
            ->withoutRedirecting() // audit 2026-11-27: a redirect would carry the call (and the password) past the host check
            ->accept('application/json')
            ->timeout(12)
            ->get($this->restBase($site).$route, [
                'per_page' => 100,
                'orderby' => 'name',
                'order' => 'asc',
                '_fields' => 'id,name,slug',
            ]);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException(
                'The account on '.$site->host.' was refused. Its application password may '
                .'have been deleted in WordPress — reissue the site token to pair again.'
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                $site->host.' answered '.$response->status().' when asked who its authors are.'
            );
        }

        $body = $response->json();

        return array_map(fn ($row) => [
            'id' => (int) ($row['id'] ?? 0),
            // Entities again — "O&#039;Brien" would otherwise reach a dropdown.
            'name' => html_entity_decode((string) ($row[$nameField] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'slug' => (string) ($row['slug'] ?? ''),
        ], is_array($body) ? $body : []);
    }

    /**
     * The REST root, re-checked against the host this site's token names.
     *
     * Validated on write too. Checked again because the check that matters is
     * the one standing between a stored string and an outbound request, and
     * this is that place.
     */
    public function restBase(WebSite $site): string
    {
        $url = (string) $site->service_rest_url;
        $host = WebSite::normalizeHost((string) parse_url($url, PHP_URL_HOST));

        if ($host !== $site->host) {
            throw new RuntimeException(
                'The stored address for '.$site->host.' points somewhere else. Reissue the '
                .'site token to pair again.'
            );
        }

        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new RuntimeException('The stored address for '.$site->host.' is not a web address.');
        }

        return rtrim($url, '/').'/';
    }

    /**
     * One row, in the shape the screen draws.
     *
     * `title.rendered` carries HTML entities — a post called "Mum &amp; Dad"
     * arrives escaped — so it is decoded once here rather than in the client,
     * where forgetting it would print the entity to a person.
     *
     * @param  mixed  $row
     * @return array<string, mixed>
     */
    private function present($row): array
    {
        $row = is_array($row) ? $row : [];
        $title = $row['title']['rendered'] ?? '';

        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'slug' => (string) ($row['slug'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'link' => (string) ($row['link'] ?? ''),
            'modified' => (string) ($row['modified'] ?? ''),

            // WordPress's own ids. Resolved to names against the lists from
            // /content/options, which is the only place the names exist.
            'author' => isset($row['author']) ? (int) $row['author'] : null,
            'categories' => array_map('intval', is_array($row['categories'] ?? null) ? $row['categories'] : []),
        ];
    }
}
