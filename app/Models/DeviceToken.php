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
 * One handset EzBizi may wake (plan §12).
 *
 * Addressed to a DEVICE, unlike a Notification which is addressed to a person.
 * The two are different on purpose: a person is told once and may read it in
 * four places, so the notification is the fact and this is only an address.
 */
class DeviceToken extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;   // last_registered_at is the only mutation

    public const PLATFORMS = ['android', 'ios'];

    /** The apps a token can belong to; see the add_app_to_device_tokens migration. */
    public const APPS = ['ezbizi', 'ezchat'];

    protected $fillable = [
        'organization_id', 'user_id', 'platform', 'app', 'token', 'last_registered_at', 'created_at',
    ];

    /**
     * Ninety days, and shorter than anything else in the system for a reason.
     *
     * A push token is not a record of something that happened; it is a way to
     * reach a device, and a way to reach a device that nobody has confirmed for
     * three months is most likely a handset that was sold, wiped or reinstalled.
     * Keeping it does not preserve history — there is no history in it — it just
     * keeps a live address for a phone that may now belong to a stranger.
     *
     * The client re-registers on every launch, so ninety days of silence means
     * ninety days of not launching, which is a deleted app rather than a quiet
     * week.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'last_registered_at',
            'Removed once a device stops presenting itself, because an address nobody has '
            .'confirmed for a quarter is more likely a stranger\'s handset than a colleague\'s.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'Which devices a person has EzBizi installed on, so a notification can reach '
            .'them when the app is closed. An address, not a message: nothing about what they '
            .'were told is kept here.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('token, platform', PersonalDataEntry::ACTIVITY,
                'A device identifier issued by Google or Apple, and which of the two issued '
                .'it. It names a handset rather than a person, but it is stored beside one.',
                Erasure::drop('A token is only ever useful for reaching the person it is '
                    .'filed under. With them erased it addresses a device nobody is entitled '
                    .'to notify, which is worse than losing it.')),
            new PersonalDataEntry('last_registered_at', PersonalDataEntry::ACTIVITY,
                'When that device last opened EzBizi — so a rough record of when somebody '
                .'was using the app, which is why it expires rather than accumulating.',
                Erasure::drop('It has no meaning apart from the token it dates.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected function casts(): array
    {
        return [
            'last_registered_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
