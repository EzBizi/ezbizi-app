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
 * What somebody thinks the software should do differently.
 *
 * ── Why this module is not in the registry ───────────────────────
 *
 * There is no ModuleDefinition for suggestions and there must not be. Every
 * surface that can take a module away is keyed off a registry entry — the roles
 * editor composes from PermissionCatalogue, ModuleAvailability refuses a flag
 * for a key it cannot find, and the client rail draws me.modules — so a module
 * with no entry has nothing for any of them to point at. Registering it and
 * declining to ship a PlatformModuleFlag would NOT be equivalent: the moment
 * `suggestions.view` exists, an org administrator can switch it off for every
 * role but their own.
 *
 * ── Why nothing here writes an audit row ─────────────────────────
 *
 * Deliberate, and not the silence docs/EZBIZI-TODO.md records against five
 * other modules. The audit trail belongs to the ORGANIZATION, and an audit row
 * saying "Jose created a suggestion" publishes the existence, the timing and the
 * frequency of every private suggestion to exactly the people this record is
 * kept from. The content would stay hidden and the channel would be dead.
 */
class Suggestion extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /** Nobody has read it. The only state in which the author may still edit or withdraw it. */
    public const STATUS_NEW = 'new';

    /** Somebody is reading it. Silent to the author's bell — nothing has been decided. */
    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    /** What `decide` accepts. `new` is absent on purpose — see StaffSuggestionsController. */
    public const DECIDABLE = [self::STATUS_REVIEWING, self::STATUS_APPROVED, self::STATUS_DENIED];

    /** The two that reach the author's bell. */
    public const ANNOUNCED = [self::STATUS_APPROVED, self::STATUS_DENIED];

    protected $fillable = [
        'organization_id', 'user_id', 'body', 'status',
        'decision_note', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Suggestions you have sent EzBizi about the software, and what we answered. '
            .'Only you and EzBizi staff can read them — nobody at your own organization can.';
    }

    /**
     * Kept, deliberately.
     *
     * An approved suggestion is the record of why a feature exists, and a
     * window that deletes it throws away the only account of that decision. The
     * usual reason to expire a table does not apply either: this one takes a row
     * when a person chooses to type one, so it cannot run away.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'An approved suggestion is the reason a feature exists and a denied one is the '
            .'reason it does not; both are the account of a decision, and the table only '
            .'grows when somebody deliberately writes something.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY,
                'Who sent it, so we can tell them what we decided.',
                Erasure::drop('A suggestion has exactly one author. With that person erased '
                    .'there is nobody to answer, and the column is NOT NULL and is what the '
                    .'list is queried by — an anonymised suggestion is one nobody could ever '
                    .'read or withdraw.')),
            new PersonalDataEntry('body, decision_note', PersonalDataEntry::CONTENT,
                'What they asked for, and what we wrote back. Free text, so it can name a '
                .'colleague or a customer rather than only the person who sent it.',
                Erasure::drop('Free text with no schema: nothing can tell which words in it '
                    .'are a name, so it cannot be selectively cleaned. It goes with the row '
                    .'above.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->withoutGlobalScopes()->where('user_id', $subject->getKey())
            : null;
    }
}
