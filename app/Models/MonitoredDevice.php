<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One device a person is signed in from, overwritten on each beat — not a log.
 *
 * See PhonePresence for the same shape: one current row per (tenant, person,
 * device), replaced rather than appended.
 */
class MonitoredDevice extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id', 'user_id', 'device_key', 'label', 'platform',
        'capabilities', 'user_agent', 'ip_address', 'last_seen_at',
        'current_module', 'specs', 'offline_alerted_at',
        'capture_last_attempt_at', 'capture_last_error',
    ];

    protected $casts = [
        'capabilities'       => 'array',
        'specs'              => 'array',
        'last_seen_at'       => 'datetime',
        'offline_alerted_at' => 'datetime',
        'capture_last_attempt_at' => 'datetime',
    ];

    /*
     * `capture_last_attempt_at` / `capture_last_error` are deliberately NOT in
     * personalDataHeld(): they describe what the MACHINE can do — the same
     * class of fact as `platform`, `capabilities` and `last_seen_at`, none of
     * which is inventoried either. Adding them would also break the reader:
     * Erasure::erase() writes the literal "Removed" into the column, which is
     * outside the fixed slug vocabulary the roster renders (see
     * HeartbeatController::CAPTURE_REASON).
     */

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'One current row per person per device; it is replaced on each beat, not appended.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'The devices a person is signed in from, so an organization can see who is '
            .'working and on what.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('ip_address', PersonalDataEntry::TECHNICAL,
                'The address a device last connected from, so a session can be recognised.',
                Erasure::erase()),
            new PersonalDataEntry('user_agent', PersonalDataEntry::TECHNICAL,
                'The browser or app build a device reports, to tell sessions apart.',
                Erasure::erase()),
            new PersonalDataEntry('current_module', PersonalDataEntry::ACTIVITY,
                'The tab a person was last on, shown to their organization.',
                Erasure::erase()),
            new PersonalDataEntry('specs', PersonalDataEntry::TECHNICAL,
                'What a desktop agent reports about the machine: processor, memory, '
                .'graphics, drives, uptime, its wifi network and local address, how '
                .'much it has sent and received, how long since someone touched it, '
                .'whether the screen is locked, and which programs are open. On a '
                .'device that is not exempt it also carries the title of the window '
                .'in front, which can name what a person is working on — that one '
                .'key is shown only to a role that may also see screenshots.',
                Erasure::erase()),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
