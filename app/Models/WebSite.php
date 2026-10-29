<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One website — or one subdomain, which is the same thing here.
 *
 * The company registers a host, EzBizi hands back a token once, and the
 * WordPress plugin on that host presents the token to read its values. Nothing
 * in this model knows anything about WordPress; a token and a host is the whole
 * contract, and a static-site build script could hold up the same end of it.
 */
#[Fillable([
    'company_id', 'franchise_id', 'host', 'label',
])]
class WebSite extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'web_sites';

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'went_quiet_at' => 'datetime',
            'service_linked_at' => 'datetime',
            // Readable, because we present it TO WordPress rather than
            // recognising it when somebody presents it to us. See the
            // migration for why this is the only workable choice.
            'service_password' => 'encrypted',
            // The areas whose jobs this site's review page thanks (Jose, 2026-10-08).
            'service_area_ids' => 'array',
            // Whether its pages carry the booking widget, as the plugin reports; null until it has.
            'has_booking' => 'boolean',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A host the company registered and the hashed token its plugin uses. Kept '
            .'while the site is registered — deleting the site, the company, or the '
            .'organization takes the row and every value beneath it. Nothing here is '
            .'about a person; the token is a machine credential and is stored hashed, '
            .'and the WordPress application password EzBizi publishes with is a second '
            .'machine credential, encrypted at rest and cleared when the pairing ends.'
        );
    }

    /**
     * The branch this site answers for, if it answers for one.
     *
     * Null is the ordinary case and means the company itself. It decides where
     * an enquiry from this website lands in Leads, which is the only thing that
     * reads it today.
     */
    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    /** Whether this site has handed EzBizi an account to publish with. */
    public function isServiceLinked(): bool
    {
        return $this->service_user_login !== null && $this->service_password !== null;
    }

    /** Forget the credential. WordPress revokes its own copy on the next read. */
    public function unlinkServiceAccount(): void
    {
        $this->forceFill([
            'service_user_login' => null,
            'service_password' => null,
            'service_rest_url' => null,
            'service_capabilities' => null,
            'service_linked_at' => null,
        ])->save();
    }

    /**
     * The one form of a host we compare and store.
     *
     * Everything a person might paste — `https://NJ.A-1Chimney.com/`,
     * `www.a-1chimney.com:443`, a trailing dot from a DNS tool — has to land on
     * the same string as what a WordPress install reports from `home_url()`, or
     * a site that is plainly connected reads as a stranger. Leading `www.` goes
     * too: `www.a-1chimney.com` and `a-1chimney.com` are one site with one phone
     * number in every case we are building for, and treating them as two would
     * hand somebody two tokens for one website and let the values drift apart.
     */
    public static function normalizeHost(string $raw): string
    {
        $host = trim($raw);
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0];
        $host = explode('@', $host)[array_key_last(explode('@', $host))];
        $host = explode('?', $host, 2)[0];
        $host = explode(':', $host, 2)[0];
        $host = rtrim(strtolower(trim($host)), '.');

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * The key a browser is allowed to see.
     *
     * `pk_` so it is recognisable in a page of markup and cannot be confused
     * with the `ezb_` secret when somebody is pasting one of them into a
     * support ticket. Random rather than derived from the host: a key that can
     * be computed from a domain name is not a key, it is a formula, and it
     * could not be rotated without changing the domain.
     */
    public static function newPublicKey(): string
    {
        return 'pk_'.Str::random(32);
    }

    /**
     * Every site gets one. Generated here rather than in the controller so a
     * site made by a seeder, a test or a console command cannot exist without
     * one; sites registered before the column existed are backfilled by the
     * migration that added it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $site): void {
            if ($site->public_key === null) {
                $site->public_key = self::newPublicKey();
            }
        });
    }

    /**
     * Primary is not a flag beside the company id — it IS the company id.
     *
     * `primary_company_id` holds this row's company when it is the primary and
     * NULL when it is not, so the unique index on it enforces one per company.
     * Reading it as a boolean is a convenience over that single fact, which is
     * why there is nothing to keep in step.
     */
    public function getIsPrimaryAttribute(): bool
    {
        return $this->primary_company_id !== null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(WebValue::class, 'site_id');
    }
}
