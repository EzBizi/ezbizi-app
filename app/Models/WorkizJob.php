<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Workiz job, as Workiz reported it.
 *
 * A MIRROR. Nothing in EzBizi edits these rows: the sweep overwrites them on
 * every run, so a local edit here would be reverted with nothing logged. What
 * EzBizi decides about a job belongs on the projected `jobs` row, not here —
 * the same split `leads` makes between `source_status` (the source's word,
 * overwritten every sync) and `status` (ours, never touched by a sweep).
 *
 * That split is why two-way sync does not change this rule. An edit made in
 * EzBizi is pushed to WORKIZ and arrives back here on the next sweep, so this
 * table still only ever holds Workiz's answer. It is never the place an edit
 * lives.
 */
#[Fillable([
    'franchise_id', 'connection_id', 'remote_id', 'serial_id', 'client_id',
    'service_area', 'postal_code', 'city', 'state', 'country', 'address', 'unit',
    'latitude', 'longitude', 'status', 'sub_status', 'job_type', 'job_source',
    'booked_at', 'scheduled_for', 'scheduled_end', 'last_status_update',
    'created_by', 'payload', 'synced_at',
])]
class WorkizJob extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'booked_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'scheduled_end' => 'datetime',
            'last_status_update' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'connection_id');
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A mirror of the source system A-1 is migrating off, kept as long as the jobs and '
            .'customers projected from it are kept — deleting the mirror would strand those rows '
            .'with no provenance for where they came from. The personal data in it (the '
            .'customer\'s name, contact details and service address, which Workiz carries on '
            .'every job because it has no separate client record) is erased by erasing the '
            .'customer it was projected onto: this table holds no person EzBizi does not also '
            .'hold in `customers`, and `customers` decides about itself.'
        );
    }
}
