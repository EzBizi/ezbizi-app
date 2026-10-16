<?php

namespace App\Http\Controllers\Api;

use App\Models\Attachment;
use App\Models\InventoryPurchaseOrder;
use App\Support\Audit\AuditLogger;
use App\Support\Files\Attachables;
use App\Support\Files\AttachmentUploader;
use App\Support\Files\FileStore;
use App\Support\Files\UploadLimits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files on records (plan §9, §11).
 *
 * ── Never a public path, and this is the §9 failure it avoids ────
 *
 * A file at a URL anybody can construct is a file anybody can read. For a logo
 * that means enumerating the platform's customers; for the files this endpoint
 * exists to carry — a signed contract, a photograph of somebody's licence,
 * proof of an address — it means the personal data of an employee of a customer,
 * served to a stranger who guessed a uuid. There is no path on the wire and no
 * `public` disk in play: `GET /attachments/{attachment}` runs the same
 * authorization the record itself would.
 *
 * ── Authorization is the subject's, every time ───────────────────
 *
 * `view` on the record to list or download, `update` on the record to add or
 * remove. Never a permission of this endpoint's own — see Attachables for why a
 * second answer to "who may read this" is a second answer that drifts.
 *
 * That also means reach is applied without this file knowing what reach is. A
 * job's policy asks `canActOnFranchise`; a customer's asks nothing of the kind.
 * Both are right for their record, and neither is restated here.
 *
 * ── The type comes in the BODY, not the path ─────────────────────
 *
 * `POST /attachments` with `attachable_type` and `attachable_id`, rather than
 * `POST /jobs/{job}/attachments`. One endpoint that resolves a declared type is
 * one place to get authorization right; a route per module is a route per
 * module to forget it on. It also keeps `GET /attachments/{attachment}` free of
 * the two-segment ambiguity a nested collection would have introduced.
 */
class AttachmentsController extends Controller
{
    public function __construct(
        private readonly FileStore $files,
        private readonly UploadLimits $limits,
        private readonly AuditLogger $audit,
        private readonly AttachmentUploader $uploader,
    ) {}

    /**
     * GET /api/v1/attachments?attachable_type=jobs&attachable_id=...
     *
     * Everything on one record. Not paginated, deliberately: this is the file
     * list on a record's own screen, and a record with enough attachments to
     * need paging is a different product decision than a page parameter.
     */
    public function index(Request $request): JsonResponse
    {
        $subject = $this->subject($request);

        $this->authorizeAttachment('view', $subject);

        return response()->json([
            'data' => $subject->attachments()->with('uploadedBy:id,name')->get()
                ->map(fn (Attachment $a) => $a->present())->all(),

            // So the upload control can state the limit before somebody spends
            // four minutes uploading something that will be refused. The number
            // is the one the validator will really apply — UploadLimits clamps
            // the configured value to what PHP will carry.
            'limits' => [
                'max_kilobytes' => $this->limits->maxKilobytes(),
                'max_readable' => $this->limits->humanMax(),
                'extensions' => $this->limits->extensions(),
            ],
        ]);
    }

