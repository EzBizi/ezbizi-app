<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Modules\ModuleRegistry;
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
 *
 * That holds for every row this module writes on purpose, and there is exactly
 * one row it does not write on purpose. `ExceptionAudit` is registered
 * globally in bootstrap/app.php against every unhandled exception, and its
 * payload includes the HTTP verb and the route PATTERN — so a 500 raised while
 * somebody is sending a suggestion leaves an org-visible `AuditEvent` naming
 * the actor and `POST api/v1/suggestions`. No body and no note, but the fact
 * that this person used this module at that minute. It is rare, it is a bug
 * when it happens, and closing it would mean carving a route out of the
 * error stream that §2.2 exists to keep complete — so it is named here rather
 * than left to make the sentence above false.
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
        'organization_id', 'user_id', 'body', 'module', 'files', 'status',
        'decision_note', 'decided_by', 'decided_at',
    ];

    /** The one extra choice beside the module keys: Settings is pinned, not a module. */
    public const MODULE_SETTINGS = 'settings';

    /**
     * Screenshots, photos, a PDF, or a written-up .md/.txt note.
     *
     * `bmp` because the 0.0.0562 Windows app pastes the clipboard's CF_DIB as
     * BMP bytes named pasted-N.png, and `mimes` (the content check) refused
     * them. Newer clients re-encode a paste as PNG; this keeps that release
     * working. A bitmap is a plain raster, not a carrier for script.
     */
    public const FILE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'heif', 'bmp', 'pdf', 'md', 'txt'];

    public const MAX_FILES = 5;

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'files' => 'array'];
    }

    /** What the client is shown of the files: never the disk or the path. */
    public function presentFiles(): array
    {
        return array_map(fn (array $f, int $i) => [
            'index' => $i,
            'name' => $f['name'],
            'mime' => $f['mime'],
            'size' => $f['size'],
        ], $this->files ?? [], array_keys($this->files ?? []));
    }

    /** Label of the tab it is about, or null for "something new". */
    public function moduleLabel(): ?string
    {
        if ($this->module === null) {
            return null;
        }

        if ($this->module === self::MODULE_SETTINGS) {
            return 'Settings';
        }

        return app(ModuleRegistry::class)->get($this->module)?->label ?? $this->module;
    }

    /** For the purge: the bytes go with the row. See Anonymiser::filesOf. */
    public function personalDataFiles(): array
    {
        return array_map(
            fn (array $f) => ['disk' => $f['disk'] ?? null, 'path' => $f['path'] ?? ''],
            $this->files ?? [],
        );
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
     * window that deletes it throws away the only account of that decision.
     *
     * The reason NOT given here is volume. "It only grows when somebody
     * deliberately writes something" was the first draft of this sentence, and
     * it describes the screen rather than the endpoint: `POST /suggestions`
     * takes an authenticated request and nothing throttles it. The authenticated
     * `v1` group carries no rate limiter, so one API token in a loop against a
     * never-expiring table is a growth path the sentence did not cover. What
     * justifies `forever` is the value of the row, not any belief about how many
     * there will be.
     *
     * An earlier draft of this paragraph cited
     * `AppServiceProvider::defineRateLimiters` for the list of what IS limited
     * and named signup, login, forgot-password and the WordPress plugin. That
     * pointer was wrong in both directions and is corrected rather than deleted,
     * because the conclusion above rests on it: that method registers `signup`,
     * `invitation` and `web-plugin`; login and forgot-password are throttled in
     * their own controllers instead, and roughly twenty more routes carry an
     * inline `throttle:N,1`. Read the route file for what is limited, not that
     * method alone.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'An approved suggestion is the reason a feature exists and a denied one is the '
            .'reason it does not; both are the account of a decision, and there is no age at '
            .'which the reason for a shipped feature stops being the reason.'
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
            new PersonalDataEntry('files', PersonalDataEntry::CONTENT,
                'Screenshots and files they attached, which can show anything that was on screen.',
                Erasure::drop('They belong to the suggestion and go with the row above.')),
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

    /**
     * How this table narrows for the inventory: to the READER, not the org.
     *
     * Every other model here answers "how many rows about people in this
     * organization do you hold", and the organization is the right unit
     * because the organization is who may read them. This one is the exception
     * the module exists for. `personalDataPurpose()` above promises, in the
     * same JSON object the number is printed in, that "nobody at your own
     * organization can" read these — and `GET /exports/inventory` is reachable
     * by an owner or an administrator. An organization-wide count under that
     * sentence is the sentence's own refutation: in a three-person company,
     * watching the number move says a suggestion was sent, when, and roughly by
     * whom, and reading it weekly gives the frequency. That is precisely the
     * fact the audit-row exemption in this class's docblock exists to withhold,
     * arriving through a different door.
     *
     * Self-scoped rather than `DataInventory`'s other honest answer, the `-1`
     * that publishes the table's name and says "we hold this and cannot tell
     * you how much". That would also close the leak, and it refuses a question
     * this one can answer truthfully: a count of your OWN suggestions is what
     * "what do you hold about me" asks for, and it does not move when a
     * colleague writes one. An administrator reading their own inventory sees
     * their own suggestions and no evidence of anybody else's, which is the
     * same view every other person in the organization gets.
     *
     * `$organizationId` goes unused on purpose. The tenant scope on this model
     * has already applied it to `$query`, and this narrows further than the
     * organization rather than to it — the parameter is the hook's shape, not
     * a value this answer needs.
     *
     * `whereRaw('0 = 1')` when there is no reader: `DataInventory` is not
     * HTTP-only in principle, and a console or queued caller has no account to
     * scope to. Counting zero is the safe direction — under-reporting a number
     * to nobody, rather than publishing everybody's.
     */
    public static function narrowToOrganization($query, string $organizationId)
    {
        $reader = auth()->user();

        return $reader instanceof User
            ? $query->where('user_id', $reader->getKey())
            : $query->whereRaw('0 = 1');
    }
}
