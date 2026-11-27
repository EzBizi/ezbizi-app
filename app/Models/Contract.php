<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contract template (EZBIZI-HR-PLAN.md, "Contracts: template versus signed
 * artefact").
 *
 * Like `Automation`, this row is a POINTER: a name, and which immutable version
 * is current. Everything that can change lives in `contract_versions`, so a
 * record written last year still reads the questions as they were asked.
 *
 * ── This is the template, never the signed thing ─────────────────
 *
 * The plan draws that line and everything follows from it: "the signed contract
 * is frozen — the rendered PDF and the answers, exactly as agreed. Editing the
 * template never touches it. A signed document that changes retroactively is
 * worthless." Nothing on this model or its versions can reach a signed artefact,
 * which is what makes that true rather than a policy somebody has to remember.
 */
class Contract extends Model
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'hr_contracts';

    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'name', 'description', 'current_version_id', 'created_by',
    ];

    /**
     * Newest first. The version list is read to answer "what changed and when",
     * and the answer people want is almost always about the most recent change.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ContractVersion::class, 'current_version_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every key this contract has EVER published, with the properties a later
     * version may not change.
     *
     * This is the memory that makes key stability enforceable — see
     * FieldSet::checkAgainstHistory. It reads every version rather than only the
     * current one on purpose: the current version already carries every key
     * (removal is refused), but that invariant is exactly what this check
     * establishes, so trusting it here would make the rule depend on itself.
     * A version list is short, and a template that survived a hole in an earlier
     * release is still held to the whole of its history.
     *
     * ── The two properties are combined differently, deliberately ────
     *
     * TYPE: later versions win, so the type compared against is the one most
     * recently published rather than an arbitrary one.
     *
     * SENSITIVE: ORed across every version, so a key that was ever published
     * sensitive stays sensitive. Taking the latest would make the rule
     * self-defeating — a template that slipped through before the ratchet
     * existed, or through any future hole, would have its last version's `false`
     * treated as the truth and the answers stored under the sensitive versions
     * would quietly lose their protection. "Was it ever" is the question the
     * answers actually pose.
     *
     * @return array<string, array{type: string, sensitive: bool}>
     */
    public function pinnedKeyProperties(): array
    {
        $pinned = [];

        /*
         * Oldest first, so the most recent publication of a key is the one left
         * standing. `versions()` is newest-first for the screen's benefit, and
         * reusing it here would silently invert that.
         *
         * `fields` only. This runs on EVERY publish and reads EVERY version, and
         * a version now also carries a body of up to 50,000 characters — so
         * selecting the whole row would drag the full text of every version of
         * the contract through PHP to answer a question about key types. The
         * primary key comes along because Eloquent hydrates models with it.
         */
        foreach ($this->versions()->reorder('version')->get(['id', 'fields']) as $version) {
            foreach ($version->fieldSet()->pinnedProperties() as $key => $properties) {
                $pinned[$key] = [
                    'type'      => $properties['type'],
                    'sensitive' => $properties['sensitive'] || ($pinned[$key]['sensitive'] ?? false),
                ];
            }
        }

        return $pinned;
    }
}
