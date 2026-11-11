<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A call's client or booking set by hand on the call report (Jose,
 * 2026-11-03, for Trey). Wins over what the phones, Leads and Workiz say;
 * deleting it reverts.
 */
class CallReportOverride extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const VALUES = ['client' => ['new', 'existing'], 'booking' => ['booked', 'not_booked']];

    protected $fillable = ['organization_id', 'call_id', 'field', 'value', 'set_by'];

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * call id => field => [value, by], for the calls that have one.
     *
     * @param  list<string>  $callIds
     * @return array<string, array<string, array{value: string, by: ?string}>>
     */
    public static function forCalls(array $callIds): array
    {
        $out = [];
        foreach (static::query()->with('setter:id,name')->whereIn('call_id', $callIds)->get() as $o) {
            $out[$o->call_id][$o->field] = ['value' => $o->value, 'by' => $o->setter?->name];
        }

        return $out;
    }

    public static function personalDataPurpose(): string
    {
        return 'Which member of staff set a phone call\'s client type or booking by hand on the call report, and when.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(24, 'created_at',
            'The corrections behind the call report. RingCentral keeps a year of calls, '
            .'so a mark older than two years points at a call nobody can open.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('set_by', PersonalDataEntry::ACTIVITY,
                'Which member of staff set the call\'s client or booking.',
                Erasure::erase()),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('set_by', $subject->getKey())
            : null;
    }
}
