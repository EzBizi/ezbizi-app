<?php

namespace App\Models\Concerns;

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
                return;
            }

            // organization_id was set explicitly — allow it only if it matches.
            // Platform scope may write anywhere; that's its purpose (§4.2).
            if (! $tenant->inPlatformScope()
                && $model->getAttribute('organization_id') !== $tenant->idOrFail()) {
                throw new RuntimeException(sprintf(
                    'Refusing to create %s for organization %s while acting as %s.',
                    static::class,
                    $model->getAttribute('organization_id'),
                    $tenant->idOrFail(),
                ));
            }
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
        });
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
 * organizations is a real, audited mode (§4.2), not an accident. With no tenant
 * and no platform scope it also does nothing, because silently returning every
 * tenant's rows would be far worse than the loud failure the write path gives.
 * That combination is what the isolation tests exist to catch.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->inPlatformScope() || ! $tenant->has()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('organization_id'),
            $tenant->id()
        );
    }
}
