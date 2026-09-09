<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Branding\Branding;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Web\WidgetDefaults;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * How one site's chat bubble and contact form look, and what the form asks.
 *
 * Absent for most sites, which is not a gap: no row means the defaults, and a
 * branch site with no row of its own follows the primary site — the same rule
 * the values follow, for the same reason. A company that sets its colours once
 * should not set them again per subdomain.
 */
#[Fillable([
    'site_id', 'config',
])]
class WebWidget extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'web_widgets';

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'The look and the questions of a site\'s chat and contact form. Configuration '
            .'a company wrote about its own website — nothing about a person, and no '
            .'enquiry content. Deleted with the site it belongs to.'
        );
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WebSite::class, 'site_id');
    }

    /**
     * The config a site actually publishes: its own, else the primary's, else
     * the defaults — resolved and sanitised, ready to render.
     *
     * ── Why the whole config inherits rather than key by key ──
     *
     * The values inherit per key, because a branch site overriding its phone
     * number should still publish the company's licence. A widget is one design
     * decision: half this site's colours and half the primary's is not a look
     * anybody chose. So a site either dresses its widgets itself or follows the
     * primary entirely.
     *
     * @return array<string, mixed>
     */
    public static function resolvedFor(WebSite $site): array
    {
        $own = self::query()->where('site_id', $site->getKey())->first();

        if ($own !== null) {
            return WidgetDefaults::resolve(is_array($own->config) ? $own->config : [], self::brandFor($site));
        }

        if ($site->is_primary) {
            return WidgetDefaults::all(self::brandFor($site));
        }

        $primary = WebSite::query()
            ->where('company_id', $site->company_id)
            ->whereNotNull('primary_company_id')
            ->first();

        if ($primary === null) {
            return WidgetDefaults::all(self::brandFor($site));
        }

        $inherited = self::query()->where('site_id', $primary->getKey())->first();

        return $inherited === null
            ? WidgetDefaults::all(self::brandFor($site))
            : WidgetDefaults::resolve(is_array($inherited->config) ? $inherited->config : [], self::brandFor($site));
    }

    /**
     * What the editing screen needs: this site's own config if it has one, and
     * separately what it would inherit — so the screen can say "following
     * a-1chimney.com" rather than showing borrowed values as if they were typed
     * here.
     *
     * @return array{config: array<string, mixed>, own: bool, inherited: array<string, mixed>}
     */
    public static function forEditor(WebSite $site): array
    {
        $own = self::query()->where('site_id', $site->getKey())->first();

        return [
            'config' => self::resolvedFor($site),
            'own' => $own !== null,
            'inherited' => $own === null ? [] : self::inheritedFor($site),
        ];
    }

    /** @return array<string, mixed> */
    private static function inheritedFor(WebSite $site): array
    {
        if ($site->is_primary) {
            return [];
        }

        $primary = WebSite::query()
            ->where('company_id', $site->company_id)
            ->whereNotNull('primary_company_id')
            ->first();

        if ($primary === null) {
            return [];
        }

        $row = self::query()->where('site_id', $primary->getKey())->first();

        return $row === null
            ? WidgetDefaults::all(self::brandFor($site))
            : WidgetDefaults::resolve(is_array($row->config) ? $row->config : [], self::brandFor($site));
    }

    /**
     * The colour this site's owner publishes under, if they have told us one.
     *
     * The company's, else the organization's, else null — the same inheritance
     * the logo has, resolved by the same helper, because they are one fact
     * about one business and a screen showing a logo in one colour scheme and a
     * button in another would be describing two companies.
     */
    private static function brandFor(WebSite $site): ?string
    {
        $site->loadMissing('company');

        return Branding::colour($site->company, $site->company?->organization);
    }

    /** @return Collection<int, WebWidget> */
    public static function forSites(Collection $siteIds): Collection
    {
        return self::query()->whereIn('site_id', $siteIds)->get();
    }
}