    /** POST /api/v1/attachments */
    public function store(Request $request): JsonResponse
    {
        // BEFORE validation, because a file PHP refused for its own size arrives
        // present and empty — `max:` has nothing to weigh and the caller is told
        // the transfer broke. See UploadLimits.
        $this->limits->assertUploadArrived($request, 'file');

        $subject = $this->subject($request);

        $this->authorizeAttachment('update', $subject);

        if ($request->input('attachable_type') === 'services'
            && $subject->attachments()->exists()) {
            throw ValidationException::withMessages([
                'file' => 'This service already has a photo. Remove it first.',
            ]);
        }

        $request->validate(
            $this->limits->rulesFor('file'),
            $this->limits->messagesFor('file'),
        );

        $file = $request->file('file');

        // Service-photo validation needs the temporary file. AttachmentUploader
        // owns the shared size, checksum and detected-mime reads before moving it.
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        if ($request->input('attachable_type') === 'services') {
            $this->assertSquareServicePhoto($file, $mime);
        }

        $attachment = $this->uploader->upload(
            $request->user(), $subject, $file, (string) $request->input('attachable_type'),
        );

        return response()->json(['data' => $attachment->present()], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/attachments/{attachment} — the bytes.
     *
     * Bound tenant-scoped, so another organization's id is a 404 from the scope
     * before this method runs. The policy check below is the second gate and the
     * one that matters within an organization: being in the same business does
     * not make somebody's employment file yours to read.
     */
    public function show(Attachment $attachment): StreamedResponse
    {
        $subject = $this->subjectOf($attachment);

        $this->authorizeAttachment('view', $subject);

        /*
         * SOME FILES ARE A REVEAL, and this was the second door to the room.
         *
         * A rendered onboarding contract is stored as an ordinary attachment and
         * contains every sensitive answer in the clear — it cannot be masked,
         * because it is the thing being signed. The /document endpoint was
         * taught to demand the reveal permission and write an audit entry. This
         * served the same bytes on `view` alone, with no log of any kind:
         *
         *     GET /hr/onboardings/{id}/answers/iban  -> 403
         *     GET /hr/onboardings/{id}/document      -> 403
         *     GET /attachments/{id}                  -> 200, the IBAN
         *
         * The subject is asked rather than this controller knowing about
         * contracts — the same arrangement `attachmentRemovalRefusal` already
         * uses, so a future subject holding sensitive files inherits the
         * behaviour instead of needing this method edited again.
         */
        $discloses = method_exists($subject, 'attachmentDisclosesSensitive')
            ? $subject->attachmentDisclosesSensitive($attachment)
            : null;

        if ($discloses !== null) {
            $this->authorize('reveal', $subject);

            $this->audit->log('sensitive_value.revealed', $subject, metadata: [
                'via' => 'attachment download',
                'attachment' => $attachment->getKey(),
                'fields' => $discloses,
            ]);
        }

        return $this->files->serve(
            $attachment->path,
            $attachment->disk,
            $attachment->mime,
            $attachment->original_name,
            // Never `inline`. The mime is detected rather than claimed, which
            // makes it honest, not safe — an honest `text/html` rendered on this
            // origin is still script running as the reader. FileStore sends
            // `nosniff` beside this; two locks, because a download endpoint for
            // arbitrary customer files is exactly where one is not enough.
            disposition: 'attachment',
        );
    }

    /** DELETE /api/v1/attachments/{attachment} */
    public function destroy(Attachment $attachment): JsonResponse
    {
        /*
         * A type this endpoint does not manage — the internal morph identity
         * from AppServiceProvider that Attachables deliberately omits. Upload
         * already refuses it by validation; this is the other half.
         *
         * `inspection_reports` is here rather than behind an
         * `attachmentRemovalRefusal` on the report, and the difference matters:
         * that hook refuses EVERY writer, the purge included, and a photograph
         * of somebody's house has to go when they are erased — the report
         * declares `answers` erasable and blanks the sentence naming the
         * picture, so a refusal here would leave the picture. What needs closing
         * is only this door: InspectionReportPolicy::update IS `archive`, so
         * without the abort anybody who may hide a report could take a
         * photograph out of a frozen one.
         */
        abort_if(
            $attachment->attachable_type === 'inspection_reports',
            Response::HTTP_NOT_FOUND,
        );

        $subject = $this->subjectOf($attachment);

        $this->authorizeAttachment('update', $subject);

        /*
         * Some files are not uploads — they are the record.
         *
         * A rendered contract is fingerprinted by a signature, so removing it
         * leaves the signature pointing at bytes nobody can produce. The SUBJECT
         * is asked rather than this controller deciding, because only it knows
         * which of its files are evidence: see
         * App\Models\Concerns\HasAttachments::attachmentRemovalRefusal, whose
         * default is null so that nothing else has to think about it.
         *
         * Asked through `Attachment::deletionRefusal()` rather than reaching for
         * the subject's method here, so the sentence a person is shown, the one
         * `Attachment::deleting` throws and the one the purge report prints are
         * all the same sentence from the same place (R6).
         *
         * A 422 with the reason, and not a 403: the person is entitled to manage
         * this record's files, and this particular one simply is not removable.
         * Attachment::deleting refuses it again for every writer that never
         * comes through here.
         */
        $refusal = $attachment->deletionRefusal();

        if ($refusal !== null) {
            throw ValidationException::withMessages(['attachment' => $refusal]);
        }

        $disk = $attachment->disk;
        $path = $attachment->path;

        DB::transaction(function () use ($attachment) {
            // Logged with everything about the file, because after this there is
            // no row left to look at and the bytes are gone too. An entry saying
            // only "an attachment was deleted" answers nothing anybody would ask.
            $this->audit->log('attachment.removed', $attachment, metadata: [
                'original_name' => $attachment->original_name,
                'checksum' => $attachment->checksum,
                'attached_to' => [
                    'type' => $attachment->attachable_type,
                    'id' => $attachment->attachable_id,
                ],
            ]);

            $attachment->delete();
        });

        // Outside the transaction and after it, in that order. A file removed
        // first and a transaction that then rolls back leaves a row pointing at
        // nothing — a download that 404s with no explanation. This way round the
        // worst case is bytes with no row, which the §9 sweep can find.
        $this->files->delete($path, $disk);

        return response()->json(['message' => 'Attachment removed.']);
    }

    /**
     * Service photos are 1:1. Not a picture, or not square, is the same
     * sentence — the requirement is the square, not the file type.
     */
    private function assertSquareServicePhoto(UploadedFile $file, string $mime): void
    {
        if (! str_starts_with($mime, 'image/')) {
            throw ValidationException::withMessages([
                'file' => 'The photo has to be square (1:1).',
            ]);
        }

        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            throw ValidationException::withMessages([
                'file' => 'The photo has to be square (1:1).',
            ]);
        }

        [$width, $height] = $info;

        if ($width < 1 || $width !== $height) {
            throw ValidationException::withMessages([
                'file' => 'The photo has to be square (1:1).',
            ]);
        }
    }

    // ── shared ───────────────────────────────────────────────────

    /**
     * `view` / `update` on the subject, plus the one type whose files are money.
     *
     * Vehicle payments close the receipt-URL hole in the policy: `view` already
     * requires financials because the payment *is* the ledger. A purchase order
     * is the opposite — technicians must see the header without financials — so
     * those abilities stay open and this type is the one case the file endpoint
     * has to close itself. Otherwise GET /attachments/{id} is a vendor invoice
     * to anyone who can see the order (spec §9).
     */
    private function authorizeAttachment(string $ability, Model $subject): void
    {
        $this->authorize($ability, $subject);

        if (! $subject instanceof InventoryPurchaseOrder) {
            return;
        }

        if (! request()->user()->hasPermission('inventory.financials')) {
            abort(Response::HTTP_FORBIDDEN, 'Costs on inventory are hidden for your role.');
        }
    }

    /**
     * The record this request is about, resolved from a DECLARED type.
     *
     * `attachable_type` is a string from a request body; it is looked up in
     * Attachables and never treated as a class name. The find is tenant-scoped,
     * so another organization's id answers 404 — the same answer as a type that
     * exists and an id that does not, which is the answer that says nothing.
     */
    private function subject(Request $request): Model
    {
        $input = $request->validate([
            'attachable_type' => ['required', 'string', Attachables::rule()],
            'attachable_id' => ['required', 'uuid'],
        ]);

        $class = Attachables::classFor($input['attachable_type']);

        $subject = $class::query()->find($input['attachable_id']);

        abort_if($subject === null, Response::HTTP_NOT_FOUND);

        return $subject;
    }

    /**
     * The subject of an attachment that already exists.
     *
     * A missing subject is a 404 rather than an open door. The morph pair has no
     * foreign key behind it (see the migration), so an orphan is possible in
     * principle — and an orphan whose authorization cannot be asked must not be
     * readable, because there is nothing left to ask.
     */
    private function subjectOf(Attachment $attachment): Model
    {
        $subject = $attachment->attachable;

        abort_if($subject === null, Response::HTTP_NOT_FOUND);

        return $subject;
    }
}
