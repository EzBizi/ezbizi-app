<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One office's floor plan — the Monitoring "General" tab
 * (docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md).
 *
 * The whole plan lives in `layout` as a JSON document — a grid, a list of wall
 * segments, and a list of nodes (a computer tile assigned to a person). It is
 * edited and saved as a unit; walls are pure geometry and nodes are a small
 * list, so there is nothing to query row by row and no reason to shred it into
 * tables (the design's ponytail note: promote nodes to their own table only if
 * per-node queries ever earn their place).
 *
 * ── Scope, and the two org columns ───────────────────────────────
 *
 * A plan belongs to a place in the Org > Company > Franchise structure, scoped
 * exactly like an assignment: `assigned_organization_id` / `assigned_company_id`
 * / `assigned_franchise_id` are the columns ScopeReach reads, so an org-level
 * viewer sees every plan, a company viewer its company's and its franchises',
 * and a franchise viewer only its own — with no new predicate.
 *
 * `assigned_organization_id` is ALWAYS the tenant org (stamped below), which is
 * what makes ScopeReach's org branch show every plan to an org-level viewer.
 * That is not the same column as `organization_id`: the latter is the tenant
 * column BelongsToTenant stamps and filters on (the trait hardcodes
 * `organization_id`, the same one monitoring_cameras carries). The two agree by
 * construction — both come from the tenant in context — but they answer
 * different questions, so the table carries both, as `assignments` does.
 *
 * ── Retention / §9 ───────────────────────────────────────────────
 *
 * A plan is organization CONFIGURATION, not data about a person: a node holds a
 * `user_id` reference, but the plan is not a record of anything that happened to
 * anybody. So it states plain `StatesRetention` / `forever()` rather than
 * `HoldsPersonalData` — the same answer MonitoringCamera gives for a camera's
 * stream URL. A deleted user leaves a dangling `user_id` in a node, which
 * resolves to grey (unassigned) at read time; nothing personal is stored here to
 * erase.
 */
class MonitoringFloorPlan extends Model implements StatesRetention
{
    use BelongsToTenant, HasUuids;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'monitoring_floor_plans';

    /** Filed where it is assigned (ChangeFeed). */
    public function changeFeedPlace(): array
    {
        return [$this->assigned_company_id, $this->assigned_franchise_id];
    }


    protected $table = 'monitoring_floor_plans';

    protected $fillable = [
        'name', 'assigned_company_id', 'assigned_franchise_id', 'layout', 'created_by',
    ];

    protected $casts = [
        'layout' => 'array',
    ];

    protected static function booted(): void
    {
        // `assigned_organization_id` is always the tenant org — the design's
        // "always the org" — and it is what ScopeReach's org branch matches on.
        // BelongsToTenant stamps `organization_id` from the same tenant, so the
        // two agree; this stamps the scope column the trait does not know about.
        // Read straight from TenantContext so it is right regardless of the order
        // the creating listeners fire in. idOrFail() mirrors the trait: creating
        // a plan with no tenant in context is the same error either way.
        static::creating(function (self $plan) {
            $plan->assigned_organization_id ??= app(TenantContext::class)->idOrFail();
        });
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            "A floor plan is the layout of an office — a grid, its walls, and where each "
            .'computer sits — which is configuration the organization keeps for as long as it '
            .'keeps the office, not a record of anything that happened to a person. It names '
            .'no individual: a tile points at a user, and if that user goes the tile simply '
            .'reads as unassigned.'
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'assigned_company_id');
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'assigned_franchise_id');
    }
}
