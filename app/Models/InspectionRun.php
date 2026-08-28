<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
class InspectionRun extends Model implements StatesRetention
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
