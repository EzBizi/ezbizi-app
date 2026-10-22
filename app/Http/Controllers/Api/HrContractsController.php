<?php

namespace App\Http\Controllers\Api;

use App\Models\Contract;
use App\Models\ContractCoverUpload;
use App\Models\ContractVersion;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Branding\Branding;
use App\Support\Contracts\CommonCore;
use App\Support\Contracts\DocumentRenderer;
use App\Support\Contracts\FieldSet;
use App\Support\Contracts\FieldType;
use App\Support\Contracts\FilledBy;
use App\Support\Contracts\MergeTags;
use App\Support\Contracts\PdfRenderer;
use App\Support\Contracts\VersionDiff;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Contracts tab — the template designer
 * (EZBIZI-HR-PLAN.md, "Contracts. The template designer. **This is the schema
 * for everything else.**").
 *
 * A tenant defines the fields; this endpoint must not know what any of them
 * mean. It knows there is a key, a label, a type, who fills it, whether it is
 * required and whether the tenant called it sensitive. It never knows that
 * "bank_account" is a bank account. That is §2.1 applied to the part of the
 * product that varies most between customers, and every refusal below is about
 * the SHAPE of the list rather than about its content.
 *
 * ── The template is TWO things, and both are versioned ──────────
 *
 * The field list — what onboarding asks — and the BODY, the wording of the
 * agreement itself with `{{merge_tags}}` in it (MergeTags). The owner's words
 * when the designer first shipped: *"we are missing something critical which is
 * the contract writing itself, you have the shortcodes but nowhere to put
 * them."* The two are published together, into one immutable row, because an
 * edit to the text changes what somebody is agreeing to exactly as much as an
 * edit to a field does.
 *
 * ── Three endpoints, because editing, previewing and publishing
 *    are different ─────────────────────────────────────────────
 *
 *   PATCH /hr/contracts/{contract}            its name and description, and
 *                                             nothing about the fields or the
 *                                             wording
 *   POST  /hr/contracts/{contract}/preview    an unsaved draft, rendered with
 *                                             sample values; writes nothing
 *   POST  /hr/contracts/{contract}/versions   publishes a field list AND a body
 *
 * `AutomationsController::update` folds both into one PATCH. This does not, and
 * the plan is the reason: "publishing a new version must be an explicit act with
 * a diff a person can read". An endpoint that also renames things is not an
 * explicit act — somebody correcting a typo in the name would publish a version
 * of the schema as a side effect, and every record from that point on would
 * remember a version nobody meant to create. So PATCH refuses `fields` outright
 * rather than ignoring them, and says where they go.
 *
 * ── There is no rollback endpoint, unlike automations ────────────
 *
 * `POST /automations/{a}/activate/{version}` repoints an automation at an older
 * definition, and that is right for a rule: rollback is incident recovery, and
 * the run log records which version each run used, so nothing is misdescribed.
 *
 * A template is a schema, and the same move would be wrong for it. Repointing
 * `current_version_id` backwards changes what every future onboarding asks while
 * producing no entry in the version list — the one place that is supposed to
 * answer "what changed, and when". Undoing a publish is therefore done by
 * publishing the previous field list again, which costs a version number and
 * buys a diff somebody can read. Records are unaffected either way: each one
 * remembers the version that produced it.
 */
class HrContractsController extends Controller
{
    /**
     * The most a cover PNG may weigh, matching BrandingController's limit on a
     * logo.
     *
     * The same number, and the argument lands harder here: a cover is base64'd
     * into EVERY document this template renders, which is a third again in
     * size, on a file that is stored per revision and kept forever. So one
     * heavy image is a heavy one thousands of times over.
     *
     * A megabyte is generous for the flat artwork a front page is made of.
     * Unlike a logo it is NOT cropped to a fixed size first (see `storeCover`),
     * so this cap is the only thing standing between a document and somebody's
     * 12-megapixel photograph — which is why it is a refusal with a sentence
     * rather than a silent resize.
     */
    private const MAX_COVER_KILOBYTES = 1024;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        // The same renderer the signed document goes through — see `preview`.
        private readonly DocumentRenderer $renderer,
        private readonly EventEmitter $events,
        // For the cover's bytes. Never `Storage::disk()` — see FileStore for
        // what naming a disk by hand cost the branding endpoints.
        private readonly FileStore $files,
    ) {}

    // ── the cover page ───────────────────────────────────────────

    /**
     * The validation rules for a cover, wherever one arrives.
     *
     * One array rather than three copies, because a cover may be sent to
     * `store`, to `publishVersion` and to `preview` — and a preview that
     * accepted something publishing refuses would show somebody a document
     * they cannot publish, which is the exact failure `preview`'s docblock
     * spends a paragraph on.
     *
     * `cover` is `nullable` and `sometimes` is deliberately absent from
     * `cover.source`: if a cover object is sent at all it has to say where the
     * mark comes from.
     */
    private static function coverRules(): array
    {
        return [
            'cover' => ['nullable', 'array'],
            'cover.source' => ['required_with:cover', Rule::in(array_keys(ContractVersion::COVER_SOURCES))],
            // The staged upload, for a cover of the tenant's own. Required
            // WITH the file source rather than always: a `logo` cover carries
            // no file, which is the whole of what makes it one press.
            'cover.upload_id' => ['nullable', 'string', 'uuid'],
        ];
    }

    /**
     * The cover a request is asking for, resolved to the bytes that will be
     * pinned — or null for no cover.
     *
     * ═══════════════════════════════════════════════════════════════
     *  TWO SOURCES, AND THE FIRST ONE IS THE POINT.
     *
     *  `logo` CARRIES NO FILE. The mark is already on the platform: it
     *  was uploaded under branding, it is what every badge in the
     *  application draws, and asking somebody to find it again and
     *  upload a second copy would be asking them to make a second home
     *  for one picture. So this reads it and pins a snapshot.
     *
     *  `file` names a staged upload — see ContractCoverUpload for why
     *  the bytes went up separately rather than inside this request:
     *  a contract being CREATED has no id to hang a file off, and a
     *  cover you could only add on the second visit is not the feature
     *  that was asked for.
     *
     *  EITHER WAY THE BYTES ARE COPIED, and that is the decision this
     *  method exists to make true rather than to explain. A version
     *  that pointed at `organizations.logo_path`, or that read the
     *  staged row at render time, would have a first page that changes
     *  after somebody signed it.
     * ═══════════════════════════════════════════════════════════════
     *
     * @return array{source: string, png: string, upload_id: string|null, filename: string|null}|null
     *
     * @throws ValidationException
     */
    private function resolveCover(Request $request): ?array
    {
        $cover = $request->input('cover');

        if (! is_array($cover) || $cover === []) {
            return null;
        }

        if ((string) ($cover['source'] ?? '') === ContractVersion::COVER_LOGO) {
            return [
                'source' => ContractVersion::COVER_LOGO,
                'png' => $this->organizationLogo($request),
                'upload_id' => null,
                'filename' => null,
            ];
        }

        $upload = $this->stagedCover($cover['upload_id'] ?? null);
        $png = $upload->png();

        if ($png === null) {
            throw ValidationException::withMessages(['cover.upload_id' => 'The cover that was uploaded is no longer on the disk, so there is nothing to '
                .'put on the front page. Choose the image again — publishing a version whose '
                .'cover is missing would produce a contract with a blank first page and '
                .'nothing saying why.',
            ]);
        }

        return [
            'source' => ContractVersion::COVER_FILE,
            'png' => $png,
            'upload_id' => $upload->getKey(),
            'filename' => $upload->original_name,
        ];
    }

    /**
     * The staged upload this request names, or a refusal.
     *
     * Scoped to the tenant by `ContractCoverUpload`'s own global scope, so an
     * id from another organization is simply not found — the same answer
     * `assertMine` gives for a contract, and for the same reason: a 403 would
     * confirm that the id is real somewhere on the platform.
     */
    private function stagedCover(mixed $id): ContractCoverUpload
    {
        $upload = is_string($id) && $id !== ''
            ? ContractCoverUpload::query()->find($id)
            : null;

        if ($upload === null) {
            throw ValidationException::withMessages(['cover.upload_id' => 'That cover was not found, so this version has nothing to put on its front '
                .'page. Upload the image again and publish with the id that comes back — a '
                .'cover is part of the version, so it has to be a file this organization '
                .'actually has.',
            ]);
        }

        return $upload;
    }

    /**
     * The mark already uploaded for this business, as bytes.
     *
     * Read through the ORGANIZATION rather than through a company: a contract
     * template reaches every franchise in the organization (ContractPolicy
     * requires standing at the organization to write one), so there is no one
     * company whose logo it would be. A company with none already falls back to
     * the organization's — see BrandingController::company — so this is the
     * mark that badge is showing anyway.
     *
     * Off the signed-in user's organization, which is how BrandingController
     * reaches the same row. `assertMine` has already established that the
     * contract belongs to it, so there is no second organization in play.
     */
    private function organizationLogo(Request $request): string
    {
        $organization = $request->user()->organization;
        $bytes = $organization?->logo_path === null
            ? null
            : $this->files->contents($organization->logo_path);

        if ($bytes === null) {
            throw ValidationException::withMessages(['cover.source' => 'This business has no logo uploaded yet, so there is nothing to put on the '
                .'cover. Upload one under the organization’s branding and it will be offered '
                .'here — or attach a file to this contract instead, which is the other way to '
                .'make a cover.',
            ]);
        }

        return $bytes;
    }

    /**
     * POST /api/v1/hr/contracts/covers — stage a cover image.
     *
     * ── Not hung off a contract, deliberately ────────────────────
     *
     * A contract being CREATED has no id, so an upload bound to `{contract}`
     * would be a cover you could add on the second visit and not the first.
     * The row this writes is the id a publish then names.
     *
     * ── PNG only, and NOT cropped to a logo's shape ──────────────
     *
     * The format rule is Branding's and is the same decision for the same
     * reason: a mark on a page somebody prints is line art and flat colour,
     * which is what PNG is for and what JPEG is worst at, and PNG is the only
     * one of the two that can sit on a page without a white box behind it. SVG
     * is absent because it is a document that can carry script and this is
     * served from our own origin.
     *
     * The SHAPE rule is deliberately not inherited. Branding fixes a logo at
     * 512x512 or 1024x256 so that a row of company marks lines up; a cover is a
     * PAGE, and a page cropped to a 4:1 lockup is not a cover. So any
     * dimensions are accepted and the size cap is what keeps it sane.
     *
     * ── `update` on the contract policy, not `create` ────────────
     *
     * There is no contract to authorize against, so this is gated on the
     * ability to author templates at all — the same permission `preview` uses
     * and for the same reason: staging a cover is part of the authoring act.
     */
    public function storeCover(Request $request): JsonResponse
    {
        $this->authorize('create', Contract::class);

        $request->validate([
            'cover' => ['required', 'file', 'mimes:'.Branding::MIME, 'max:'.self::MAX_COVER_KILOBYTES],
        ], [
            'cover.mimes' => 'A cover has to be a PNG. It is artwork on a page somebody prints '
                .'— PNG keeps hard edges clean and can sit on the page without a white box '
                .'behind it, which is exactly what a JPEG cannot do.',
            'cover.max' => sprintf(
                'A cover has to be under %s KB. It is copied into every document this template '
                .'ever produces and kept with each one, so a heavy image is a heavy one '
                .'thousands of times over.',
                number_format(self::MAX_COVER_KILOBYTES),
            ),
        ]);

        $file = $request->file('cover');

        // Read out of the BYTES, never off the header the browser sent. The
        // `mimes:` rule above checks the extension and the guessed type; this
        // is the one that establishes the file actually decodes as a PNG, and
        // it is the same check BrandingController makes for the same reason.
        $size = @getimagesize($file->getRealPath());

        abort_if(
            $size === false || ($size[2] ?? null) !== IMAGETYPE_PNG,
            422,
            'That file is named like a PNG and does not read as one, so nothing would appear '
            .'on the cover. Export it again as a PNG and upload that.',
        );

        $organizationId = $request->user()->organization_id;
        $disk = $this->files->defaultDisk();

        $upload = new ContractCoverUpload([
            'organization_id' => $organizationId,
            'path' => $this->files->put(
                $file,
                $this->files->directoryFor('contract-covers', $organizationId),
            ),
            'disk' => $disk,
            'original_name' => (string) $file->getClientOriginalName(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by_user_id' => $request->user()->getKey(),
        ]);

        $upload->save();

        // §10: a file arriving on the platform is an act, and this one becomes
        // the front page of an agreement.
        $this->audit->log('contract.cover_uploaded', $upload, metadata: [
            'filename' => $upload->original_name,
            'bytes' => $upload->size_bytes,
        ]);

        return response()->json(['data' => [
            'upload_id' => $upload->getKey(),
            'filename' => $upload->original_name,
            // Where it can be read back. Answered by the server rather than
            // assembled by the client, so the route is decided in one place.
            'image_path' => '/hr/contracts/covers/'.$upload->getKey(),
        ]], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/hr/contracts/covers/{upload} — the staged image itself.
     *
     * `inline`, which is the safe half of that decision and the same one
     * BrandingController makes: the upload has already been established as a
     * PNG by reading its bytes, so rendering it in the page is what it is for.
     *
     * No permission beyond being able to author a template. The tenant scope on
     * ContractCoverUpload is what keeps another organization's cover out of
     * reach, and a 404 rather than a 403 is what stops this confirming that an
     * id is real somewhere on the platform.
     */
    public function showCover(ContractCoverUpload $upload)
    {
        $this->authorize('create', Contract::class);

        abort_unless($upload->organization_id === $this->tenant->idOrFail(), 404);

        return $this->files->serve(
            $upload->path,
            mime: Branding::contentType(),
            disposition: 'inline',
            disk: $upload->disk,
        );
    }

    /**
     * What a publish would do to the cover, in the words the diff screen shows.
     *
     * Not folded into VersionDiff, which compares field lists and bodies and is
     * shared with the version-history screen. This is computed here and sent
     * beside the diff — and it is reported at all for the reason VersionDiff
     * reports a removed body: SENDING NO COVER REMOVES ONE, because a version
     * is the whole template and never a patch of one, and nobody should
     * discover that after publishing.
     *
     * ── It compares the BYTES, not only the source ───────────────
     *
     * Two publishes can both say `upload` and carry different files, and two
     * can both say `logo` across a rebrand — the version pins a
     * copy, so the second one is genuinely a different first page. Comparing
     * only the label would call both of those "no change", and the no-op guard
     * below would then refuse the publish that was the entire point.
     *
     * @param  array{source: string, png: string}|null  $cover
     * @return array{changed: bool, from: string|null, to: string|null}
     */
    private function coverChange(?ContractVersion $current, ?array $cover): array
    {
        $from = $current?->cover_source;
        $to = $cover['source'] ?? null;

        $changed = $from !== $to
            || ($to !== null && $current?->coverPng() !== $cover['png']);

        return ['changed' => $changed, 'from' => $from, 'to' => $to];
    }

    /**
     * GET /api/v1/hr/contracts/catalogue
     *
     * What a template may be built out of: the field types, who can fill a
     * field, the keys the common core has already claimed, which of those are
     * actually asked, and where a cover page's mark may come from.
     *
     * It exists for the same reason `automations/catalogue` and
     * `roles/catalogue` do. The alternative is the client carrying its own copy
     * of the six types and the eight reserved keys, and a client-side copy of a
     * server rule is a second place to forget: the day a type is added, the
     * picker does not offer it, and the day a reserved key changes, the form
     * accepts a key the server then refuses with an error nobody expected.
     *
     * ── RESERVED AND ASKED ARE TWO LISTS, AND THEY WERE ONE ──────
     *
     * Eight keys are refused to a template; six are asked of a person. That
     * difference had no spelling on the wire, so a client had one list and used
     * it for both jobs — which is why the designer showed "Display name" and
     * "Gender" under "Always asked". `asked` and `required` on every entry, and
     * a pre-filtered `core`, are that difference published.
     */
    public function catalogue(): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        /*
         * The common core, published rather than merely enforced, ONCE.
         *
         * A form that lets somebody type `date_of_birth`, submit, and read a
         * refusal has taught them a rule the hard way. The client can grey
         * these out with the sentence explaining why — and the sentence is the
         * server's, so the two cannot drift.
         *
         * `label` and `is` are both sent and they are not interchangeable.
         * `is` completes "it is …" inside `reason`; `label` is what goes on the
         * chip and above the box. Sending only `is` is what had the designer
         * captioning a locked field "their middle name".
         *
         * `asked` and `required` are the two that were missing, and their
         * absence is fact B: nothing on the wire distinguished "a template may
         * not declare this" from "onboarding asks this", so a screen showing
         * the first list under a heading meaning the second was not misreading
         * the payload — the payload did not say.
         */
        $reserved = array_map(
            fn (string $key) => [
                'key' => $key,
                'label' => CommonCore::labelFor($key),
                'is' => CommonCore::describe($key),
                'reason' => CommonCore::refusalFor($key),
                'asked' => CommonCore::isAsked($key),
                'required' => CommonCore::isRequired($key),
            ],
            CommonCore::keys(),
        );

        return response()->json(['data' => [
            /*
             * Keys AND words, because the person configuring this runs a chimney
             * business — the same reason the automation catalogue carries labels.
             *
             * `takes_options` is the third thing each type has to say. The
             * designer decides whether to draw the list-of-answers box, and it
             * decided by comparing the type to the literal `'choice'` in Dart —
             * in the one screen whose docblock says it does not re-state server
             * rules. A seventh type that took options would have been offered
             * with no way to give it any. See FieldType::takesOptions.
             *
             * `merges` is the fourth, and it is here for the same failure with
             * the sign reversed. The body editor offered every live field as an
             * insertable tag, so a template carrying a signature field offered
             * one — and `MergeTags::refusalFor` refuses it with a paragraph
             * about a signature being an act rather than text. The editor
             * cannot ask `merge_tags` for that answer while the contract is
             * being created, because that list comes back from
             * `POST {contract}/preview` and there is no contract yet. So it is
             * on the type, beside the other property the designer reads off it.
             */
            'field_types' => array_map(
                fn (string $key) => [
                    'key' => $key,
                    'label' => FieldType::ALL[$key],
                    'takes_options' => FieldType::takesOptions($key),
                    'merges' => FieldType::merges($key),
                ],
                FieldType::keys(),
            ),
            'filled_by' => self::pairs(FilledBy::ALL),
            // EVERY key a template may not declare — asked or not. Built above,
            // where the reasoning for each property is.
            'reserved_keys' => $reserved,
            /*
             * WHAT ONBOARDING ACTUALLY ASKS — the list a screen may put under
             * a heading like "Always asked", and the reason this key exists.
             *
             * `reserved_keys` above is the longer list and has to be: it is
             * every key a template may not declare, which is what a designer
             * needs to grey one out WHILE somebody is typing it. Two of the
             * eight are reserved without being asked (`name`, `gender`), so a
             * screen that drew the refusal list under "Always asked" was
             * naming two questions nobody is ever asked — and the owner read
             * that as onboarding demanding a middle name of people who have
             * none, which was the one thing on that screen that WAS true and
             * is now fixed a different way (CommonCore::ASKED).
             *
             * FILTERED FROM THE SAME ARRAY, not built beside it. Two loops
             * producing overlapping lists is R6's trap and this module has
             * already paid for it once — `resolvable()` grew a `source` and
             * the catalogue's hand-rolled copy did not. These entries are
             * identical objects, and a test asserts it.
             *
             * `required` is the half a client cannot infer. Middle name is
             * asked and optional; drawn identically to "Last name" it reads as
             * a demand, which is exactly the complaint this came from.
             */
            'core' => array_values(array_filter($reserved, fn (array $e) => $e['asked'])),
            /*
             * WHERE A COVER PAGE'S MARK MAY COME FROM.
             *
             * Published for the same reason the field types are: the
             * alternative is a picker with two hardcoded Dart strings that
             * have to match two PHP constants forever. The shape rules come
             * from Branding, which is the one place the cropper and the
             * validator already agree on, so a cover is held to exactly what a
             * logo is held to and neither list is restated here.
             */
            'cover' => [
                'sources' => self::pairs(ContractVersion::COVER_SOURCES),
                'format' => Branding::MIME,
                'max_kilobytes' => self::MAX_COVER_KILOBYTES,
                // Branding's SHAPES are deliberately absent. A logo is cropped
                // to one of two exact sizes so a row of marks lines up; a cover
                // is a page, and a page cropped to a 4:1 lockup is not one. See
                // `storeCover`.
            ],
            /*
             * How a merge tag is written, and the half of the vocabulary that
             * does not depend on which contract is open.
             *
             * The FIELD half cannot be here — it is different for every template
             * and different again for a template being edited — so the full list
             * comes back from `POST {contract}/preview`, which is the request
             * that knows the field list. What is published here is everything a
             * client would otherwise hardcode: the delimiters, the details every
             * onboarding collects, and the facts.
             *
             * `open` and `close` are the grammar itself, two strings rather than
             * a format string, so that building a tag from a key is wrapping and
             * never parsing an example. They are what a client needs for the one
             * list this server cannot pre-assemble — a template's own questions
             * while the template does not exist yet and there is no `{contract}`
             * to preview against. Everything else arrives with `tag` already
             * written.
             *
             * Sent, not enforced. What any client then does is not asserted here
             * — see MergeTags::resolvable for why a comment on this side of the
             * wire does not get to describe the other.
             */
            'merge_tags' => [
                'open' => MergeTags::OPEN,
                'close' => MergeTags::CLOSE,
                'example' => MergeTags::OPEN.'first_name'.MergeTags::CLOSE,
                'max_body_length' => MergeTags::MAX_BODY_LENGTH,
            ] + self::contractFreeTags(),
            'limits' => [
                'max_fields' => FieldSet::MAX_FIELDS,
                'max_options' => FieldSet::MAX_OPTIONS,
                'max_option_length' => FieldSet::MAX_OPTION_LENGTH,
                'max_body_length' => MergeTags::MAX_BODY_LENGTH,
            ],
        ]]);
    }

    /**
     * The tags that resolve whatever contract is open, in the two groups the
     * picker draws them as.
     *
     * Taken from `MergeTags::resolvable(null)` rather than assembled here. It
     * used to be two `array_map`s over `CommonCore::asked()` and
     * `ContractFacts::keys()` producing the same `{key, tag, label, is}` shapes
     * that `resolvable()` produces one file away — and R6's trap had already
     * sprung: `resolvable()` grew a `source` and these two did not, so the chip
     * for `{{first_name}}` said where it came from when the preview endpoint sent
     * it and said nothing when the catalogue did.
     *
     * A null field list is what makes one home possible: `resolvable()` skips the
     * field loop entirely, so what comes back IS the contract-free half. Grouped
     * here, because the grouping is a property of this response and not of the
     * vocabulary — `preview` sends the same entries as one flat list with
     * `source` on each.
     *
     * @return array{core: list<array<string, mixed>>, facts: list<array<string, mixed>>}
     */
    private static function contractFreeTags(): array
    {
        $groups = [MergeTags::SOURCE_CORE => [], MergeTags::SOURCE_FACT => []];

        foreach (MergeTags::resolvable(null) as $entry) {
            // Anything else would be a field, and there are no fields in a null
            // field list. Skipped rather than dropped into a group it is not.
            if (isset($groups[$entry['source']])) {
                $groups[$entry['source']][] = $entry;
            }
        }

        return [
            'core' => $groups[MergeTags::SOURCE_CORE],
            'facts' => $groups[MergeTags::SOURCE_FACT],
        ];
    }

    /** @param array<string, string> $map */
    private static function pairs(array $map): array
    {
        return array_map(
            fn (string $key, string $label) => ['key' => $key, 'label' => $label],
            array_keys($map),
            array_values($map),
        );
    }

    /** GET /api/v1/hr/contracts */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $contracts = Contract::query()
            ->with('currentVersion')
            ->withCount('versions')
            // An archived contract is history, not something to design or start
            // an onboarding from. The first one is the A1 Tools record the
            // people import creates (2026-09-29); its onboardings still open,
            // because they are reached from the person, not from this list.
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->reject(function (Contract $c) {
                foreach ([
                    ' (hourly, scheduled increases)',
                    ' (salary, scheduled increases)',
                    ' (hourly)',
                    ' (salary)',
                ] as $suffix) {
                    if (str_ends_with($c->name, $suffix)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->map(fn (Contract $c) => $this->present($c));

        return response()->json(['data' => $contracts]);
    }

    /**
     * Another tenant's id is NOT FOUND, never forbidden — the same reasoning
     * AutomationsController states: 403 confirms the id is real somewhere on the
     * platform and 404 does not.
     */
    private function assertMine(Contract $contract): void
    {
        abort_unless($contract->organization_id === $this->tenant->idOrFail(), 404);
    }

    /** GET /api/v1/hr/contracts/{contract} */
    public function show(Contract $contract): JsonResponse
    {
        $this->assertMine($contract);
        $this->authorize('view', $contract);

        return response()->json(['data' => $this->present($contract, detailed: true)]);
    }

    /**
     * POST /api/v1/hr/contracts
     *
     * A contract and its first version, in one transaction. Unlike an
     * automation, it is usable the moment it exists — there is no `is_active`,
     * because a template does nothing on its own. An automation switched on
     * starts acting on records unattended, which is why that one starts off; a
     * template only ever does something when a person chooses it for an
     * onboarding, and that choice is the switch.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Contract::class);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:150', $this->uniqueName()],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'fields' => ['required', 'array'],
            // No `max` here: the limit is MergeTags::MAX_BODY_LENGTH and it is
            // enforced with the sentence explaining why, one line down. A `max`
            // rule as well would be a second copy of the number, and the two
            // would answer with different words.
            'body' => ['nullable', 'string'],
        ] + self::coverRules());

        // Validated before anything is written, and by the same class that will
        // validate every later version — so the first field list is held to
        // exactly the rules the second one will be.
        $fields = FieldSet::fromInput($request->input('fields'));
        $body = MergeTags::normalise($input['body'] ?? null);

        // The wording is checked against the field list it is published WITH,
        // never against the one already stored: they arrive together and they
        // become one immutable version together. The cover is resolved in the
        // same breath and for the same reason.
        MergeTags::validate($body, $fields);

        $cover = $this->resolveCover($request);

        $contract = DB::transaction(function () use ($input, $fields, $body, $cover, $request) {
            $contract = Contract::create([
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'created_by' => $request->user()->getKey(),
            ]);

            $this->publish($contract, $fields, $body, $cover, $input['notes'] ?? null, $request);

            $this->audit->log('contract.created', $contract, metadata: [
                'fields' => count($fields->fields),
                'has_body' => $body !== null,
                'cover' => $cover['source'] ?? null,
            ]);

            return $contract;
        });

        return response()->json(
            ['data' => $this->present($contract->fresh(), detailed: true)],
            Response::HTTP_CREATED
        );
    }

    /**
     * PATCH /api/v1/hr/contracts/{contract}
     *
     * The name and the description. Not the fields, and not the wording — see the
     * class docblock for why publishing is its own act, and why both are refused
     * here rather than quietly dropped.
     */
    public function update(Request $request, Contract $contract): JsonResponse
    {
        $this->assertMine($contract);
        $this->authorize('update', $contract);

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:150', $this->uniqueName($contract)],
            'description' => ['nullable', 'string', 'max:2000'],
            // `missing` rather than absent-by-omission: a client that sends
            // fields here is doing something it believes will take effect, and
            // the reply has to be the reason rather than silence.
            'fields' => ['missing'],
            /*
             * And the body, for a reason that is stronger rather than merely
             * consistent. `description` on this same request is a note ABOUT the
             * template; the body is the agreement ITSELF. Accepting it here would
             * let somebody rewrite what new starters sign while correcting a
             * typo in the name — with no version, no diff, and nothing in the
             * history saying the terms had moved.
             */
            'body' => ['missing'],
            /*
             * And the cover, which is the newest member of the same family and
             * belongs here for the strongest version of the argument. The cover
             * is the FIRST PAGE of the document — see ContractVersion::hasCover
             * — so a cover this endpoint could change would be a signed
             * document whose front page is not the one that was signed.
             */
            'cover' => ['missing'],
        ], [
            'fields.missing' => 'Changing the fields publishes a new version of the template, '
                .'which is a separate, deliberate act — POST to this contract’s /versions '
                .'instead. It answers with a diff of what would change, so nobody publishes a '
                .'new schema while renaming something.',
            'body.missing' => 'Changing the wording publishes a new version too — it is what '
                .'people are agreeing to, so an edit to it is as consequential as an edit to a '
                .'field. POST to this contract’s /versions with the wording and the field list '
                .'together, and /preview first if you want to see it rendered. The description '
                .'here is the note about the template, not the agreement.',
            'cover.missing' => 'Changing the cover publishes a new version too. It is the first '
                .'page of the document, so replacing it changes what the person signing is '
                .'handed — POST to this contract’s /versions with the cover, the wording and '
                .'the field list together. A cover that could be swapped without a version '
                .'would mean a signed contract whose front page is not the one that was signed.',
        ]);

        DB::transaction(function () use ($contract, $input) {
            $contract->fill(array_intersect_key($input, array_flip(['name', 'description'])));

            $this->audit->logChange('contract.updated', $contract);
            $contract->save();
        });

        return response()->json(['data' => $this->present($contract->fresh(), detailed: true)]);
    }

    /** GET /api/v1/hr/contracts/{contract}/versions */
    public function versions(Contract $contract): JsonResponse
    {
        $this->assertMine($contract);
        $this->authorize('view', $contract);

        // Oldest first here, then reversed, because each version's diff is
        // against the one BEFORE it — walking newest-first would need the list
        // twice over to find each predecessor.
        $ordered = $contract->versions()->reorder('version')->with('createdBy:id,name')->get();
        $out = [];
        $previous = null;

        foreach ($ordered as $version) {
            $out[] = $this->presentVersion($version, $contract, $previous);
            $previous = $version;
        }

        return response()->json(['data' => array_reverse($out)]);
    }

    /**
     * POST /api/v1/hr/contracts/{contract}/versions — publish.
     *
     * ── `preview` is what makes this an informed act ─────────────
     *
     * The plan asks for "a diff a person can read" at the moment of publishing.
     * A diff returned only in the 201 is a diff read after the fact, so the same
     * body may be sent with `preview: true`: it validates exactly as a real
     * publish would, computes the same diff by the same code path, and writes
     * nothing. The screen shows what would change, and the person publishes.
     *
     * One code path rather than a separate dry-run endpoint, deliberately — two
     * would eventually disagree, and the one that drifts would be the one saying
     * "nothing will be retired" about a publish that retires something.
     */
    public function publishVersion(Request $request, Contract $contract): JsonResponse
    {
        $this->assertMine($contract);
        $this->authorize('update', $contract);

        $input = $request->validate([
            'fields' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
            'preview' => ['boolean'],
            /*
             * ABSENT MEANS EMPTY, and this is the one place that is dangerous
             * enough to spell out.
             *
             * A version is the whole template, not a patch of one — the field
             * list is sent in full on every publish and the wording is sent the
             * same way. So a client that omits `body` publishes a version with no
             * wording, which drops the document back to the generated form dump.
             * That is the correct reading of "publish this version", and it is
             * exactly why VersionDiff reports `removed` as its own kind of change
             * rather than folding it in with an edit: the person clicking publish
             * sees it named before they click.
             */
            'body' => ['nullable', 'string'],
        ] + self::coverRules());

        $fields = FieldSet::fromInput(
            $request->input('fields'),
            // The whole history, not just the current version — see
            // Contract::pinnedKeyProperties for why the distinction matters.
            $contract->pinnedKeyProperties(),
        );

        $body = MergeTags::normalise($input['body'] ?? null);

        MergeTags::validate($body, $fields);

        /*
         * The cover reads exactly like `body` above, including the dangerous
         * part: ABSENT MEANS NO COVER. A version is the whole template, so a
         * publish that omits the cover publishes a template without one — and
         * that is why `coverChange` below reports it as a change the person
         * clicking publish is shown before they click.
         */
        $cover = $this->resolveCover($request);

        $current = $contract->currentVersion;
        $diff = VersionDiff::between($current?->fieldSet(), $fields, $current?->body, $body);
        $coverChange = $this->coverChange($current, $cover);

        if ($request->boolean('preview')) {
            return response()->json(['data' => [
                'preview' => true,
                'diff' => $diff->toArray(),
                // Beside the diff rather than inside it: VersionDiff compares
                // field lists and bodies and is also what the version-history
                // screen renders, so growing it is a change to a shared shape.
                // Reported here because a publish that silently dropped a cover
                // is exactly what "a diff a person can read" exists to prevent.
                'cover' => $coverChange,
                // The version number this WOULD take, so the screen can name it.
                'version' => (int) ($contract->versions()->max('version') ?? 0) + 1,
            ]]);
        }

        /*
         * An identical field list is refused.
         *
         * Not pedantry: every version is a fork in the history of the schema,
         * and records point at them. A version that changed nothing splits the
         * records into two groups for no reason, and leaves a version list where
         * half the entries answer "what changed here?" with "nothing". If the
         * intent was to record a note against the current version, that is not
         * something a new version should be minted for.
         */
        if ($diff->isEmpty() && ! $coverChange['changed'] && $current !== null) {
            throw ValidationException::withMessages(['fields' => 'That is the field list, the wording and the cover this contract already has, '
                .'so there is nothing to publish. A version that changes nothing still splits '
                .'the records that follow it away from the ones before, and the version list '
                .'stops being a record of what actually changed.',
            ]);
        }

        $version = DB::transaction(function () use ($contract, $fields, $body, $cover, $coverChange, $input, $request, $diff) {
            $version = $this->publish($contract, $fields, $body, $cover, $input['notes'] ?? null, $request);

            /*
             * The diff goes in the audit metadata, not in a column.
             *
             * §10 wants the record of the act, and "what changed" is the act.
             * Storing it on the version as well would be a second copy of
             * something derivable from two immutable rows — and the audit entry
             * is where somebody looks to answer "who added the bank account
             * field and when", which is a different question from "what does
             * version 4 contain".
             */
            $this->audit->log('contract.version_published', $contract, metadata: [
                'version' => $version->version,
                'notes' => $version->notes,
                'diff' => $diff->toArray(),
                // §10 wants the record of the ACT, and swapping the front page
                // of the agreement is one. The two labels, never the bytes.
                'cover' => $coverChange,
            ]);

            /*
             * The event carries the VERSION, not the contract.
             *
             * A version is the thing that was published and the thing that is
             * immutable — the contract row is a name and a pointer at whichever
             * version is current, so an event whose subject were the contract
             * would resolve, months later, to whatever had been published since
             * and would describe the wrong document.
             *
             * The version has no franchise and needs none: a template reaches
             * every franchise in the organization (ContractPolicy requires
             * standing at the organization to write one), so it carries no
             * automation scope and only an organization-wide rule covers it.
             * That is the same answer AutomationEngine gives anything without a
             * franchise, reached deliberately rather than by omission.
             *
             * COUNTS rather than the diff itself. The diff names every field
             * that moved, which for a contract template is the tenant's own
             * wording about pay and hours, and this payload is readable by any
             * webhook a rule points at. The audit entry above holds the whole
             * thing, where it is read by people rather than by rules.
             *
             * `wording_changed` and not a count, because the body is one field
             * and what a rule wants to know about it is whether the terms were
             * rewritten — "when the wording of a contract changes, tell the
             * managers" being the obvious rule.
             */
            $changes = $diff->toArray();

            $this->events->emit('contract.version_published', $version, ['after' => [
                'contract' => $contract->name,
                'contract_id' => $contract->getKey(),
                'version' => $version->version,
                'field_count' => count($version->liveFields()),
                'has_body' => $version->hasBody(),
                'added' => count($changes['added']),
                'changed' => count($changes['changed']),
                'retired' => count($changes['retired']),
                'wording_changed' => $changes['body'] !== null,
                /*
                 * And the cover, for the same reason `wording_changed` is here
                 * rather than a count: a rule wants to know whether the front
                 * page moved, and "when the cover of a contract changes, tell
                 * the managers" is as obvious a rule as the wording one.
                 *
                 * `has_cover` beside it because a rule may want the state
                 * rather than the transition — "warn me if a template is
                 * published without a cover" is a house-style check somebody
                 * will write, and it cannot be derived from `cover_changed`
                 * alone. Labels only; no bytes reach a webhook.
                 */
                'cover_changed' => $coverChange['changed'],
                'has_cover' => $version->hasCover(),
                'cover_source' => $version->cover_source,
            ]], actor: $request->user());

            return $version;
        });

        return response()->json([
            'data' => [
                'contract' => $this->present($contract->fresh(), detailed: true),
                'diff' => $diff->toArray(),
                'cover' => $coverChange,
                'version' => $version->version,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/hr/contracts/{contract}/preview
     *
     * An UNSAVED body and field list, rendered as the document they would
     * produce, with sample values — so the wording can be written while looking
     * at the contract rather than at a text box.
     *
     * ── It renders through the same code as signing ──────────────
     *
     * `DocumentRenderer::sample` and `DocumentRenderer::render` are the same
     * assembler with different values (see that class). So this is not "roughly
     * what it will look like": every merge tag lands where it will land, every
     * escape happens where it will happen, and the signature block is the same
     * block. The alternative — a preview renderer of its own — is the one that
     * drifts, and it drifts silently, and what it drifts about is a document with
     * a signature on it.
     *
     * ── And it refuses exactly what publishing refuses ───────────
     *
     * A tag naming a key that does not exist, or one that was retired, comes back
     * 422 with the tag named, WHILE THE PERSON IS LOOKING AT THE EDITOR. That is
     * the whole reason this endpoint validates instead of rendering something
     * best-effort: finding a typo months later, on somebody's employment
     * contract, is the failure the check exists to prevent, and a preview that
     * quietly rendered around it would put the check back where it started.
     *
     * ── `merge_tags` comes back too, and that is not a convenience ─
     *
     * The list of tags that resolve depends on the field list being EDITED, which
     * only this request has. Without it the client would have to compile its own
     * — from the fields on screen, plus the common core, plus the facts, minus
     * the retired ones, minus the signatures — which is four server rules
     * restated in Dart. It is published here instead, with the exact text to
     * insert.
     *
     * ── Why there is no preview for a contract that does not exist yet ─
     *
     * The route is bound to a contract because the document names one: the
     * business, the contract's own name and its version are three of the four
     * always-available facts. A brand-new template is created first — with or
     * without wording — and previewed from the version after that. Nothing is
     * lost by writing the first body blind, because publishing refuses a bad tag
     * with the tag named rather than accepting it silently.
     */
    public function preview(Request $request, Contract $contract): JsonResponse
    {
        $this->assertMine($contract);
        // The same permission publishing needs, not `view`. This renders a draft
        // of the agreement, which is the authoring act — and §4 says authoring
        // reaches every franchise, so it is gated where authoring is gated.
        $this->authorize('update', $contract);

        $input = $request->validate([
            'fields' => ['required', 'array'],
            'body' => ['nullable', 'string'],
        ] + self::coverRules());

        $fields = FieldSet::fromInput($request->input('fields'), $contract->pinnedKeyProperties());
        $body = MergeTags::normalise($input['body'] ?? null);

        MergeTags::validate($body, $fields);

        // Held to the publish rules, and refused here for the reason the
        // docblock gives about tags: a preview that accepted a cover publishing
        // would refuse is a preview of a document nobody can publish.
        $cover = $this->resolveCover($request);

        // The number this draft WOULD take. A preview is of the next version by
        // definition — it is a draft of something not yet published — so
        // `{{contract.version}}` and the header both say so rather than
        // reprinting the number of the version currently in force.
        $version = (int) ($contract->versions()->max('version') ?? 0) + 1;

        return response()->json(['data' => [
            'version' => $version,
            'content' => $this->renderer->sample(
                // Off the contract rather than off the session: the document
                // names the business the AGREEMENT belongs to, and `assertMine`
                // has already established those are the same one.
                organizationName: (string) $contract->organization->name,
                organizationAddress: (string) ($contract->organization->address ?? ''),
                contractName: (string) $contract->name,
                version: $version,
                fields: $fields,
                body: $body,
                // The DRAFT's cover, from this request. Nothing is stored: a
                // preview writes nothing, and a cover that had to be saved
                // before it could be looked at would be a file belonging to a
                // version that may never exist.
                coverPng: $cover['png'] ?? null,
            ),
            'mime' => DocumentRenderer::MIME,
            /*
             * Named, because a screen that presented this as "the contract"
             * would be showing a draft full of sample values as a document —
             * the same distinction `hr/onboardings/{id}/document` draws with
             * `is_preview`, and drawn with the same word.
             */
            'is_preview' => true,
            'merge_tags' => MergeTags::resolvable($fields),
        ]]);
    }

    /**
     * The current version as a PDF, sample values in every blank and
     * "DRAFT, nothing is signed yet" on every page (Jose, 2026-10-22: a
     * Download on contracts, to send before anybody signs). Gated as preview
     * is: rendering a draft is the authoring act.
     */
    public function draftPdf(Contract $contract): \Illuminate\Http\Response
    {
        $this->assertMine($contract);
        $this->authorize('update', $contract);

        $current = $contract->currentVersion;
        abort_if($current === null, 404, 'This contract has no published version yet.');

        $pdf = app(PdfRenderer::class)->draft($this->renderer->sample(
            organizationName: (string) $contract->organization->name,
            organizationAddress: (string) ($contract->organization->address ?? ''),
            contractName: (string) $contract->name,
            version: (int) $current->version,
            fields: $current->fieldSet(),
            body: $current->body,
            coverPng: $current->coverPng(),
        ), (string) $contract->name);
        $name = Str::slug($contract->name).'-draft.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($pdf),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT, $name, $name),
        ]);
    }

    // ── shared ───────────────────────────────────────────────────

    /**
     * Write the version and point the contract at it.
     *
     * Publishing IS activation, which is the one place this follows
     * `AutomationsController::addVersion` exactly: the new version becomes
     * current in the same transaction that creates it, so there is no moment
     * where a contract has a version nobody is using and no way to say so.
     */
    private function publish(
        Contract $contract,
        FieldSet $fields,
        ?string $body,
        ?array $cover,
        ?string $notes,
        Request $request,
    ): ContractVersion {
        $next = (int) ($contract->versions()->max('version') ?? 0) + 1;

        /*
         * THE COVER'S BYTES ARE COPIED, not pointed at, and this line is where
         * the version stops being able to change underneath a signature.
         *
         * `logo` means the tenant chose the mark already uploaded
         * for the business — they do not upload it twice, which is what the
         * owner asked for. What they get is a SNAPSHOT of it: a version holding
         * `organizations.logo_path` would render a different first page the day
         * somebody rebranded, on contracts that were signed years earlier.
         *
         * The file is never deleted. Nothing deletes a version (see
         * ContractVersion), so nothing may delete the page it renders; the
         * per-organization prefix means erasing an organization still takes it
         * with everything else.
         */
        $coverPath = $cover === null ? null : $this->files->putContents(
            $cover['png'],
            $this->files->directoryFor('contract-covers', $contract->organization_id),
            Branding::MIME,
        );

        $version = ContractVersion::create([
            'contract_id' => $contract->getKey(),
            'version' => $next,
            'fields' => $fields->toArray(),
            // Written in the same statement as the fields, into the same
            // write-once row. That is what makes "the wording is versioned with
            // the questions" a property of the schema rather than a convention.
            'body' => $body,
            // And the cover, in that same statement, for that same reason.
            'cover_source' => $cover['source'] ?? null,
            'cover_path' => $coverPath,
            'cover_upload_id' => $cover['upload_id'] ?? null,
            'cover_filename' => $cover['filename'] ?? null,
            'notes' => $notes,
            'created_by' => $request->user()->getKey(),
        ]);

        $contract->forceFill(['current_version_id' => $version->getKey()])->save();

        return $version;
    }

    /**
     * Names are unique per organization — checked with the tenant column spelt
     * out rather than trusting the global scope, because `Rule::unique` builds
     * its own query and Eloquent's scope never sees it.
     */
    private function uniqueName(?Contract $ignoring = null): Unique
    {
        $rule = Rule::unique('contracts', 'name')
            ->where('organization_id', $this->tenant->idOrFail());

        return $ignoring === null ? $rule : $rule->ignore($ignoring->getKey());
    }

    private function present(Contract $contract, bool $detailed = false): array
    {
        $current = $contract->currentVersion;

        $base = [
            'id' => $contract->id,
            'name' => $contract->name,
            'description' => $contract->description,
            'version' => $current?->version,
            'versions_count' => $contract->versions_count ?? $contract->versions()->count(),
            // What onboarding would ask today, counted. The number people check
            // at a glance, and it is the LIVE count — a list showing retired
            // fields in the total would overstate every template that has ever
            // been tidied up.
            'field_count' => $current === null ? 0 : count($current->liveFields()),
            /*
             * Whether this template has been WRITTEN, or is still only a set of
             * questions.
             *
             * On the list rather than only in the detail, because it is the
             * distinction the owner was pointing at: a template with no wording
             * produces a form dump of answers, which is a record of what was
             * collected and not an agreement. Somebody looking at the list needs
             * to be able to see which of their templates are still in that
             * state without opening each one.
             *
             * A flag rather than the text, because the list is every template in
             * the organization and the text is pages.
             */
            'has_body' => $current?->hasBody() ?? false,
            /*
             * Whether the document opens on a cover page, and where its mark
             * came from.
             *
             * On the LIST as well as the detail, beside `has_body`, and for the
             * same reason that one is: it is a property of the template
             * somebody wants to see across all of them at once — "which of our
             * contracts still go out with no front page" is the question, and
             * opening eleven templates to answer it is not an answer.
             *
             * The source is the LABEL of a source, never a path. The bytes are
             * in the document; nothing about where they sit on the disk belongs
             * on a list screen.
             */
            'has_cover' => $current?->hasCover() ?? false,
            /*
             * The cover itself, in the shape the designer holds it: a source,
             * and for a file the id and name of the upload plus where the
             * bytes can be read. NULL for the ordinary contract, which is one
             * spelling for one state — the same rule the body follows.
             *
             * On the LIST as well as the detail, beside `has_body`, for the
             * same reason that flag is there: "which of our contracts still go
             * out with no front page" is a question about all of them at once,
             * and opening eleven templates is not an answer.
             */
            'cover' => $current?->coverPayload(),
            'updated_at' => $contract->updated_at?->toIso8601String(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            /*
             * The full stored list, retired entries included.
             *
             * The designer has to show a retired field — it is still part of the
             * template, it is what stops the key being reused, and un-retiring it
             * is a legitimate edit. `retired` is on every entry, so the screen
             * can draw the distinction the plan draws.
             */
            'fields' => $current?->fieldSet()->toArray() ?? [],
            /*
             * The agreement's own wording, as it was written — the source text
             * with its tags still in it, not the rendered document.
             *
             * This is what the editor opens. The RENDERED form is what /preview
             * answers and what an onboarding's /document answers; sending either
             * here would be sending a document nobody can edit in place of the
             * thing they came to edit.
             */
            'body' => $current?->body,
            /*
             * And what the tags in it may name, for THIS version's field list.
             *
             * Published rather than compiled by the client — see
             * MergeTags::resolvable. Present as an empty list for a contract with
             * no version rather than absent, for the reason FieldDefinition gives
             * about `options`: empty is an answer, missing is indistinguishable
             * from an older server.
             */
            'merge_tags' => MergeTags::resolvable($current?->fieldSet()),
            'current_version' => $current === null ? null : [
                'id' => $current->id,
                'version' => $current->version,
                'notes' => $current->notes,
                'created_at' => $current->created_at?->toIso8601String(),
            ],
        ];
    }

    private function presentVersion(ContractVersion $version, Contract $contract, ?ContractVersion $previous): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'is_current' => $version->id === $contract->current_version_id,
            'notes' => $version->notes,
            // Per version, because that is the whole point of the cover living
            // here: "what did version 3 look like" has an answer, and it is not
            // "whatever the logo is today".
            'has_cover' => $version->hasCover(),
            'cover' => $version->coverPayload(),
            'created_at' => $version->created_at?->toIso8601String(),
            'created_by' => $version->createdBy?->name,
            'fields' => $version->fieldSet()->toArray(),
            // The wording as this version had it. Sent in full, per version,
            // because "what did version 3 say" is the question this screen
            // exists to answer and a length or a flag does not answer it. It is
            // what MergeTags::MAX_BODY_LENGTH is sized against.
            'body' => $version->body,
            /*
             * What this publish changed, against the version before it.
             *
             * Computed on read from two immutable rows, so it cannot go stale —
             * see VersionDiff. Version 1's diff is everything, because a first
             * version genuinely does add every field it has.
             */
            'diff' => VersionDiff::between(
                $previous?->fieldSet(),
                $version->fieldSet(),
                $previous?->body,
                $version->body,
            )->toArray(),
        ];
    }
}
