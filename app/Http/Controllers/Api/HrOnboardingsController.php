<?php

namespace App\Http\Controllers\Api;

use App\Models\Assignment;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\Franchise;
use App\Models\Invitation;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\OnboardingDocument;
use App\Models\User;
use App\Notifications\PaperworkReadyToFinish;
use App\Services\Hr\OnboardingDocuments;
use App\Services\Hr\OnboardingForm;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Contracts\Ceremony;
use App\Support\Contracts\CommonCore;
use App\Support\Contracts\DocumentFingerprint;
use App\Support\Contracts\DocumentRenderer;
use App\Support\Contracts\FieldDefinition;
use App\Support\Contracts\FilledBy;
use App\Support\Contracts\PdfRenderer;
use App\Support\Hr\CannotActOnInvitation;
use App\Support\Hr\InvitationActions;
use App\Support\Hr\OnboardingToRecord;
use App\Support\Hr\TaxForms;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Privacy\SensitiveValues;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Onboarding — the two-sided form, the signature and the document
 * (EZBIZI-HR-PLAN.md, "Onboarding").
 *
 * The last piece of HR and the one the rest was built for. Invitations produce
 * the person, Contracts produce the questions, and this renders one against the
 * other, stores the answers with provenance, takes the signature and keeps the
 * document.
 *
 * ── The two sides, and where each rule lives ─────────────────────
 *
 *   OnboardingPolicy       may this person touch this onboarding at all
 *   OnboardingForm         may they write THIS FIELD, and is the value usable
 *   Onboarding::partiesFor which side they are on
 *
 * Split that way because the three answers have different shapes. Whether
 * somebody may open an onboarding is a question about them and the record;
 * whether they may set `bank_account` is a question about a row of a JSON column
 * that a tenant wrote last Tuesday. A policy cannot answer the second, and a
 * controller that tried would be the only place the rule existed.
 *
 * ── Save freely, submit once ─────────────────────────────────────
 *
 * PATCH validates FORMAT and refuses fields the caller does not own; it never
 * asks whether the form is finished. Somebody halfway through, waiting on a
 * document they have to go and scan, has to be able to put it down. Completeness
 * is asked once, at the first signature, which is the act that says "this is
 * what we agreed".
 *
 * From that moment the answers are frozen. A document somebody has signed cannot
 * go on changing underneath them — that is what the fingerprint is for — so a
 * correction after signing is an AMENDMENT, which keeps both documents and says
 * who changed what.
 *
 * ── Why signing sends back a hash ────────────────────────────────
 *
 * `document_hash` on the sign request is what makes "fingerprint exactly what
 * was shown" true rather than asserted. The signer's client has just been handed
 * the document; it sends back the fingerprint of what it received, and the
 * server refuses if that is not the document it is about to record a signature
 * against. Without it, the server would be recording a hash of ITS OWN idea of
 * the document and calling that evidence of what somebody read.
 */
class HrOnboardingsController extends Controller
{
    public function __construct(
        private readonly OnboardingForm $form,
        private readonly OnboardingDocuments $documents,
        private readonly ScopeReach $reach,
        private readonly AuditLogger $audit,
        private readonly SensitiveValues $sensitive,
        private readonly NotificationDispatcher $notifications,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
        private readonly InvitationActions $actions,
    ) {}

    /**
     * GET /api/v1/hr/onboardings
     *
     * What is in flight. Filtered to reach in SQL rather than afterwards, for the
     * reason ScopeReach::constrain sets out — a list cannot be filtered row by
     * row and paginated at the same time.
     *
     * ── Not gated on `hr.view`, and that is the point ────────────
     *
     * A new starter holds no HR permission whatever, and their own onboarding is
     * the one screen in this module they must be able to reach. So the list is
     * not refused; it is FILTERED. Somebody with `hr.view` sees everything their
     * reach covers plus their own; somebody with nothing sees their own and
     * nothing else, which for most of the organization is an empty list. There
     * is no disclosure either way, because the predicate is the authorization.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $mine = $actor->getKey();

        // The policy, asked as a question rather than as a gate — the same
        // `viewAny` an employer would be refused on, used here to decide how
        // wide the list is.
        $mayViewOthers = Gate::forUser($actor)->allows('viewAny', Onboarding::class);

        /*
         * An onboarding with no invitation is visible only from the organization.
         *
         * There is no path that creates one today — `store()` requires an
         * invitation — so this is the conservative reading held ready for the
         * second-contract-for-an-existing-employee case: visible to somebody
         * standing at the top, invisible to everybody else, rather than visible
         * to all because nothing narrower could be worked out.
         */
        $reachesOrganization = $this->reach->covers(
            $actor, Assignment::SCOPE_ORGANIZATION, $actor->organization_id,
        );

        $query = Onboarding::query()
            ->with(['user:id,name,email', 'contractVersion.contract:id,name',
                'invitation:id,email,first_name,last_name,assigned_organization_id,'
                .'assigned_company_id,assigned_franchise_id'])
            ->where(function (Builder $visible) use ($actor, $mine, $mayViewOthers, $reachesOrganization) {
                $visible->where('user_id', $mine);

                if (! $mayViewOthers) {
                    return;
                }

                // Through the invitation, which is where the placement was
                // decided and the only table on this path carrying the three
                // `assigned_*` columns constrain() understands.
                $visible->orWhereHas('invitation', fn (Builder $i) => $this->reach->constrain($i, $actor));

                if ($reachesOrganization) {
                    $visible->orWhereNull('invitation_id');
                }
            });

        // Every status counted across all pages, before a filter narrows the
        // list - the tiles above it (HR lists, layout A, Mia 2026-10-02).
        $tiles = (clone $query)->toBase()->reorder()
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')
            ->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();

        $query
            ->when(
                $request->query('status'),
                fn (Builder $q, string $status) => $q->where('status', $status),
            )
            // In progress apart from finished, so done forms are their own
            // archive (Andy, a Suggestion Jose said to build, 2026-10-01:
            // "a seperate archieved for all the forms that are already done").
            ->when(
                $request->query('state'),
                fn (Builder $q, string $state) => $q->whereIn('status', $state === 'finished'
                    ? [Onboarding::COMPLETED, Onboarding::CANCELLED]
                    : Onboarding::OPEN),
            )
            ->when(
                $request->query('user_id'),
                fn (Builder $q, string $userId) => $q->where('user_id', $userId),
            );

