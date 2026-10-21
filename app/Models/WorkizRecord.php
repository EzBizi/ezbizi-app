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
 * One Workiz v2 record that is not a job, client, lead or team member: an
 * invoice, payment, estimate, time off, calendar event, timesheet, custom
 * field or price-book item. Written by SyncWorkizRecords; see the migration
 * for why it is one table.
 */
#[Fillable([
    'franchise_id', 'connection_id', 'type', 'remote_id', 'job_remote_id', 'job_id', 'user_remote_id',
    'starts_at', 'ends_at', 'amount', 'amount_due', 'status', 'remote_updated_at', 'payload', 'synced_at', 'links', 'links_read_at',
])]
class WorkizRecord extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'links' => 'array',
            'links_read_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'amount' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'remote_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A mirror of the money, estimates, time off and hours Workiz holds for the jobs and '
            .'technicians EzBizi already mirrors, kept as long as those are: a payment or an invoice '
            .'outliving its job is the record of what was charged. It names people only by Workiz '
            .'id (a client CL-, a technician USR-), and the personal data behind those ids is held, '
            .'and erased, through `customers` and `workiz_team_members`. Workiz\'s free text on '
            .'these records -- notes, descriptions, a time off\'s reason -- is not copied '
            .'(WorkizRecords::UNCOPIED).'
        );
    }
}
