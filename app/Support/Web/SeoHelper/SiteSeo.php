<?php

namespace App\Support\Web\SeoHelper;

use App\Models\WebSite;
use App\Support\Web\SiteContent;
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
    public const FIELDS = ['title', 'description', 'keyphrase', 'noindex', 'canonical', 'social_title', 'social_description', 'social_image'];

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
     * @param  array<string, mixed>  $fields  only the fields to change
     * @return array<string, mixed> the page as saved
     */
    public function save(WebSite $site, int $id, array $fields): array
    {
        return $this->call($site, 'post', 'seo/'.$id, array_intersect_key($fields, array_flip(self::FIELDS)));
    }

    /** @return array<string, mixed> */
    private function call(WebSite $site, string $method, string $route, array $data = []): array
    {
        if (! $site->isServiceLinked() || $site->service_rest_url === null) {
            throw new RuntimeException('This website has not given EzBizi an account yet. Update the plugin on '
                .$site->host.' and open its WordPress admin once.');
        }

        $response = $this->http($site)->{$method}($this->content->restBase($site).'ezbizi/v1/'.$route, $data);

        if ($response->status() === 404 && str_contains((string) $response->body(), 'rest_no_route')) {
            throw new RuntimeException('The EzBizi plugin on '.$site->host.' is too old for the SEO Helper. It updates itself shortly.');
        }

        if (! $response->successful()) {
            throw new RuntimeException($site->host.' answered '.$response->status().'.');
        }

        return (array) $response->json();
    }

    private function http(WebSite $site): PendingRequest
    {
        return Http::withBasicAuth($site->service_user_login, $site->service_password)
            ->accept('application/json')
            ->timeout(25);
    }
}