        $onboardings = $query
            ->withExists('documents')
            // Newest first: the list exists to be acted on, and the thing most
            // likely to need chasing is the thing just started.
            ->orderByDesc('started_at')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($onboardings->items())->map(fn (Onboarding $o) => $this->summarise($o)),
            'meta' => [
                ...$this->pageMeta($onboardings),
                'tiles' => [
                    Onboarding::IN_PROGRESS => $tiles[Onboarding::IN_PROGRESS] ?? 0,
                    Onboarding::SUBMITTED => $tiles[Onboarding::SUBMITTED] ?? 0,
                    Onboarding::AWAITING_APPROVAL => $tiles[Onboarding::AWAITING_APPROVAL] ?? 0,
                    Onboarding::COMPLETED => $tiles[Onboarding::COMPLETED] ?? 0,
                    Onboarding::CANCELLED => $tiles[Onboarding::CANCELLED] ?? 0,
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/hr/onboardings — start one.
     *
     * An invitation and a contract. The version is PINNED here, at the moment the
     * form begins, and never looked up again: publishing version 5 this afternoon
     * must not re-word a question somebody answered this morning.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Onboarding::class);

        $actor = $request->user();

        $input = $request->validate([
            'invitation_id' => ['required', 'uuid', Rule::exists('invitations', 'id')
                ->where('organization_id', $this->tenant->idOrFail())],
            // Not an archived one: the list no longer offers it, and a request
            // that names it anyway is starting new paperwork on a record of the
            // past — the A1 Tools import's contract is the case this exists for.
            'contract_id' => ['required', 'uuid', Rule::exists('contracts', 'id')
                ->where('organization_id', $this->tenant->idOrFail())
                ->whereNull('archived_at')],
            // Under the organization (null) or a company: the employer the
            // contract names, where it is filed, where the person is assigned
            // (Ana's Suggestion; Jose, 2026-10-01). Absent: where the
            // invitation already places them.
            'company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')
                ->where('organization_id', $this->tenant->idOrFail())],
        ]);

        $invitation = Invitation::findOrFail($input['invitation_id']);
        $contract = Contract::with('currentVersion')->findOrFail($input['contract_id']);

        // The same §4 bound inviting is held to, asked again here because
        // starting somebody's paperwork is acting on their placement.
        $this->reach->assertCovers(
            $actor, $invitation->scopeType(), $invitation->scopeId(), 'invitation_id',
        );

        // What STARTING means lives in InvitationActions now, because
        // Automations causes the same act from a rule and a second copy would
        // have drifted the first time one changed. The four state rules moved
        // in with it — already carrying paperwork, withdrawn, lapsed, no
        // signature field. Everything above this line is the ACTOR half, which
        // a rule has no answer for and this controller must still ask.
        //
        // Converted to the refusal shape this endpoint has always answered
        // with: 422 carrying an `errors` key the client reads in preference to
        // `message`. `invitation_id` for every one of them, because that is
        // the field the old messages were attached to.
        $chosen = array_key_exists('company_id', $input);
        if ($chosen) {
            $input['company_id'] === null
                ? $this->reach->assertCovers($actor, Assignment::SCOPE_ORGANIZATION, $actor->organization_id, 'company_id')
                : $this->reach->assertCovers($actor, Assignment::SCOPE_COMPANY, $input['company_id'], 'company_id');
        }

        try {
            $onboarding = DB::transaction(function () use ($invitation, $contract, $actor, $chosen, $input) {
                $onboarding = $this->actions->startOnboarding(
                    $invitation, $contract, $actor, $chosen ? $input['company_id'] : null,
                );
                if ($chosen) {
                    $onboarding->forceFill(['company_id' => $input['company_id']])->save();
                    $this->placeUnder($invitation, $input['company_id']);
                }

                return $onboarding;
            });
        } catch (CannotActOnInvitation $e) {
            throw ValidationException::withMessages([
                $e->field ?? 'invitation_id' => $e->getMessage(),
            ]);
        }

        return response()->json(
            ['data' => $this->detail($onboarding->fresh(), $actor)],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/hr/onboardings/{onboarding} — the form and its answers. */
    public function show(Request $request, Onboarding $onboarding): JsonResponse
    {
        $this->authorize('view', $onboarding);

        return response()->json(['data' => $this->detail($onboarding, $request->user())]);
    }

    /**
     * DELETE /api/v1/hr/onboardings/{onboarding} — stop it.
     *
     * The row stays. Status becomes `cancelled`, which is its own word and not
     * a delete: the organization changed its mind about this paperwork, and
     * that is a different fact from never starting it. Deleting instead would
     * erase that somebody was taken through a form at all.
     *
     * Whatever its state, signed included (Jose, 2026-10-05: "regardless of
     * anything"). What was signed stays on the row; the invitation is then
     * free for a new start.
     */
    public function destroy(Request $request, Onboarding $onboarding): JsonResponse
    {
        $this->authorize('cancel', $onboarding);

        if ($onboarding->isCancelled()) {
            throw ValidationException::withMessages(['onboarding' => 'That onboarding is already cancelled.',
            ]);
        }

        $actor = $request->user();
        $this->actions->cancelOnboarding($onboarding, $actor);

        return response()->json(['data' => $this->detail($onboarding->fresh(), $actor)]);
    }

    /**
     * PATCH /api/v1/hr/onboardings/{onboarding} — save answers, either side.
     *
     * Partial is normal. What is refused is a field belonging to the other party,
     * a value the field's type cannot hold, and any write at all once somebody
     * has signed.
     */
    public function update(Request $request, Onboarding $onboarding): JsonResponse
    {
        $this->authorize('update', $onboarding);

        $this->assertNotCancelled($onboarding);

        $actor = $request->user();

        $request->validate([
            'answers' => ['required', 'array'],
            'answers.'.Onboarding::EMPLOYER_ADDRESS => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'answers.required' => 'Send the answers you are saving as an object of field key '
                .'to value. An empty save is not an error, but it is also not a request worth '
                .'making.',
        ]);

        if ($onboarding->isFrozen()) {
            throw ValidationException::withMessages(['answers' => sprintf(
                'This onboarding was signed on %s and its answers are fixed. A signed document '
                .'that changes afterwards is worth nothing, which is the whole point of the '
                .'fingerprint on it. Correct it with an amendment instead: the original stays '
                .'because it is what was signed, and the amendment is what is true.',
                $onboarding->submitted_at->toDateString(),
            )]);
        }

        $parties = $this->partiesFor($actor, $onboarding);

        // The company address typed here (Jose, 2026-10-05): the employer's,
        // and fixed by the employer's signature like the rest of their side.
        $answers = (array) $request->input('answers');
        $typedAddress = array_key_exists(Onboarding::EMPLOYER_ADDRESS, $answers);
        $address = trim((string) ($answers[Onboarding::EMPLOYER_ADDRESS] ?? '')) ?: null;
        unset($answers[Onboarding::EMPLOYER_ADDRESS]);
        if ($typedAddress && (! in_array(FilledBy::EMPLOYER, $parties, true) || $this->employerSigned($onboarding))) {
            throw ValidationException::withMessages(['answers' => 'Only the employer can set the company address, and only before signing.',
            ]);
        }

        // The employer signs first and the rest is the employee's (Jose,
        // 2026-10-01). From that signature the employer's answers are what
        // was signed, so only the employee's own questions still move.
        if ($this->employerSigned($onboarding)) {
            $locked = array_keys(array_filter(
                $this->ownerByKey($onboarding),
                fn (string $owner) => $owner !== FilledBy::EMPLOYEE,
            ));
            $touched = array_intersect(array_keys((array) $request->input('answers')), $locked);
            if ($touched !== []) {
                throw ValidationException::withMessages(['answers' => 'The employer has signed, so the employer\'s answers are fixed: they are what '
                    .'was signed. Only the employee\'s own questions can still be answered.',
                ]);
            }
        }

        $changed = DB::transaction(function () use ($onboarding, $actor, $parties, $answers, $typedAddress, $address) {
            $changed = $this->form->save($onboarding, $actor, $parties, $answers);

            if ($typedAddress && $onboarding->employer_address !== $address) {
                $onboarding->forceFill([Onboarding::EMPLOYER_ADDRESS => $address])->save();
                $changed['answers'][] = Onboarding::EMPLOYER_ADDRESS;
            }

            if (array_intersect(['work_days', 'work_hours'], $changed['answers']) !== []) {
                app(OnboardingToRecord::class)->saveSchedule($onboarding);
            }

            if ($changed['answers'] !== [] || $changed['identity'] !== []) {
                /*
                 * The KEYS, never the values.
                 *
                 * AuditLogger redacts by column name and cannot help here: these
                 * keys are the tenant's own words, so `iban` and `acct` mean
                 * nothing to its list. An answer may be a bank account, and the
                 * audit table is append-only and read by more people than that
                 * should be — the reveal event is where "somebody looked at it"
                 * belongs, and it stores the field name for exactly this reason.
                 */
                $this->audit->log('onboarding.answers_saved', $onboarding, metadata: [
                    'fields' => $changed['answers'],
                    'identity' => $changed['identity'],
                    'party' => $parties,
                ]);
            }

            return $changed;
        });

        return response()->json([
            'data' => $this->detail($onboarding->fresh(), $actor),
            'changed' => $changed,
        ]);
    }

    /**
     * POST /api/v1/hr/onboardings/{onboarding}/preview — the contract as it
     * would read with the unsaved answers in `answers`, for the page that shows
     * it beside the form as somebody types (Jose, 2026-10-01). Writes nothing
     * and stores nothing; saved sensitive answers stay masked.
     */
    public function preview(Request $request, Onboarding $onboarding): JsonResponse|HttpResponse
    {
        $this->authorize('view', $onboarding);

        $input = $request->validate([
            'answers' => ['present', 'array'],
            // Keys whose values are marked, so somebody typing sees where
            // their answer lands (Jose, 2026-10-01). Preview only.
            'highlight' => ['sometimes', 'array'],
            'highlight.*' => ['string', 'max:64'],
        ]);
        $draft = $input['answers'];

        // ?format=pdf: the "final result" view, the PDF it would print as.
        if ($request->query('format') === 'pdf') {
            $pdf = $this->documents->previewPdf($onboarding, $draft);

            return response($pdf, Response::HTTP_OK, [
                'Content-Type' => PdfRenderer::MIME,
                'Content-Length' => (string) strlen($pdf),
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT, 'draft-contract.pdf', 'draft-contract.pdf',
                ),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $content = $this->documents->preview($onboarding, $draft, $input['highlight'] ?? []);

        return response()->json(['data' => [
            'content' => $content,
            'mime' => DocumentRenderer::MIME,
            'content_hash' => DocumentFingerprint::of($content),
        ]]);
    }

    /**
     * GET /api/v1/hr/onboardings/{onboarding}/document
     *
     * The document, and its fingerprint. Before anything is signed this is a
     * PREVIEW, rendered on the spot; afterwards it is the bytes on the disk.
     *
     * ── Why JSON rather than the file ────────────────────────────
     *
     * Two reasons, and the second is the one that decides it. A preview has no
     * attachment to serve, so a file response could only exist for half the
     * lifecycle. And the caller's next act is to sign, which means it has to send
     * the fingerprint of what it received — so the hash has to arrive beside the
     * bytes, as data, rather than in a header somebody has to know to read.
     *
     * Downloading a copy is a different act with an endpoint that already exists:
     * every stored revision publishes its `attachment_id`, and
     * `GET /attachments/{id}` serves the bytes through the same authorization as
     * this onboarding. That is retrievability, and it is deliberately the
     * ordinary file path rather than a special one for contracts.
     */
    public function document(Request $request, Onboarding $onboarding): JsonResponse
    {
        $this->authorize('view', $onboarding);

        // Reading the document is a reveal, and is authorized and audited as one.
        // See the method for what happened when it was not.
        $this->assertMayReadTheDocument($onboarding, 'contract document');

        $revision = $request->query('revision');

        // Not frozen means somebody still has to sign the form as it stands
        // (the employee, after the employer pre-signed), so that is what is
        // shown, rather than the employer's earlier document.
        $document = match (true) {
            $revision !== null => $onboarding->documents()->where('revision', (int) $revision)->first(),
            $onboarding->isFrozen() => $onboarding->currentDocument(),
            default => null,
        };

        if ($document === null) {
            if ($revision !== null) {
                abort(Response::HTTP_NOT_FOUND);
            }

            $content = $this->documents->render($onboarding);

            return response()->json(['data' => [
                'stored' => false,
                'revision' => null,
                'attachment_id' => null,
                'content' => $content,
                'mime' => DocumentRenderer::MIME,
                'content_hash' => DocumentFingerprint::of($content),
                'hash_algorithm' => DocumentFingerprint::ALGORITHM,
                'content_length' => strlen($content),
                // Nothing is signed yet, so this is what the form currently says
                // and not an artefact. Named so, because a screen that presented
                // it as "the contract" would be showing a draft as a document.
                'is_preview' => true,
            ]]);
        }

        $content = $this->documents->contentsOf($document);

        abort_if($content === null, Response::HTTP_NOT_FOUND);

        return response()->json(['data' => $document->present() + [
            'stored' => true,
            'content' => $content,
            'mime' => DocumentRenderer::MIME,
            'is_preview' => false,
            /*
             * Checked on every read, not only when somebody asks.
             *
             * This is the tamper-evidence being USED rather than merely stored.
             * A false here means the bytes on the disk are not the bytes this
             * revision recorded, which is worth knowing at the moment somebody
             * opens the document rather than years later in a dispute.
             */
            'intact' => $document->stillMatches($content),
        ]]);
    }

    /**
     * GET /api/v1/hr/onboardings/{onboarding}/document/pdf
     *
     * A PDF COPY of a stored revision — the bytes, as a download, not JSON.
     *
     * ── What this is not ─────────────────────────────────────────
     *
     * It is not a second contract. The signed instrument is the HTML document
     * `document()` above answers with; this is converted from that document's
     * stored bytes, says on its face that it is a copy, and is kept by nobody.
     * DocumentRenderer and PdfRenderer both argue that at length, and the
     * argument is the reason this endpoint has the shape it has:
     *
     *   - there is NO PDF OF A PREVIEW. Before the first signature nothing is
     *     stored, so there are no bytes to convert, and converting a fresh
     *     render instead would be exactly the second artefact the design
     *     forbids. It refuses, in a sentence that says what to do.
     *   - the copy is refused when the stored document does not match its own
     *     fingerprint. `document()` reports `intact: false` and still answers,
     *     because it is showing you the record along with the verdict on it. A
     *     PDF is different in kind: it leaves the building, it asserts on its
     *     face that it is a copy of a signed document, and it must not make
     *     that claim about bytes this server can no longer vouch for.
     *
     * ── The same door, with the same lock and the same log ───────
     *
     * Reading somebody's signed contract is a reveal whichever format it comes
     * out in — the answers are in the clear in both — so this goes through
     * `assertMayReadTheDocument()`, the identical call `document()` makes, in the
     * identical place. Adding a format must not add a way round the mechanism;
     * that is precisely what `GET /attachments/{id}` was before it was closed.
     *
     * The `via` on the audit entry differs and only that, because which artefact
     * left the building is worth being able to read back later.
     */
    public function documentPdf(Request $request, Onboarding $onboarding): HttpResponse
    {
        $this->authorize('view', $onboarding);

        $this->assertMayReadTheDocument($onboarding, 'contract document (PDF copy)');

        $revision = $request->query('revision');

        $document = $revision === null
            ? $onboarding->currentDocument()
            : $onboarding->documents()->where('revision', (int) $revision)->first();

        // Signed elsewhere and imported: the original PDF is the download.
        if ($document === null && $revision === null
            && $onboarding->status === Onboarding::COMPLETED
            && ($imported = $this->documents->importedWithRecord(
                $onboarding, $this->documents->importedPdf($onboarding),
            )) !== null) {
            $this->audit->log('onboarding.document_copied', $onboarding, metadata: [
                'format' => PdfRenderer::MIME,
                'source' => 'imported',
                'copy' => ['hash' => DocumentFingerprint::of($imported), 'algorithm' => DocumentFingerprint::ALGORITHM],
            ]);
            $filename = Str::slug(trim(($onboarding->user?->name ?? '').' signed contract')) ?: 'signed-contract';

            return response($imported, Response::HTTP_OK, [
                'Content-Type' => PdfRenderer::MIME,
                'Content-Length' => (string) strlen($imported),
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT, $filename.'.pdf', 'contract.pdf',
                ),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        if ($document === null) {
            abort(Response::HTTP_NOT_FOUND, $revision === null
                ? 'There is no PDF yet, because nothing has been signed. A PDF here is a copy '
                    .'of the signed document, and until somebody signs there is no document to '
                    .'copy — only the form as it stands, which the onboarding screen shows.'
                : sprintf(
                    'This contract has no revision %d. Revision 1 is what was signed and each '
                    .'later number is an amendment; the record lists the ones that exist.',
                    (int) $revision,
                ));
        }

        $content = $this->documents->contentsOf($document);

        abort_if($content === null, Response::HTTP_NOT_FOUND,
            'The stored contract document is missing from this server, so there is nothing to '
            .'make a copy of. The record of it is intact — its fingerprint, its revision and '
            .'the signatures against it are all still here — but the file itself is gone and '
            .'this needs somebody to look at the backups.');

        /*
         * Checked BEFORE converting, and the bytes checked are the bytes
         * converted — `pdfCopyOf` takes them as an argument rather than reading
         * the file again for exactly this reason.
         */
        abort_if(! $document->stillMatches($content), Response::HTTP_CONFLICT,
            'The stored copy of this contract is not the document that was recorded, so no '
            .'copy of it can go out saying it is. A PDF handed to somebody outlives every '
            .'screen that could have warned them, so this refuses rather than labelling it — '
            .'the document is still readable on the onboarding record, where it is shown '
            .'along with the fact that it no longer matches.');

        $pdf = $this->documents->pdfCopyOf($onboarding, $document, $content);

        /*
         * THE COPY'S OWN FINGERPRINT, WRITTEN DOWN. This is what makes the PDF in
         * somebody's inbox traceable to the document it derives from: the entry
         * carries both hashes and the person who asked for it, so a file produced
         * here can be matched against a row rather than against a claim.
         *
         * Recorded even when nothing on the form is sensitive, which is the one
         * place this differs from the reveal above. A reveal is about a value; a
         * copy leaving the building is about the artefact, and the whole contract
         * walks out either way.
         */
        $copyHash = DocumentFingerprint::of($pdf);

        $this->audit->log('onboarding.document_copied', $onboarding, metadata: [
            'format' => PdfRenderer::MIME,
            'revision' => $document->revision,
            'document' => [
                'hash' => $document->content_hash,
                'algorithm' => $document->hash_algorithm,
            ],
            'copy' => [
                'hash' => $copyHash,
                'algorithm' => DocumentFingerprint::ALGORITHM,
                'length' => strlen($pdf),
            ],
        ]);

        $filename = $this->documents->filenameFor(
            $onboarding, (int) $document->revision, PdfRenderer::EXTENSION,
        );

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => PdfRenderer::MIME,
            'Content-Length' => (string) strlen($pdf),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                // Never `inline`. The bytes are converted from a document a
                // customer wrote, and this application's own origin is the last
                // place to render one — the same reasoning FileStore gives for
                // serving every attachment as a download.
                HeaderUtils::DISPOSITION_ATTACHMENT, $filename, 'contract.pdf',
            ),
            'X-Content-Type-Options' => 'nosniff',
            // Behind an authorization check, so a shared cache holding one would
            // serve somebody's contract to the next person through the proxy.
            'Cache-Control' => 'private, no-store',
            /*
             * Both fingerprints, for a client that wants to show them beside the
             * download. The audit entry is the record; these are a convenience,
             * which is why the wording above puts the tie-back on the log and not
             * on a header a saved file does not keep.
             */
            'X-Document-Fingerprint' => (string) $document->content_hash,
            'X-Document-Algorithm' => (string) $document->hash_algorithm,
            'X-Copy-Fingerprint' => $copyHash,
            'X-Copy-Algorithm' => DocumentFingerprint::ALGORITHM,
        ]);
    }

    /**
     * POST /api/v1/hr/onboardings/{onboarding}/tax-form: the W-4 or W-9,
     * filled in and signed in the app (Jose, 2026-10-01: "it should be signed
     * within the app as well, check how a1tools did it").
     *
     * The new hire answers the form's own questions and draws their signature;
     * their name and address come from their profile, the employer's block
     * from the contract. The signed form is stored as the `tax_form` answer.
     * With `preview=1` it comes back as a PDF and nothing is stored.
     */
    public function taxForm(Request $request, Onboarding $onboarding): JsonResponse|HttpResponse
    {
        $this->authorize('update', $onboarding);
        $this->assertNotCancelled($onboarding);

        $actor = $request->user();
        $parties = $this->partiesFor($actor, $onboarding);

        $input = $request->validate([
            'kind' => ['required', Rule::in(TaxForms::KINDS)],
            'values' => ['present', 'array'],
            'values.*' => ['nullable'],
            'drawing' => ['nullable', 'string', 'max:600000'],
        ]);
        $answerKey = TaxForms::ANSWER_KEYS[$input['kind']];
        $field = collect($onboarding->fields())->firstWhere('key', $answerKey);

        abort_if($field === null, Response::HTTP_NOT_FOUND, 'This contract does not ask for that form.');
        if ($onboarding->isFrozen() || ! in_array(FilledBy::EMPLOYEE, $parties, true) || $onboarding->user === null) {
            throw ValidationException::withMessages([$answerKey => 'Only the new hire fills in and signs their own forms, before the paperwork is signed.']);
        }

        $preview = $request->boolean('preview');
        $drawing = OnboardingDocuments::drawingBytes($input['drawing'] ?? null);
        if (! $preview && $drawing === null) {
            throw ValidationException::withMessages(['drawing' => 'Draw your signature to sign the form.']);
        }

        $user = $onboarding->user;
        $answers = $onboarding->answers()->get()->keyBy('field_key');
        $answer = fn (string $key) => trim((string) ($answers->get($key)?->value ?? ''));
        $given = fn (string $key) => is_scalar($input['values'][$key] ?? null) ? mb_substr(trim((string) $input['values'][$key]), 0, 120) : '';
        $parts = $user->addressParts();
        $street = trim(implode(' ', array_filter([$parts['address_line1'], $parts['address_line2']])));
        $cityLine = trim(implode(', ', array_filter([$parts['city'], trim(($parts['state'] ?? '').' '.($parts['postal_code'] ?? ''))])));
        $employer = trim(implode("\n", $onboarding->employer()));
        $ssn = $given('ssn') !== '' ? $given('ssn') : $answer('ssn');
        $zone = (string) ($this->tenantTimezone($onboarding) ?: config('app.timezone'));
        $hired = $answer('date_of_hire');

        $values = match ($input['kind']) {
            TaxForms::DIRECT_DEPOSIT => $this->directDepositValues($onboarding, $answer, $given),
            TaxForms::I9 => $this->i9Values($onboarding, $answer, $given, $ssn),
            default => null,
        } ?? ($input['kind'] === TaxForms::W4
            ? [
                'first_name' => trim($user->first_name.' '.mb_substr((string) $user->middle_name, 0, 1)),
                'last_name' => (string) $user->last_name,
                'ssn' => $ssn,
                'address' => $street,
                'city_state_zip' => $cityLine,
                'filing_status' => in_array($given('filing_status'), ['single', 'married', 'head'], true) ? $given('filing_status') : '',
                'multiple_jobs' => filter_var($input['values']['multiple_jobs'] ?? false, FILTER_VALIDATE_BOOL),
                'children_amount' => $given('children_amount'),
                'other_dependents_amount' => $given('other_dependents_amount'),
                'credits_total' => $given('credits_total'),
                'other_income' => $given('other_income'),
                'deductions' => $given('deductions'),
                'extra_withholding' => $given('extra_withholding'),
                'exempt' => filter_var($input['values']['exempt'] ?? false, FILTER_VALIDATE_BOOL),
                'employer' => $employer,
                'first_date' => $hired === '' ? '' : Carbon::parse($hired)->format('m/d/Y'),
            ]
            : [
                'name' => (string) $user->name,
                'business_name' => $given('business_name'),
                'classification' => $given('classification'),
                'llc_code' => $given('llc_code'),
                'other_classification' => $given('other_classification'),
                'exempt_payee_code' => $given('exempt_payee_code'),
                'fatca_code' => $given('fatca_code'),
                'foreign_partners' => filter_var($input['values']['foreign_partners'] ?? false, FILTER_VALIDATE_BOOL),
                'address' => $street,
                'city_state_zip' => $cityLine,
                'requester' => $employer,
                'account_numbers' => $given('account_numbers'),
            ] + $this->taxIdBoxes($given('ein') !== '' ? $given('ein') : $answer('ein'), $ssn));

        if (! $preview && $input['kind'] === TaxForms::I9 && ($values['status'] ?? '') === '') {
            throw ValidationException::withMessages(['values.status' => 'Choose which of the four statements is true of you, then sign.']);
        }
        if (! $preview && $input['kind'] === TaxForms::DIRECT_DEPOSIT && ($values['account_type'] ?? '') === '') {
            throw ValidationException::withMessages(['values.account_type' => 'Choose checking or savings, then sign.']);
        }

        $date = now($zone)->format('m/d/Y');

        if ($preview) {
            $pdf = TaxForms::render($input['kind'], $values, $drawing, $date);

            return response($pdf, Response::HTTP_OK, [
                'Content-Type' => PdfRenderer::MIME,
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'tax-form.pdf'),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $label = TaxForms::LABELS[$input['kind']];
        // Kept as the picture of the signed page, A1 Tools' way, so the
        // contract's PDF prints it with the other documents (Jose,
        // 2026-10-05: "the w4-w9 signed pdf does not seem attached at all").
        $png = TaxForms::renderPng($input['kind'], $values, $drawing, $date);
        DB::transaction(function () use ($onboarding, $actor, $parties, $png, $label, $input, $answerKey) {
            $file = $this->documents->storeFile($onboarding, $actor, $png, "{$label}.png", 'image/png');
            $this->form->save($onboarding, $actor, $parties, [$answerKey => $file->getKey()]);
            $this->audit->log('onboarding.tax_form_signed', $onboarding, metadata: ['form' => $input['kind']]);
        });

        return response()->json(['data' => $this->detail($onboarding->fresh(), $actor)]);
    }

    /**
     * The direct deposit form, from the bank answers (Jose, 2026-10-06: "all
     * filled on ezbizi"). The account type and the amount are asked on it.
     */
    private function directDepositValues(Onboarding $onboarding, \Closure $answer, \Closure $given): array
    {
        $type = strtolower($given('account_type'));

        return [
            'account_type' => in_array($type, ['checking', 'savings'], true) ? $type : '',
            'routing' => $answer('bank_routing_number'),
            'account' => $answer('bank_account_number'),
            'amount' => $given('amount') !== '' ? $given('amount') : '100%',
            'bank_info' => implode("\n", array_filter([
                $answer('bank_name') === '' ? null : 'Bank: '.$answer('bank_name'),
                $answer('bank_account_name') === '' ? null : 'Account holder: '.$answer('bank_account_name'),
            ])),
            'company' => $onboarding->employer()['name'],
            'employee_id' => '',
            'print_name' => (string) $onboarding->user?->name,
        ];
    }

    /** Section 1 of the I-9, from the profile and the answers; the status is asked on it. */
    private function i9Values(Onboarding $onboarding, \Closure $answer, \Closure $given, string $ssn): array
    {
        $user = $onboarding->user;
        $parts = $user->addressParts();
        $digits = preg_replace('/\D/', '', $ssn) ?? '';
        $status = $given('status');

        return [
            'last_name' => (string) $user->last_name,
            'first_name' => (string) $user->first_name,
            'middle_initial' => mb_substr((string) $user->middle_name, 0, 1),
            'other_last_names' => $given('other_last_names') !== '' ? $given('other_last_names') : 'N/A',
            'address' => (string) $parts['address_line1'],
            'apt' => (string) $parts['address_line2'],
            'city' => (string) $parts['city'],
            'state' => (string) $parts['state'],
            'zip' => (string) $parts['postal_code'],
            'date_of_birth' => $user->date_of_birth?->format('m/d/Y') ?? '',
            'ssn' => strlen($digits) === 9 ? substr($digits, 0, 3).'-'.substr($digits, 3, 2).'-'.substr($digits, 5) : $ssn,
            'email' => (string) $user->email,
            'phone' => (string) $user->phone,
            'status' => in_array($status, ['citizen', 'noncitizen_national', 'permanent_resident', 'authorized_alien'], true) ? $status : '',
            'lpr_number' => $status === 'permanent_resident' ? $given('lpr_number') : '',
            'work_until' => $status === 'authorized_alien' ? $given('work_until') : '',
            'uscis_number' => $status === 'authorized_alien' ? $given('uscis_number') : '',
            'i94_number' => $status === 'authorized_alien' ? $given('i94_number') : '',
            'passport' => $status === 'authorized_alien' ? $given('passport') : '',
        ];
    }

    /**
     * POST /api/v1/hr/onboardings/{onboarding}/i9-section2 — the employer's
     * half of the I-9: the documents examined, the first day, a signature.
     * Drawn onto the page the new hire signed, which it then replaces (Jose,
     * 2026-10-06). `?preview=1` draws it and keeps nothing.
     */
    public function i9Section2(Request $request, Onboarding $onboarding): JsonResponse|HttpResponse
    {
        $this->authorize('cancel', $onboarding); // the employer's side, hr.create
        $actor = $request->user();

        $input = $request->validate([
            'values' => ['present', 'array'],
            'values.*' => ['nullable'],
            'drawing' => ['nullable', 'string', 'max:600000'],
        ]);
        $preview = $request->boolean('preview');
        $drawing = OnboardingDocuments::drawingBytes($input['drawing'] ?? null);
        if (! $preview && $drawing === null) {
            throw ValidationException::withMessages(['drawing' => 'Draw your signature to sign Section 2.']);
        }

        $answer = $onboarding->answers()->where('field_key', 'i9_form')->first();
        $file = $answer?->value === null ? null : Attachment::query()->find($answer->value);
        $page = $file === null ? null : $this->documents->fileBytes($file);
        if ($page === null) {
            throw ValidationException::withMessages(['i9_form' => 'The new hire has not signed Section 1 of their I-9 yet.']);
        }

        $given = fn (string $key) => is_scalar($input['values'][$key] ?? null) ? mb_substr(trim((string) $input['values'][$key]), 0, 160) : '';
        $hired = trim((string) ($onboarding->answers()->where('field_key', 'date_of_hire')->first()?->value ?? ''));
        $employer = $onboarding->employer();
        $values = [
            'first_day' => $given('first_day') !== '' ? $given('first_day')
                : ($hired === '' ? '' : Carbon::parse($hired)->format('m/d/Y')),
            'representative' => trim(implode(', ', array_filter([(string) $actor->last_name, (string) $actor->first_name, $given('title')]))),
            'business_name' => $employer['name'],
            'business_address' => $employer['address'],
        ];
        foreach (['a', 'b', 'c'] as $list) {
            foreach (['title', 'authority', 'number', 'expires'] as $part) {
                $values["{$list}_{$part}"] = $given("{$list}_{$part}");
            }
        }
        $values['additional'] = $given('additional');

        if (! $preview && $values['a_title'] === '' && ($values['b_title'] === '' || $values['c_title'] === '')) {
            throw ValidationException::withMessages(['values' => 'Enter the document from List A, or one from List B and one from List C.']);
        }

        $date = now((string) ($this->tenantTimezone($onboarding) ?: config('app.timezone')))->format('m/d/Y');
        if ($preview) {
            return response(TaxForms::render(TaxForms::I9_SECTION_2, $values, $drawing, $date, $page), Response::HTTP_OK, [
                'Content-Type' => PdfRenderer::MIME,
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'i-9.pdf'),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $png = TaxForms::renderPng(TaxForms::I9_SECTION_2, $values, $drawing, $date, $page);
        DB::transaction(function () use ($onboarding, $actor, $png, $answer) {
            $stored = $this->documents->storeFile($onboarding, $actor, $png, OnboardingDocuments::I9_COMPLETE, 'image/png');
            // The employee's question, completed by the employer: written
            // here rather than through the form, which (rightly) refuses the
            // employer an employee's answer and anything once signed.
            $answer->forceFill(['value' => $stored->getKey()])->save();
            $this->audit->log('onboarding.i9_section2_signed', $onboarding);
        });

        return response()->json(['data' => $this->detail($onboarding->fresh(), $actor)]);
    }

    /** The W-9's number boxes: the EIN when there is one, otherwise the SSN. */
    private function taxIdBoxes(string $ein, string $ssn): array
    {
        $e = preg_replace('/\D/', '', $ein) ?? '';
        $n = preg_replace('/\D/', '', $ssn) ?? '';

        return strlen($e) === 9
            ? ['ein_1' => substr($e, 0, 2), 'ein_2' => substr($e, 2)]
            : ['ssn_1' => substr($n, 0, 3), 'ssn_2' => substr($n, 3, 2), 'ssn_3' => substr($n, 5)];
    }

    private function tenantTimezone(Onboarding $onboarding): ?string
    {
        return app(SettingsResolver::class)->get('timezone', $onboarding->organization);
    }

    /**
     * POST /api/v1/hr/onboardings/{onboarding}/sign — the ceremony.
     *
     * ESIGN and UETA ask for five things, and every one of them is written by
     * this method rather than assumed:
     *
     *   consent        the person agreed to transact electronically at all, in
     *                  the words that were on screen, with its own timestamp
     *   intent         the words above the control they used
     *   attribution    who signed, from the session — never from the body — plus
     *                  the address and the agent as corroboration
     *   tamper-evidence the fingerprint of the exact stored document, which the
     *                  signer's own client has just confirmed it received
     *   retrievability the document is kept, and the version behind it is
     *                  immutable, so the questions can always be reproduced
     */
    public function sign(Request $request, Onboarding $onboarding): JsonResponse
    {
        $this->authorize('update', $onboarding);

        $this->assertNotCancelled($onboarding);

        $actor = $request->user();

        $input = $request->validate([
            'field_key' => ['required', 'string', 'max:64'],

            /*
             * The affirmative act. Free text because it is what the person
             * actually did — a typed name today, a drawn mark when the client
             * can capture one — and a column shaped only for the first would
             * have to change to allow the second.
             */
            'affirmation' => ['required', 'string', 'max:200'],

            // Consent is a separate agreement from the contract, so it is a
            // separate tick. `accepted` rather than `boolean`: a `false` here is
            // somebody declining, and recording a declined consent as a
            // signature would be the worst possible reading of it.
            'consent' => ['required', 'accepted'],

            // What the signer's client received. See the class docblock. The
            // length is DERIVED from the algorithm rather than written as 64, so
            // the day DocumentFingerprint moves this rule moves with it instead
            // of refusing every signature until somebody notices.
            // The signature they drew (Jose, 2026-10-01: "the actual
            // signature, like hand drawn signature"), a PNG data URL.
            'drawing' => ['sometimes', 'nullable', 'string', 'max:600000'],

            'document_hash' => ['required', 'string',
                'size:'.strlen(hash(DocumentFingerprint::ALGORITHM, ''))],
        ], [
            'consent.accepted' => 'Signing electronically requires agreeing to do so. If you '
                .'would rather sign on paper, ask the employer — that is a conversation, not '
                .'a setting.',
            'document_hash.required' => 'Send the fingerprint of the document you were shown. '
                .'It is what makes the signature evidence of what you actually read rather '
                .'than of what the server assumed you read.',
        ]);

        $field = $this->signatureField($onboarding, $input['field_key']);

        $parties = $this->partiesFor($actor, $onboarding);

        if (! in_array($field->filledBy, $parties, true)) {
            throw ValidationException::withMessages(['field_key' => sprintf(
                '"%s" is the %s\'s signature. Attribution is the whole of what a signature '
                .'proves, so one side cannot sign in the other\'s place — that is also why '
                .'the template refuses "either" on a signature field.',
                $field->label, $field->filledBy,
            )]);
        }

        if ($this->existingSignature($onboarding, $field->key) !== null) {
            /*
             * Refused here, though the RECORD would allow a second one.
             *
             * ContractSignature's own docblock says a signature captured wrongly
             * is superseded by a new one and both stay — which is right about
             * the evidence and wrong as a button. Through this endpoint a repeat
             * is somebody pressing sign twice, and it would add a second record
             * of one act rather than correct anything. Superseding is a
             * deliberate thing somebody does to a record, not a thing a form
             * does when it is submitted again.
             */
            throw ValidationException::withMessages(['field_key' => 'That line has already been signed. Signing it again would record the same '
                .'act twice rather than replace the first — a signature is never replaced. '
                .'If what was signed is wrong, correct it with an amendment: both documents '
                .'stay, and the original remains what was agreed to.',
            ]);
        }

        /*
         * The employee has to exist before anybody signs.
         *
         * Not a technicality: `subject_user_id` is who the contract is about, and
         * a signature with nobody as its subject is a record that cannot be shown
         * to the person it binds. Reachable whenever an employer tries to sign a
         * form for somebody who has not accepted their invitation yet.
         */
        // The employer may sign first, before the invitation is accepted
        // (Jose, 2026-10-05: "the final approval and the signature from the
        // employer are two different things"). The employee needs an account.
        if ($onboarding->user_id === null && $field->filledBy !== FilledBy::EMPLOYER) {
            throw ValidationException::withMessages(['field_key' => 'Nobody has accepted the invitation yet, so there is no person for this '
                .'contract to be about. They create their account from the link, and the '
                .'contract can be signed after that.',
            ]);
        }

        // The employer signs their side first, before the employee has
        // answered theirs: the document is stored as it stands and the form
        // stays open for the employee, who signs the finished one.
        $presign = $field->filledBy === FilledBy::EMPLOYER && ! $onboarding->isFrozen();

        // Required (Jose, 2026-10-05: an employer signature reached a signed
        // contract with no drawing; "each contract needs a proper signature
        // from both employer and employee"). An app too old to send one is
        // told to update rather than allowed to sign without.
        $drawing = OnboardingDocuments::drawingBytes($input['drawing'] ?? null);
        if ($drawing === null) {
            throw ValidationException::withMessages(['drawing' => ($input['drawing'] ?? null) === null
                ? 'Draw your signature to sign. If there is nowhere to draw it, update the app.'
                : 'The drawn signature did not arrive as a PNG image under 400 KB. Draw it again and sign.']);
        }

        $signature = DB::transaction(function () use ($onboarding, $actor, $field, $input, $request, $presign, $drawing) {
            $document = match (true) {
                $onboarding->isFrozen() => $onboarding->currentDocument(),
                $presign => $this->renderForEmployer($onboarding, $actor),
                default => $this->freezeAndRender($onboarding, $actor),
            };

            abort_if($document === null, Response::HTTP_CONFLICT);

            $this->assertShownDocumentMatches($document, $input['document_hash']);

            $ceremony = new Ceremony($onboarding);
            $signature = ContractSignature::create([
                'contract_version_id' => $onboarding->contract_version_id,
                'field_key' => $field->key,
                'onboarding_id' => $onboarding->getKey(),
                'subject_user_id' => $onboarding->user_id,
                // From the SESSION, never from the request body. "Who signed" is
                // the one thing a signature cannot take somebody's word for.
                'signer_user_id' => $actor->getKey(),
                'signer_label' => "{$actor->name} <{$actor->email}>",
                // From the FIELD. ContractSignature refuses anything else, and
                // the reason is in its own guard: a party taken from the request
                // is an employer signature filed as the employee's.
                'signer_party' => $field->filledBy,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),

                'content_hash' => $document->content_hash,
                'hash_algorithm' => $document->hash_algorithm,
                'content_length' => $document->content_length,

                // The words that were on screen, and the server's own — a client
                // that supplied them could record somebody agreeing to anything.
                'consent_statement' => $ceremony->consent(),
                'consented_at' => now(),
                'intent_statement' => $ceremony->intent(),
                'affirmation' => trim($input['affirmation']),
                'drawing_attachment_id' => $drawing === null ? null
                    : $this->documents->storeDrawing($onboarding, $actor, $drawing)->getKey(),
                'signed_at' => now(),
            ]);

            $this->audit->log('onboarding.signed', $onboarding, metadata: [
                'field' => $field->key,
                'party' => $field->filledBy,
                'document' => ['revision' => $document->revision, 'hash' => $document->content_hash],
                'signature_id' => $signature->getKey(),
            ]);

            /*
             * `party` is the condition that makes this event two events.
             *
             * "When the new starter signs" and "when the employer signs" are
             * both rules people want, and they are one ACT with a property
             * rather than two different things happening — so the party is in
             * the payload and a rule tests it, instead of the module declaring
             * two triggers that would then both have to be kept in step with
             * whatever a signature turns out to mean.
             *
             * `fully_signed` is asked before completeIfFullySigned() runs and
             * answers the same question it does, so a rule can distinguish the
             * last signature from the earlier ones without waiting for the
             * separate completion event.
             */
            $this->events->emit('onboarding.signed', $onboarding, ['after' => [
                'status' => $onboarding->status,
                'party' => $field->filledBy,
                'field' => $field->key,
                'field_label' => $field->label,
                'revision' => $document->revision,
                'fully_signed' => $onboarding->isFullySigned(),
            ]], actor: $actor);

            $this->completeIfFullySigned($onboarding, $actor);

            return $signature;
        });

        $this->tellTheOtherSide($onboarding->fresh(), $actor, $field->filledBy);

        // The invitation held for this signature goes out now (Jose,
        // 2026-10-06: "sent only after the employer signs").
        if ($field->filledBy === FilledBy::EMPLOYER && $onboarding->invitation !== null) {
            $this->actions->releaseHeld($onboarding->invitation);
        }

        return response()->json([
            'data' => $this->detail($onboarding->fresh(), $actor),
            'signature' => $this->presentSignature($signature),
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/hr/onboardings/{onboarding}/amendments
     *
     * The plan's rule, and the reason it is its own endpoint: "correcting one
     * wrong bank account must not mean redoing the contract. Correct the field,
     * re-render, keep both, record who changed what."
     *
     * Not folded into PATCH, for the same reason publishing a contract version is
     * not folded into renaming one. Saving an answer and amending a signed
     * agreement are different acts with different consequences, and an endpoint
     * that did both would let somebody amend a contract by pressing save.
     */
    public function amend(Request $request, Onboarding $onboarding): JsonResponse
    {
        $this->authorize('amend', $onboarding);

        $this->assertNotCancelled($onboarding);

        $actor = $request->user();

        $input = $request->validate([
            'answers' => ['required', 'array'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'reason.required' => 'Say why this is being corrected. The list of fields says '
                .'what changed; nothing can reconstruct why, and an amendment nobody can '
                .'explain is indistinguishable from an alteration.',
            'reason.min' => 'A few words at least — this sits beside the original for as long '
                .'as the contract does.',
        ]);

        if (! $onboarding->isFrozen()) {
            throw ValidationException::withMessages(['answers' => 'Nothing has been signed yet, so there is nothing to amend — just save the '
                .'correction. An amendment exists because a signed document cannot change, '
                .'and it costs a second document that will be kept forever.',
            ]);
        }

        /*
         * NOT WHILE THE CEREMONY IS UNFINISHED. This was reachable mid-signing,
         * and it is a fraud vector rather than an inconvenience.
         *
         * Proven: the employer signs revision 1 at an hourly wage of 18.50; the
         * same employer alone amends it to 4.00, producing revision 2; the
         * employee then signs, because sign() always takes the CURRENT document.
         * Final state — employer's signature fingerprints the 18.50 document,
         * employee's fingerprints the 4.00 one, status `completed`, and nothing
         * anywhere says the two of them signed different things. The employee
         * was shown, and agreed to, terms the other party had already replaced.
         *
         * `isFrozen()` means "answers stop changing", which begins at the first
         * signature. Amendment needs the stronger condition: everybody who is
         * going to sign has signed. Between those two moments the document has
         * to hold still, because a signature is only worth anything if every
         * party fingerprinted the same bytes.
         *
         * A genuine mistake caught mid-ceremony has to wait. There is no way
         * to take a signature back: the first mark froze the answers, and the
         * second has to fingerprint the same bytes. After both have signed,
         * amend — that keeps the original and records who changed what.
         */
        if (! $onboarding->isFullySigned()) {
            throw ValidationException::withMessages(['answers' => 'Somebody has signed and somebody has not, so the document cannot change yet '
                .'— the second signature has to be over the same text as the first. Wait for '
                .'the remaining signature, then amend. That keeps the original and records '
                .'who changed what.',
            ]);
        }

        $parties = $this->partiesFor($actor, $onboarding);

        $result = DB::transaction(function () use ($onboarding, $actor, $parties, $input) {
            $changed = $this->form->save($onboarding, $actor, $parties, (array) $input['answers']);
            $keys = [...$changed['answers'], ...$changed['identity']];

            if ($keys === []) {
                throw ValidationException::withMessages(['answers' => 'Those are the answers this contract already has, so there is nothing to '
                    .'correct. An amendment that changes nothing would still be a second '
                    .'document kept forever beside the first, with a reason describing a '
                    .'change nobody made.',
                ]);
            }

            $document = $this->documents->store(
                $onboarding->fresh(),
                $actor,
                ($onboarding->currentDocument()?->revision ?? OnboardingDocument::ORIGINAL) + 1,
                trim($input['reason']),
                $keys,
            );

            $this->audit->log('onboarding.amended', $onboarding, metadata: [
                'revision' => $document->revision,
                'fields' => $keys,
                'reason' => $document->reason,
                'party' => $parties,
            ]);

            /*
             * The field KEYS, never the values — the same rule the audit entry
             * above follows and for the same reason. An amendment is very often
             * a correction to a bank account, and this payload is readable by a
             * tenant-authored webhook.
             *
             * The reason IS carried. It is the employer's own sentence about why
             * the contract changed, it already sits on the document forever, and
             * a notification that cannot say why is the one nobody acts on.
             */
            $this->events->emit('onboarding.amended', $onboarding, ['after' => [
                'status' => $onboarding->status,
                'revision' => $document->revision,
                'fields' => $keys,
                'reason' => $document->reason,
                'party' => $parties,
            ]], actor: $actor);

            $this->notifyOtherSide($onboarding, $actor, $keys);

            return $document;
        });

        return response()->json([
            'data' => $this->detail($onboarding->fresh(), $actor),
            'document' => $result->present(),
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/hr/onboardings/{onboarding}/answers/{field}
     *
     * One sensitive answer, in the clear — and the audit entry that says who
     * asked.
     *
     * ── Asking IS the reveal, which is why it is a whole endpoint ──
     *
     * Every other read of this onboarding returns the mask, for everybody,
     * including people who would be allowed to see the value. That is deliberate:
     * it means each entry in the log corresponds to somebody deliberately
     * looking, rather than to a screen having been opened. This endpoint exists
     * to be that deliberate act.
     *
     * A GET with a side effect, then. Worth it: the side effect is a log line
     * about a read, the read is the request, and a POST here would make the
     * client's job of fetching one value into something it has to think about.
     */
    public function revealAnswer(Request $request, Onboarding $onboarding, string $field): JsonResponse
    {
        $this->authorize('view', $onboarding);

        /** @var OnboardingAnswer|null $answer */
        $answer = $onboarding->answers()->where('field_key', $field)->first();

        abort_if($answer === null, Response::HTTP_NOT_FOUND);

        // Handed the parent it came from, so the sensitivity lookup — which has
        // to read the pinned version — costs nothing extra.
        $answer->setRelation('onboarding', $onboarding);

        // Throws AuthorizationException when refused, which the handler turns
        // into a 403. The audit write happens inside, BEFORE the value is
        // returned, so a failure there costs the disclosure rather than the
        // record of it.
        $read = $this->sensitive->read($answer, 'value', $request->user(), reveal: true);

        return response()->json(['data' => [
            'field_key' => $field,
            'value' => $read['value'],
            'sensitive' => $read['sensitive'],
            'revealed' => $read['revealed'],
        ]]);
    }

    /**
     * May this person read the whole agreement, and the entry that says they did.
     *
     * ── THE DOCUMENT CONTAINS EVERY SENSITIVE ANSWER IN THE CLEAR ──
     *
     * so reading it is a reveal and is authorized and audited as one.
     *
     * The `/document` endpoint authorized `view` alone, and that made it a way
     * around the whole mechanism. Measured, in sequence, for one reader holding
     * only `hr.view`:
     *
     *     GET /hr/onboardings/{id}/answers/iban   -> 403, "every reveal is
     *                                                recorded against the
     *                                                person who asked"
     *     GET /hr/onboardings/{id}                -> ••••••••
     *     GET /hr/onboardings/{id}/document       -> 200, the clear IBAN
     *     sensitive_value.revealed rows written   -> 0
     *
     * So the endpoint that says no was decoration, and MasksSensitiveValues'
     * claim — "the ONLY way a sensitive value leaves this application in the
     * clear is through the call that records that it did" — was false of the one
     * screen built to display them.
     *
     * The document cannot be masked instead: it is the thing being signed, and a
     * contract with the bank account starred out is not the contract. So the
     * answer is that reading it requires what revealing requires, and writes the
     * same audit entry. Somebody who may only look at the record sees the form,
     * with the masks.
     *
     * ── One method, because a second format is a second door ─────
     *
     * This was written inline in `document()`, which was fine while there was
     * one way to read the agreement. There are two now — the HTML and the PDF
     * copy — and a copy of this check beside each of them is a copy that can
     * drift. The `via` is the caller's, and it is the only thing that differs:
     * both entries name the same fields for the same reason, and which artefact
     * was handed over is the thing worth telling apart afterwards.
     */
    private function assertMayReadTheDocument(Onboarding $onboarding, string $via): void
    {
        if (! $onboarding->hasSensitiveAnswers()) {
            return;
        }

        $this->authorize('reveal', $onboarding);

        $this->audit->log('sensitive_value.revealed', $onboarding, metadata: [
            'via' => $via,
            'fields' => $onboarding->sensitiveAnswerKeys(),
        ]);
    }

    // ── the ceremony's moving parts ──────────────────────────────

    /**
     * The first signature: check the form is finished, then render and store the
     * one artefact everybody will sign.
     *
     * This is where "validate completeness at SUBMIT" happens, and it is the only
     * place. It is also where the answers stop being editable.
     */
    private function freezeAndRender(Onboarding $onboarding, User $actor): OnboardingDocument
    {
        $missing = $this->form->missing($onboarding);

        if ($missing !== []) {
            throw ValidationException::withMessages(['answers' => sprintf(
                'This cannot be signed yet — %s still unanswered: %s. Everything else that '
                .'has been filled in is saved and waiting.',
                count($missing) === 1 ? 'one required question is' : 'required questions are',
                implode(', ', array_values($missing)),
            )]);
        }

        // The employer may have signed an earlier, unfinished document. When
        // nothing has changed since, both sign that one; otherwise the
        // finished form is the next revision.
        $earlier = $onboarding->currentDocument();
        $document = $earlier !== null
            && hash_equals((string) $earlier->content_hash, DocumentFingerprint::of($this->documents->render($onboarding)))
            ? $earlier
            : $this->documents->store($onboarding, $actor, ($earlier?->revision ?? OnboardingDocument::ORIGINAL - 1) + 1);

        $onboarding->forceFill([
            'status' => Onboarding::SUBMITTED,
            'submitted_at' => now(),
        ])->save();

        $this->audit->log('onboarding.submitted', $onboarding, metadata: [
            'document' => ['revision' => $document->revision, 'hash' => $document->content_hash],
        ]);

        return $document;
    }

    /**
     * Is the document the signer read the document about to be signed?
     *
     * Two different failures, one refusal each, and they are worth telling apart.
     * A mismatch against the RECORDED hash means the stored file has changed
     * since it was written — that is a tamper signal and it stops everything. A
     * mismatch against what the CLIENT says it received means the person is
     * looking at a stale copy, which happens honestly: they left the tab open
     * while the other side corrected something.
     */
    private function assertShownDocumentMatches(OnboardingDocument $document, string $shown): void
    {
        $stored = $this->documents->contentsOf($document);

        if ($stored === null || ! $document->stillMatches($stored)) {
            throw ValidationException::withMessages(['document_hash' => 'The stored copy of this contract is not the document that was recorded, so '
                .'nothing may be signed against it. Nobody should sign a document the system '
                .'cannot vouch for — this needs looking at before anything else happens.',
            ]);
        }

        if (! hash_equals((string) $document->content_hash, $shown)) {
            throw ValidationException::withMessages(['document_hash' => 'The document changed after it was put in front of you, so this signature '
                .'would be against something you have not read. Open it again and sign from '
                .'there — that comparison is the only thing making a signature evidence of '
                .'what somebody actually saw.',
            ]);
        }
    }

    /**
     * POST /api/v1/hr/onboardings/{onboarding}/approve — the sender's final
     * approval, once both sides have signed (Jose, 2026-10-05). Completes it:
     * the contract's terms go into the record and the new starter's access
     * opens.
     */
    public function approve(Request $request, Onboarding $onboarding): JsonResponse
    {
        $this->authorize('approve', $onboarding);

        if ($onboarding->status !== Onboarding::AWAITING_APPROVAL) {
            throw ValidationException::withMessages(['onboarding' => 'Only paperwork both sides have signed waits for approval, and this one is not '
                .'waiting.',
            ]);
        }

        $actor = $request->user();
        DB::transaction(function () use ($onboarding, $actor) {
            $this->audit->log('onboarding.approved', $onboarding);
            $this->complete($onboarding, $actor);
        });

        if (($employee = $onboarding->user) !== null && $employee->is_active) {
            try {
                $this->notifications->dispatch(
                    title: 'Your paperwork is approved',
                    body: 'Everything is signed and approved. The rest of the app is open to you now.',
                    subject: $onboarding,
                    onlyTo: $employee,
                    source: 'hr',
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['data' => $this->detail($onboarding->fresh(), $actor)]);
    }

    /**
     * Every signature field signed: done, or waiting for the sender's
     * approval where the organization asks for it.
     */
    private function completeIfFullySigned(Onboarding $onboarding, User $actor): void
    {
        if (! $onboarding->isFullySigned()) {
            return;
        }

        if ((bool) app(SettingsResolver::class)
            ->get(Onboarding::APPROVAL_SETTING, $onboarding->organization)) {
            $onboarding->forceFill(['status' => Onboarding::AWAITING_APPROVAL])->save();
            $this->audit->log('onboarding.awaiting_approval', $onboarding);

            return;
        }

        $this->complete($onboarding, $actor);
    }

    private function complete(Onboarding $onboarding, User $actor): void
    {
        $before = $onboarding->status;
        $signed = $onboarding->takenSignatures()->pluck('field_key')->all();

        $onboarding->forceFill([
            'status' => Onboarding::COMPLETED,
            'completed_at' => now(),
        ])->save();

        // The contract's pay, start date, schedule and equipment into the
        // record (Jose, 2026-09-30). A failure there must not cost anybody
        // their signature, so it is reported and the completion stands;
        // Records then shows the gaps amber.
        try {
            $filled = app(OnboardingToRecord::class)->apply($onboarding);
        } catch (\Throwable $e) {
            report($e);
            $filled = [];
        }

        $this->audit->log('onboarding.completed', $onboarding, metadata: [
            'signatures' => count($signed),
            'filled' => $filled,
        ]);

        /*
         * The event a payroll handover should hang off.
         *
         * `user.activated` fires when somebody creates their account, which is
         * the beginning of the paperwork rather than the end of it — a rule
         * built on that one tells an accounts department about a person whose
         * contract nobody has signed. This is the moment everything the
         * agreement asked for has been given.
         *
         * `before` carries the status it left, so a rule can be written with
         * `changed_to` rather than having to assume this event only ever fires
         * on the transition. It does only fire on the transition; the payload
         * is what lets a reader of the rule see that without knowing it.
         */
        $this->events->emit('onboarding.completed', $onboarding, [
            'before' => ['status' => $before === Onboarding::AWAITING_APPROVAL ? $before : Onboarding::SUBMITTED],
            'after' => [
                'status' => $onboarding->status,
                'signatures' => count($signed),
                'contract' => $onboarding->contractVersion?->contract?->name,
                'version' => $onboarding->contractVersion?->version,
                'user_id' => $onboarding->user_id,
            ],
        ], actor: $actor);
    }

    /**
     * Tell whoever did not make the amendment that it happened.
     *
     * The plan's open question — whether an employee may change their own bank
     * account without an administrator seeing it — is answered by its own
     * recommendation: "allow it, audit it and notify". This is the notify.
     *
     * A named recipient rather than an audience: the employee when the employer
     * amended, the person who started the onboarding when the employee did.
     * NotificationDispatcher's audience resolution works from a franchise column,
     * which an onboarding does not have, and its fallback is EVERY active user —
     * which for a note about somebody's bank details being changed would be the
     * disclosure the notification is meant to prevent.
     *
     * The field LABELS are in the notification, never the values. Somebody being
     * told their account details changed does not need to be told what they
     * changed to; they need to know to go and look.
     */
    private function notifyOtherSide(Onboarding $onboarding, User $actor, array $keys): void
    {
        $employee = $onboarding->user;

        $recipient = $employee !== null && $actor->getKey() === $employee->getKey()
            ? $onboarding->startedBy
            : $employee;

        if ($recipient === null || ! $recipient->is_active || $recipient->getKey() === $actor->getKey()) {
            return;
        }

        // The switch used to be asked about here, and this was the only place in
        // the system that asked. It is now checked inside dispatch() itself,
        // where no caller can leave it out — so a guard here would be a second
        // copy of a rule that has exactly one correct answer.
        $this->notifications->dispatch(
            title: 'A signed contract was amended',
            body: sprintf(
                '%s corrected %s on %s\'s contract. The original stays on the record beside '
                .'the correction.',
                $actor->name,
                implode(', ', $this->labelsFor($onboarding, $keys)),
                $employee?->name ?? 'the new starter',
            ),
            subject: $onboarding,
            onlyTo: $recipient,
            source: 'hr',
        );
    }

    /** @return string[] */
    private function labelsFor(Onboarding $onboarding, array $keys): array
    {
        $version = $onboarding->contractVersion;

        return array_map(
            fn (string $key) => CommonCore::isAsked($key)
                ? CommonCore::labelFor($key)
                : ($version->field($key)?->label ?? $key),
            $keys,
        );
    }

    // ── shared ───────────────────────────────────────────────────

    private function assertNotCancelled(Onboarding $onboarding): void
    {
        if ($onboarding->isCancelled()) {
            throw ValidationException::withMessages(['onboarding' => 'This onboarding was cancelled, so nothing on it can change. Start a new one '
                .'if you still need the paperwork.',
            ]);
        }
    }

    private function assertInvitationCanCarryAnOnboarding(Invitation $invitation): void
    {
        if ($invitation->onboarding !== null) {
            $live = $invitation->onboarding;

            throw ValidationException::withMessages(['invitation_id' => $live->status === Onboarding::COMPLETED
                    ? 'That invitation already produced a signed agreement. A second '
                        .'onboarding against it would be two contracts for one arrival, with '
                        .'nothing saying which is real.'
                    : 'That invitation already has an onboarding in flight. Cancel it first '
                        .'if you meant a different contract.',
            ]);
        }

        if ($invitation->revoked_at !== null) {
            throw ValidationException::withMessages(['invitation_id' => 'That invitation was withdrawn, so nobody is coming. Send a new one if that '
                .'has changed.',
            ]);
        }

        // An expired link is one nobody can accept, so the form would have no
        // second side. Resending restarts the week, which is what that button is
        // for and is a smaller act than starting paperwork.
        if ($invitation->accepted_at === null && $invitation->hasExpired()) {
            throw ValidationException::withMessages(['invitation_id' => 'That invitation has lapsed and its link no longer works, so the person could '
                .'never fill in their half. Resend it first.',
            ]);
        }
    }

    /** @return string[] */
    private function partiesFor(User $actor, Onboarding $onboarding): array
    {
        return $onboarding->partiesFor(
            $actor,
            Gate::forUser($actor)->allows('actAsEmployer', $onboarding),
        );
    }

    /**
     * The employer's signature on the form as it stands: every question the
     * employer owns answered, the employee's still open. Stored as the
     * document the employer signed; the employee later signs the finished
     * one, stored as the next revision.
     */
    private function renderForEmployer(Onboarding $onboarding, User $actor): OnboardingDocument
    {
        $owners = $this->ownerByKey($onboarding);
        $missing = array_filter(
            $this->form->missing($onboarding),
            fn (string $label, string $key) => ($owners[$key] ?? FilledBy::EMPLOYEE) !== FilledBy::EMPLOYEE,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missing !== []) {
            throw ValidationException::withMessages(['answers' => sprintf(
                'Your side cannot be signed yet — %s: %s.',
                count($missing) === 1 ? 'one of your questions is unanswered' : 'some of your questions are unanswered',
                implode(', ', array_values($missing)),
            )]);
        }

        return $onboarding->currentDocument()
            ?? $this->documents->store($onboarding, $actor, OnboardingDocument::ORIGINAL);
    }

    /**
     * Whose turn it is now, told to them. The employer signing first emails
     * the employee their half; the employee signing while the employer has not
     * tells whoever started it, in the app. After the commit, so a mail never
     * goes out about a signature that rolled back; a failed send never costs
     * the signature.
     */
    private function tellTheOtherSide(Onboarding $onboarding, User $actor, string $signed): void
    {
        if ($onboarding->status === Onboarding::COMPLETED) {
            return;
        }

        try {
            if ($onboarding->status === Onboarding::AWAITING_APPROVAL) {
                $sender = $onboarding->startedBy;
                if ($sender !== null && $sender->is_active && ! $sender->is($actor)) {
                    $this->notifications->dispatch(
                        title: 'Paperwork ready for your approval',
                        body: sprintf('Both sides have signed %s\'s paperwork. It waits for your approval.',
                            (string) $onboarding->user?->name),
                        subject: $onboarding,
                        onlyTo: $sender,
                        source: 'hr',
                    );
                }
            } elseif ($signed === FilledBy::EMPLOYER && ($employee = $onboarding->user) !== null) {
                $employee->notify(new PaperworkReadyToFinish(
                    (string) $onboarding->organization?->name,
                    (string) $onboarding->contractVersion?->contract?->name,
                    $actor->name,
                ));
            } elseif ($signed === FilledBy::EMPLOYEE && ($starter = $onboarding->startedBy) !== null
                && $starter->is_active && $starter->getKey() !== $actor->getKey()) {
                $this->notifications->dispatch(
                    title: 'Paperwork ready for your signature',
                    body: sprintf('%s has finished and signed their part. Yours is the last signature.', $actor->name),
                    subject: $onboarding,
                    onlyTo: $starter,
                    source: 'hr',
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Put the person where their onboarding is: the invitation, if they have
     * not accepted it, and their assignment, if they have and it lies outside
     * the chosen company. Somebody already somewhere inside it stays put; the
     * organization as a choice moves nobody.
     */
    private function placeUnder(Invitation $invitation, ?string $companyId): void
    {
        if ($companyId === null) {
            return;
        }

        $company = Company::query()->findOrFail($companyId);
        $companies = $company->subtreeCompanyIds();
        $franchises = Franchise::query()->whereIn('company_id', $companies)->pluck('id')->all();
        $inside = fn ($row) => in_array($row->assigned_company_id, $companies, true)
            || in_array($row->assigned_franchise_id, $franchises, true);

        if ($invitation->accepted_user_id === null) {
            if (! $inside($invitation)) {
                $invitation->forceFill([
                    'assigned_organization_id' => null,
                    'assigned_company_id' => $companyId,
                    'assigned_franchise_id' => null,
                ])->save();
            }

            return;
        }

        $user = User::query()->find($invitation->accepted_user_id);
        if ($user === null || $user->assignments()->get()->contains($inside)) {
            return;
        }

        $user->assignments()->delete();
        Assignment::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->getKey(),
            'assigned_company_id' => $companyId,
        ]);
        $this->audit->log('user.assignment_moved', $user, metadata: [
            'to' => ['type' => Assignment::SCOPE_COMPANY, 'name' => $company->name],
            'because' => 'onboarding',
        ]);
    }

    /** @return array<string, string> field key => who fills it */
    private function ownerByKey(Onboarding $onboarding): array
    {
        $owners = [];
        foreach ($onboarding->fields() as $field) {
            $owners[$field->key] = $field->filledBy;
        }

        return $owners;
    }

    private function employerSigned(Onboarding $onboarding): bool
    {
        return $onboarding->takenSignatures()
            ->contains(fn (ContractSignature $s) => $s->signer_party === FilledBy::EMPLOYER);
    }

    private function signatureField(Onboarding $onboarding, string $key): FieldDefinition
    {
        foreach ($onboarding->signatureFields() as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        throw ValidationException::withMessages(['field_key' => sprintf(
            "'%s' is not a signature on version %d of this contract. What can be signed is: "
            .'%s.',
            $key,
            (int) $onboarding->contractVersion->version,
            implode(', ', array_map(
                fn (FieldDefinition $f) => $f->key,
                $onboarding->signatureFields(),
            )) ?: 'nothing',
        )]);
    }

    private function existingSignature(Onboarding $onboarding, string $fieldKey): ?ContractSignature
    {
        return $onboarding->takenSignatures()
            ->first(fn (ContractSignature $s) => $s->field_key === $fieldKey);
    }

    // ── presentation ─────────────────────────────────────────────

    private function summarise(Onboarding $onboarding): array
    {
        $version = $onboarding->contractVersion;

        return [
            'id' => $onboarding->id,
            'status' => $onboarding->status,
            'person' => [
                'user_id' => $onboarding->user_id,
                // From the account when there is one, from the invitation before
                // that — the same one-home rule the form itself follows.
                'name' => $onboarding->user?->name ?? trim(sprintf(
                    '%s %s',
                    (string) $onboarding->invitation?->first_name,
                    (string) $onboarding->invitation?->last_name,
                )) ?: null,
                'email' => $onboarding->user?->email ?? $onboarding->invitation?->email,
            ],
            'contract' => [
                'name' => $version?->contract?->name,
                'version' => $version?->version,
                'version_id' => $onboarding->contract_version_id,
            ],
            // The company it is under (the contract's employer), or null for
            // the organization.
            'company' => $onboarding->company_id === null ? null
                : ['id' => $onboarding->company_id, 'name' => $onboarding->company?->name],
            'invitation_id' => $onboarding->invitation_id,
            // Whether there is a signed PDF to download: its own document, or
            // the contract an import brought (Jose, 2026-10-05).
            // A completed one with none of its own was imported: its download
            // is what the import brought, with the record (Jose, 2026-10-05).
            'downloadable' => $onboarding->status === Onboarding::COMPLETED
                || (bool) ($onboarding->documents_exists ?? $onboarding->documents()->exists()),
            'progress' => $this->progress($onboarding),
            'started_at' => $onboarding->started_at?->toIso8601String(),
            'submitted_at' => $onboarding->submitted_at?->toIso8601String(),
            'completed_at' => $onboarding->completed_at?->toIso8601String(),
            'cancelled_at' => $onboarding->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * Answered and still owed, per side, while it is being filled in: the
     * amber count on the list, as Records has (Jose, 2026-10-14). The same
     * missing() the signature refuses on, so the list and the refusal agree;
     * what the employee owns (the core included) is theirs, the rest the
     * employer's, as renderForEmployer() splits it.
     *
     * ponytail: a few queries per row on a page of 50; eager-load answers if
     * the list gets slow.
     */
    private function progress(Onboarding $onboarding): ?array
    {
        if ($onboarding->status !== Onboarding::IN_PROGRESS) {
            return null;
        }
        $owners = $this->ownerByKey($onboarding);
        $side = fn (string $key) => ($owners[$key] ?? FilledBy::EMPLOYEE) === FilledBy::EMPLOYEE
            ? FilledBy::EMPLOYEE : FilledBy::EMPLOYER;
        $progress = [
            FilledBy::EMPLOYER => ['answered' => 0, 'outstanding' => 0],
            FilledBy::EMPLOYEE => ['answered' => 0, 'outstanding' => 0],
        ];
        foreach ($onboarding->answers()->get() as $answer) {
            if ((string) $answer->value !== '' && isset($owners[$answer->field_key])) {
                $progress[$side($answer->field_key)]['answered']++;
            }
        }
        // Without the list's relations: it loads the user as id, name and
        // email only, and the core read off that has no phone or birth date.
        foreach (array_keys($this->form->missing($onboarding->withoutRelations())) as $key) {
            $progress[$side($key)]['outstanding']++;
        }

        return $progress;
    }

    /**
     * The whole form, for whoever is looking at it.
     *
     * `parties` is in the response because the client needs to know which side it
     * is drawing for — and because a field's `writable` flag alone cannot explain
     * itself. Being told "you cannot edit this" is different from being told "the
     * employer fills this in".
     */
    private function detail(Onboarding $onboarding, User $actor): array
    {
        $onboarding->loadMissing(['user', 'invitation', 'contractVersion.contract', 'startedBy:id,name']);

        /** @var Collection<int, OnboardingAnswer> $answers */
        $answers = $onboarding->answers()->with('setBy:id,name')->get()
            // The parent, handed over rather than looked up per row. Sensitivity
            // is decided by the pinned version, so every answer would otherwise
            // load the onboarding and the version again to answer whether it is
            // masked — on a form of thirty fields that is sixty queries.
            ->each(fn (OnboardingAnswer $a) => $a->setRelation('onboarding', $onboarding));

        $parties = $this->partiesFor($actor, $onboarding);
        $ceremony = new Ceremony($onboarding);

        return $this->summarise($onboarding) + [
            'parties' => $parties,
            // The I-9: whether Section 2 is done, and whether this person
            // may do it (the employer's side).
            'i9' => ($i9 = $onboarding->answers()->where('field_key', 'i9_form')->first()?->value) === null ? null : [
                'section2' => Attachment::query()->whereKey($i9)
                    ->where('original_name', OnboardingDocuments::I9_COMPLETE)->exists(),
                'mine' => Gate::forUser($actor)->allows('cancel', $onboarding),
            ],
            // Whose final approval it waits for, and whether this person may
            // give it (Jose, 2026-10-05).
            'approval' => $onboarding->status !== Onboarding::AWAITING_APPROVAL ? null : [
                'by' => $onboarding->startedBy?->name,
                'mine' => Gate::forUser($actor)->allows('approve', $onboarding),
            ],
            // The company address typed on this form, what the contract
            // prints without it, and whether this person may type it.
            'employer_address' => [
                'typed' => $onboarding->employer_address,
                'default' => $onboarding->employer(false)['address'],
                'editable' => in_array(FilledBy::EMPLOYER, $parties, true)
                    && ! $onboarding->isFrozen() && ! $this->employerSigned($onboarding),
            ],
            'started_by' => $onboarding->startedBy?->name,
            'fields' => $this->form->present($onboarding, $answers, $parties),
            'missing' => $this->form->missing($onboarding),
            'frozen' => $onboarding->isFrozen(),
            'signature_fields' => array_map(fn (FieldDefinition $f) => [
                'key' => $f->key,
                'label' => $f->label,
                'filled_by' => $f->filledBy,
                'mine' => in_array($f->filledBy, $parties, true),
                'signed' => $this->existingSignature($onboarding, $f->key) !== null,
            ], $onboarding->signatureFields()),
            'signatures' => $onboarding->takenSignatures()
                ->map(fn (ContractSignature $s) => $this->presentSignature($s))->all(),
            'documents' => $onboarding->documents()->with('createdBy:id,name')->get()
                ->map(fn (OnboardingDocument $d) => $d->present())->all(),
            // The words the signing screen has to display, from the server, so
            // that what a person reads and what the record says they read are
            // the same string.
            'ceremony' => [
                'consent_statement' => $ceremony->consent(),
                'intent_statement' => $ceremony->intent(),
            ],
        ];
    }

    private function presentSignature(ContractSignature $signature): array
    {
        return [
            'id' => $signature->id,
            'field_key' => $signature->field_key,
            'party' => $signature->signer_party,
            'signer' => $signature->signer_label,
            'affirmation' => $signature->affirmation,
            'signed_at' => $signature->signed_at?->toIso8601String(),
            'consented_at' => $signature->consented_at?->toIso8601String(),
            'content_hash' => $signature->content_hash,
            'hash_algorithm' => $signature->hash_algorithm,
            'provider' => $signature->provider,
            // Published because it is the record of what they agreed to, and a
            // person is entitled to be shown it rather than told it exists.
            'consent_statement' => $signature->consent_statement,
            'intent_statement' => $signature->intent_statement,
        ];
    }
}
