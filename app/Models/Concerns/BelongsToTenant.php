<?php

namespace App\Models\Concerns;

use App\Exceptions\SubscriptionLapsedException;
use App\Support\Subscriptions\SubscriptionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

/**
 * Applied to every tenant-owned model. Two jobs:
 *
 *   1. Reads are filtered to the current organization.
 *   2. Writes are STAMPED with it, and cross-tenant writes are refused.
 *
 * Point 2 is the one usually missed. A global scope filters SELECTs; it does
 * nothing to stop code saving a row with someone else's organization_id, which
 * is a worse bug — it silently plants data inside another tenant.
 *
 * This is a supporting control, not the boundary itself (plan §5.1). The boundary
 * is TenantContext + authorization + database constraints. This trait just makes
 * the common path safe by default.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        // Stamp on create, so nothing can be written without a tenant.
        static::creating(function (Model $model) {
            $tenant = app(TenantContext::class);

            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', $tenant->idOrFail());
            } elseif (! $tenant->inPlatformScope()
                && $model->getAttribute('organization_id') !== $tenant->idOrFail()) {
                // organization_id was set explicitly — allow it only if it
                // matches. Platform scope may write anywhere; that's its
                // purpose (§4.2).
                throw new RuntimeException(sprintf(
                    'Refusing to create %s for organization %s while acting as %s.',
                    static::class,
                    $model->getAttribute('organization_id'),
                    $tenant->idOrFail(),
                ));
            }

            // Deliberately after the branch rather than inside it: the stamped
            // case is the COMMON one, and an early return there would have left
            // almost every create in the app unguarded.
            static::assertSubscriptionPermits($model);
        });

        // Refuse to move a row between tenants, or edit another tenant's row.
        static::updating(function (Model $model) {
            $tenant = app(TenantContext::class);

            if ($tenant->inPlatformScope()) {
                return;
            }

            if ($model->isDirty('organization_id')) {
                throw new RuntimeException(
                    'organization_id is immutable. Moving a record between tenants is '
                    .'never a legitimate update.'
                );
            }

            if ($model->getAttribute('organization_id') !== $tenant->idOrFail()) {
                throw new RuntimeException(sprintf(
                    'Refusing to update %s belonging to another organization.',
                    static::class,
                ));
            }

            static::assertSubscriptionPermits($model);
        });

        static::deleting(function (Model $model) {
            $tenant = app(TenantContext::class);

            if (! $tenant->inPlatformScope()
                && $model->getAttribute('organization_id') !== $tenant->idOrFail()) {
                throw new RuntimeException(sprintf(
                    'Refusing to delete %s belonging to another organization.',
                    static::class,
                ));
            }

            // A delete is a write. This hook was the one of the three that did
            // not say so, while the docblock below claimed every tenant-owned
            // write converged here — so a lapsed organization could not create
            // or edit a customer and could still delete one, including
            // forceDelete. The owner's rule for the read-only state is
            // "no changes whatsoever can be done", and removing a record is the
            // largest change there is.
            static::assertSubscriptionPermits($model);
        });
    }

    /**
     * The subscription floor (§13).
     *
     * Here rather than in middleware because §13 is explicit that HTTP is not
     * the only write path: automation actions, queued jobs, scheduled tasks and
     * imports all run with no request at all, and "an automation that keeps
     * firing after expiry means the product works fine without paying".
     *
     * This is the one place every tenant-owned write converges, whatever started
     * it — which is the difference between an edge that can be walked around and
     * a floor that cannot.
     *
     * Platform scope is exempt: staff repairing a lapsed customer's data is a
     * legitimate, audited act (§4.2), and refusing it would make an expired
     * account unsupportable.
     */
    protected static function assertSubscriptionPermits(Model $model): void
    {
        if (app(TenantContext::class)->inPlatformScope()) {
            return;
        }

        $guard = app(SubscriptionGuard::class);

        if (! $guard->permits($model)) {
            throw new SubscriptionLapsedException($guard->refusal($model));
        }
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }
}

/**
 * The read filter.
 *
 * In platform scope it deliberately does nothing — staff working across
 * organizations is a real, audited mode (§4.2), not an accident.
 *
 * ── With no tenant it REFUSES ────────────────────────────────────
 *
 * It used to return without adding a predicate, on the reasoning that a loud
 * failure was worse than no filter. That was backwards, and the docblock saying
 * so described the leak as though it were the safeguard: no predicate means the
 * query returns every organization's rows. Route model binding was resolving
 * ahead of SetTenantContext (see bootstrap/app.php), so that was not a
 * hypothetical — it was the live path for every {model} in routes/api.php.
 *
 * So this now matches the write path, which has always thrown. Reading across
 * tenants is a real need, and it has two named ways to say so:
 *
 *   TenantContext::runWithoutTenant()   the callback runs in platform scope
 *   Model::withoutGlobalScopes()        one query, opted out at the call site
 *
 * Both are greppable. Silence was not.
 *
 * The exception is RuntimeException rather than something more specific
 * deliberately: this is a programming error — code reading tenant data from
 * somewhere that never established a tenant — not a condition callers should be
 * catching and handling.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->inPlatformScope()) {
            return;
        }

        if (! $tenant->has()) {
            throw new RuntimeException(sprintf(
                'No tenant in context, refusing to read %s unscoped. Anything reading '
                .'tenant data must run inside TenantContext::run() or after a request '
                .'has resolved its organization. If this read genuinely spans tenants, '
                .'say so with runWithoutTenant() or withoutGlobalScopes().',
                $model::class,
            ));
        }

        $builder->where(
            $model->qualifyColumn('organization_id'),
            $tenant->id()
        );
    }
}
