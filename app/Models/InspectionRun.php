<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use App\Models\Concerns\HasAttachments;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's in-progress draft of one named inspection for one customer/job.
 *
 * Personal: Rodrigo cannot see Carlos's draft. Unique on
 * (user_id, inspection_definition_id, scope_key) so two customer-only drafts
 * cannot hide behind SQL NULL. Auto-saved; discarded on start-again; removed
 * when submitted (the frozen copy is the report).
 */
class InspectionRun extends Model implements CarriesAutomationScope, StatesRetention
{
    use BelongsToTenant;
    use HasAttachments;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A personal draft. It is discarded when the person starts again, removed when '
            .'they submit (the report is what remains), and taken with the organization. '
            .'It is not evidence — that is the report — and it is not shared with anyone else.'
        );
    }

    protected $fillable = [
        'organization_id', 'user_id', 'customer_id', 'job_id',
        'inspection_definition_id', 'inspection_definition_version_id',
        'scope_key', 'answers',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
        ];
    }


    /**
     * Where this sits, for an automation rule and for who gets notified.
     *
     * ── What this closes ────────────────────────────────────────
     *
     * `NotificationDispatcher::audienceFor` falls through to EVERY ACTIVE USER
     * in the organization when the subject of an event cannot say where it is —
     * no `CarriesAutomationScope`, no `franchise_id`, no `owning_franchise_id`.
     * This model has none of those columns, so every event it raised was in
     * that fallback, and a tenant may attach `notify` to any raised trigger.
     *
     * ── The job first, then the customer ────────────────────────
     *
     * A job names a franchise outright and is the stronger answer. A
     * customer-only inspection is a real case rather than a defensive one — the
     * picker allows starting against a client with no job — and its customer's
     * `owning_franchise_id` is where that work belongs.
     *
     * `withTrashed()` on the job, and it is not tidiness: a job soft-deletes
     * and this record outlives it. Read through the default scope, a deleted
     * job answers null and the record falls back to the whole organization —
     * so the very cases most likely to be discussed loudly would land in the
     * widest possible audience.
     *
     * Never `['company', …]`: an inspection is always done somewhere specific.
     * `[null, null]` only when neither a job nor a customer survives, which
     * means the thing it was about is gone and only an organization-wide rule
     * can sensibly cover what is left.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function automationScope(): array
    {
        $franchiseId = $this->job_id === null
            ? null
            : Job::withTrashed()->whereKey($this->job_id)->value('franchise_id');

        $franchiseId ??= $this->customer_id === null
            ? null
            : Customer::withTrashed()->whereKey($this->customer_id)->value('owning_franchise_id');

        return $franchiseId === null ? [null, null] : ['franchise', $franchiseId];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(InspectionDefinition::class, 'inspection_definition_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InspectionDefinitionVersion::class, 'inspection_definition_version_id');
    }
}
