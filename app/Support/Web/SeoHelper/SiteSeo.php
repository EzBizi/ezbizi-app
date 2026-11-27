<?php

namespace App\Support\Web\SeoHelper;

use App\Models\WebSite;
use App\Support\Web\SiteContent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The SEO Helper's calls to a website: its pages' search titles, descriptions
 * and keyphrases, read and written through the plugin's `ezbizi/v1/seo`
 * routes with the service account the site handed over at pairing.
 *
 * The site's SEO plugin keeps the values and prints them; EzBizi keeps no copy.
 */
final class SiteSeo
{
    /** The fields a save may carry. */
    public const FIELDS = ['title', 'description', 'keyphrase', 'noindex', 'canonical', 'social_title', 'social_description', 'social_image',
        'twitter_title', 'twitter_description', 'twitter_image'];

    /** The site-wide title templates a save may carry. */
    public const SITE_FIELDS = ['page_title_template', 'post_title_template', 'social_page_title_template', 'social_post_title_template'];

    public function __construct(private readonly SiteContent $content) {}

    /**
     * Every published page and post, with its SEO, and the site's settings.
     *
     * @return array{pages: list<array<string, mixed>>, site: array<string, mixed>}
     */
    public function all(WebSite $site): array
    {
        $pages = [];
        $settings = [];
        $page = 1;

        do {
            $body = $this->call($site, 'get', 'seo', ['page' => $page, 'per_page' => 200]);
            $pages = [...$pages, ...($body['pages'] ?? [])];
            $settings = $body['site'] ?? $settings;
            $page++;
            // A ceiling well past the largest site (1,300 pages) so a bad count cannot loop.
        } while (count($pages) < (int) ($body['total'] ?? 0) && ($body['pages'] ?? []) !== [] && $page <= 50);

        return ['pages' => $pages, 'site' => $settings];
    }

    /** @return array<string, mixed> */
    public function one(WebSite $site, int $id): array
    {
        return $this->call($site, 'get', 'seo/'.$id);
    }

    /**
     * What a draft title and description would print on the page.
     *
     * @return array{title: string, description: string}
     */
    public function preview(WebSite $site, int $id, string $title, string $description): array
    {
        return $this->call($site, 'post', 'seo/'.$id.'/preview', ['title' => $title, 'description' => $description]);
    }

    /**
     * The site's default page and post titles.
     *
     * @param  array{page_title_template?: string, post_title_template?: string}  $templates
     * @return array<string, mixed> the site's settings as saved
     */
    public function saveSite(WebSite $site, array $templates): array
    {
        return $this->call($site, 'post', 'seo/site', array_intersect_key($templates, array_flip(self::SITE_FIELDS)));
    }

    /**
     * @param  array<string, mixed>  $fields  only the fields to change
     * @return array<string, mixed> the page as saved
     */
    public function save(WebSite $site, int $id, array $fields): array
    {
        return $this->call($site, 'post', 'seo/'.$id, array_intersect_key($fields, array_flip(self::FIELDS)));
    }

    /**
     * The exception's code is the site's HTTP status, or 0 when it never answered,
     * so a missing page, a refused account and a dead site read differently.
     *
     * @return array<string, mixed>
     */
    private function call(WebSite $site, string $method, string $route, array $data = []): array
    {
        if (! $site->isServiceLinked() || $site->service_rest_url === null) {
            throw new RuntimeException('This website has not given EzBizi an account yet. Update the plugin on '
                .$site->host.' and open its WordPress admin once.');
        }

        try {
            $response = $this->http($site)->{$method}($this->content->restBase($site).'ezbizi/v1/'.$route, $data);
        } catch (ConnectionException) {
            throw new RuntimeException($site->host.' did not answer.', 0);
        }

        if ($response->status() === 404 && str_contains((string) $response->body(), 'rest_no_route')) {
            throw new RuntimeException('The EzBizi plugin on '.$site->host.' is too old for the SEO Helper. It updates itself shortly.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException('That page does not exist on '.$site->host.'.', 404);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException($site->host.' refused EzBizi\'s account ('.$response->status().'). Open the site\'s WordPress admin once so the plugin hands over a new one.', $response->status());
        }

        if (! $response->successful()) {
            throw new RuntimeException($site->host.' answered '.$response->status().'.', $response->status());
        }

        return (array) $response->json();
    }

    private function http(WebSite $site): PendingRequest
    {
        return Http::withBasicAuth($site->service_user_login, $site->service_password)
            ->withoutRedirecting() // audit 2026-11-27: a redirect would carry the call (and the password) past the host check
            ->accept('application/json')
            ->timeout(25);
    }
}
