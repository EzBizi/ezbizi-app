<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Access\ScopeReach;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Tenancy\TenantContext;
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
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). A machine is its user's, so the feed asks ScopeReach (ChangeFeed::NARROW). */
    protected static string $changeType = 'monitoring_devices';

    /** Rewritten on every beat (about once a minute): not news; online and offline are (RecordsChanges). */
    protected static array $changeFeedIgnores = [
        'last_seen_at', 'user_agent', 'ip_address', 'specs', 'screen_context', 'capture_last_attempt_at',
    ];


    protected $fillable = [
        'organization_id', 'user_id', 'device_key', 'label', 'platform',
        'capabilities', 'user_agent', 'ip_address', 'last_seen_at',
        'current_module', 'specs', 'screen_context', 'offline_alerted_at',
        'security_alerted_at',
        'capture_last_attempt_at', 'capture_last_error', 'lighting',
    ];

    protected $casts = [
        'capabilities'       => 'array',
        'specs'              => 'array',
        'lighting'           => 'array',
        'screen_context'     => 'array',
        'security_alerted_at' => 'datetime',
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
                .'graphics, drives, uptime, how hard the processor and graphics are '
                .'working, its wifi network and local address, how much it has sent '
                .'and received, how long since someone touched it, whether the screen '
                .'is locked, whether Windows reports its antivirus, firewall and '
                .'updates as healthy, which programs are open, what it has installed '
                .'and which addresses those programs are talking to.',
                Erasure::erase()),

            // Its own column, and its own CATEGORY, for the reason the
            // migration gives: this is what a person is doing, not a fact
            // about the hardware, and the inventory endpoint publishes the
            // category to whoever asks what is held about them.
            new PersonalDataEntry('screen_context', PersonalDataEntry::ACTIVITY,
                'The title of the window in front on a desktop agent, and of every '
                .'open browser tab, which can name what a person is working on. '
                .'Shown only to a role that may also see screenshots, and never '
                .'reported at all by a device exempt from screen monitoring.',
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

    /**
     * §3: devices whose person the actor may see — the reach check on top of
     * `BelongsToTenant`, which already excludes a sibling org's device.
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        return $query->whereHas('user', fn (Builder $u) => $u
            ->where('is_active', true) // removed/erased people are not listed
            ->where(fn (Builder $q) => app(ScopeReach::class)->constrainToVisiblePeople($q, $actor)));
    }

    /**
     * An agent's own device, from its own session, never anyone else's: the
     * three-key (org, user, device_key) resolution HeartbeatController::store
     * uses. The explicit org filter throws when no tenant is set.
     */
    public function scopeOwnedBy(Builder $query, User $user, string $deviceKey): Builder
    {
        return $query->where([
            'organization_id' => app(TenantContext::class)->idOrFail(),
            'user_id' => $user->getKey(),
            'device_key' => $deviceKey,
        ]);
    }
}
