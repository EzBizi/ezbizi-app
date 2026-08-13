<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WrittenOnce;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Contracts\FieldDefinition;
use App\Support\Contracts\FieldSet;
use App\Support\Contracts\MergeTags;
use App\Support\Files\FileStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An immutable published field list (EZBIZI-HR-PLAN.md, "The template is
 * versioned").
 *
 * Editing a contract publishes a new version and repoints the contract at it.
 * Nothing ever edits one of these, because every employment record remembers
 * which version produced it — and a version that can change turns a signed
 * record into a description of questions nobody was asked.
 *
 * `AutomationVersion` says the same thing about run logs. The consequence is
 * heavier here: a run log that misdescribes itself is a debugging nuisance, and
 * a contract that misdescribes itself is a document somebody signed.
 *
 * ── How far "nothing ever edits one" actually reaches ────────────
 *
 * Two layers, and neither reaches all the way. The `updating` event below covers
 * a model being saved; `WrittenOnce` covers the query builder and the quiet
 * saves that skip events entirely, which is where `ContractVersion::query()
 * ->update(['fields' => …])` used to go straight through. Raw SQL beneath
 * Eloquent is not covered, and WriteOnceBuilder's docblock says why that is
 * where the line falls rather than leaving it to be assumed.
 */
class ContractVersion extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use WrittenOnce;

    public const UPDATED_AT = null;   // versions are written once

    /**
     * Never, and the reason is the same one that makes the class written-once.
     *
     * A version is what somebody signed. Retiring a field produces a NEW version
     * and the old one stays, because an onboarding pins the version it began
     * under and its answers are unreadable without the questions. An age-based
     * window would delete the oldest version first — which is the one the
     * longest-standing contracts are pinned to.
     *
     * It grows one row per publish, which is a human act, so the growth is
     * bounded by how often people edit templates rather than by traffic.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the organization exists. A version is the exact set of '
            .'questions an onboarding was pinned to, and deleting one would leave the '
            .'answers already given to it unreadable — a signed contract nobody can '
            .'reproduce.'
        );
    }

    /**
     * Where the cover's mark came from.
     *
     * PROVENANCE, and never a pointer. See `hasCover()` for why the bytes are
     * this version's own copy whichever of the two was chosen.
     */
    public const COVER_LOGO = 'logo';
    public const COVER_FILE = 'file';

    /** The two answers, and the sentence each one puts on a screen. */
    public const COVER_SOURCES = [
        self::COVER_LOGO => 'The logo already uploaded for this business',
        self::COVER_FILE => 'A file uploaded for this contract',
    ];

    protected $fillable = [
        'organization_id', 'contract_id', 'version', 'fields', 'body', 'notes', 'created_by',
        'cover_source', 'cover_path', 'cover_upload_id', 'cover_filename',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Contract versions are immutable. Changing a template means publishing a new '
                .'version — editing this one would rewrite the questions every record produced '
                .'by it claims to have been asked.'
            );
        });

        /*
         * And no deletes, for a reason that is not symmetry.
         *
         * `contract_signatures.contract_version_id` is `cascadeOnDelete`, so one
         * ordinary `$version->delete()` removed the published field list AND
         * every signature taken against it — the evidentiary record this module
         * is built around, gone, with no trace of the signature or of its
         * removal. It also left `contracts.current_version_id` pointing at
         * nothing, which the migration explicitly relies on never happening: it
         * omits a foreign key there on the argument that "nothing ever deletes a
         * version". That argument was a convention plus a guard against the
         * wrong verb. This is the guard it needed.
         */
        static::deleting(function () {
            throw new RuntimeException(
                'A published version cannot be deleted. Records made under it name it, and '
                .'the signatures taken against it hang off it — removing one would take '
                .'them with it. Publish a new version instead; the old one stays because '
                .'somebody agreed to it.'
            );
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fieldSet(): FieldSet
    {
        return FieldSet::fromStored($this->fields);
    }

    /**
     * Does this version carry the agreement's own wording?
     *
     * The one question that decides which of the two documents DocumentRenderer
     * produces, asked here so that both the renderer and the API presenter ask it
     * the same way — including the "a body of whitespace is no body" rule, which
     * is MergeTags::normalise's and must not be re-invented at either site.
     */
    public function hasBody(): bool
    {
        return MergeTags::normalise($this->body) !== null;
    }

    /**
     * Does this version put a cover page in front of the agreement?
     *
     * ═══════════════════════════════════════════════════════════════
     *  THE COVER BELONGS TO THE VERSION. THAT IS THE WHOLE DESIGN.
     *
     *  A version is what somebody signed, and this class refuses to be
     *  updated or deleted for exactly that reason. The cover is the
     *  document's FIRST PAGE — the business's mark, its name, the name
     *  of the agreement — so it is part of what was signed as much as
     *  any clause is.
     *
     *  Had the cover hung off the CONTRACT instead, it would be a
     *  mutable value the renderer reads. Somebody rebrands, uploads a
     *  new mark, and every contract already signed under that template
     *  starts rendering with a different first page than the one that
     *  was agreed to. Nothing would fail; the documents would simply
     *  stop being the documents.
     *
     *  So CHANGING A COVER PUBLISHES A NEW VERSION, exactly like
     *  changing the wording, and for the same reason: the two are the
     *  same kind of edit to the same artefact. HrContractsController
     *  will not let a publish through that changes nothing, and it
     *  counts a changed cover as a change.
     *
     *  This is also why `cover_source` is provenance and not a pointer.
     *  `logo` says the tenant chose the mark already uploaded for the
     *  business rather than uploading it again — which is what the
     *  owner asked for — and the BYTES are copied here at publish.
     *  `organizations.logo_path` is replaced and cleared by
     *  BrandingController; a version reading it live would have a cover
     *  that changes after signature, which is the failure above wearing
     *  a different hat.
     * ═══════════════════════════════════════════════════════════════
     */
    public function hasCover(): bool
    {
        return $this->cover_path !== null;
    }

    /**
     * The cover as the wire describes it, or null when there is none.
     *
     * The shape the designer reads: a source, and for an uploaded file the id
     * and name of the upload it came from plus where its bytes can be fetched.
     * A `logo` cover answers no path — the client already has one for the
     * business's mark and drawing it through the branding endpoint is what
     * every other badge in the application does.
     *
     * @return array<string, string|null>|null
     */
    public function coverPayload(): ?array
    {
        if (! $this->hasCover()) {
            return null;
        }

        if ($this->cover_source === self::COVER_LOGO) {
            return ['source' => self::COVER_LOGO];
        }

        return [
            'source'    => self::COVER_FILE,
            'upload_id' => $this->cover_upload_id,
            'filename'  => $this->cover_filename,
            // Null when the staged row has gone (the foreign key nulls rather
            // than cascades — see the migration). The document is unaffected:
            // it renders from this version's own pinned copy. What is lost is
            // the thumbnail, and a screen that is told "no path" draws an icon
            // rather than an invented URL.
            'image_path' => $this->cover_upload_id === null
                ? null
                : '/hr/contracts/covers/'.$this->cover_upload_id,
        ];
    }

    /**
     * The cover's bytes — a PNG — or null when there is no cover or the file
     * has gone.
     *
     * Null for BOTH, deliberately. `storage/` is outside the nightly backup
     * (see FileStore), so a rebuilt instance has the rows and not the files; a
     * renderer that threw on a missing cover would refuse to produce the
     * agreement over a missing decoration. It renders without the mark instead,
     * which is a document somebody can still read and sign.
     *
     * Resolved through the container rather than an injected FileStore because
     * this is a model: every other caller builds one of these from a query, and
     * a constructor dependency on a storage service would have to be threaded
     * through Eloquent's own hydration.
     */
    public function coverPng(): ?string
    {
        if ($this->cover_path === null) {
            return null;
        }

        return app(FileStore::class)->contents($this->cover_path);
    }

    /**
     * The definition of one key, retired or not.
     *
     * This is what "a retired field stays readable on records that carry it"
     * means in code: a record was produced by a version, and that version can
     * always still say what its keys were called and how to read them. The
     * lookup deliberately does NOT skip retired fields — a record carrying an
     * answer to a field that has since been retired still needs its label.
     */
    public function field(string $key): ?FieldDefinition
    {
        return $this->fieldSet()->keyed()[$key] ?? null;
    }

    /**
     * What a NEW onboarding asks. Retired fields are gone from here and nowhere
     * else — that is the entire difference between retiring and deleting.
     *
     * @return FieldDefinition[]
     */
    public function liveFields(): array
    {
        return array_values(array_filter(
            $this->fieldSet()->fields,
            fn (FieldDefinition $f) => ! $f->retired,
        ));
    }
}
