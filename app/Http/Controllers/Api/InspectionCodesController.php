<?php

namespace App\Http\Controllers\Api;

use App\Models\InspectionCode;
use App\Support\Audit\AuditLogger;
use App\Support\Inspection\InspectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The building codes this organization cites (engine design §5.5).
 *
 * ── Read on `inspection.view`, write on `inspection.edit` ────────
 *
 * A technician has to SEE the code they are being held to — it is printed on
 * the report they hand a customer, and a field that cites a standard the
 * technician cannot read is a form asking them to certify something unseen.
 * They have no business editing it, which is the same split the Builder uses
 * for reading a named inspection versus changing one.
 *
 * No new permission key. `inspection.edit` already exists and this is the
 * Builder's own reference data; a `codes.*` permission would be a second answer
 * to a question the module already answers, and the two would drift.
 *
 * ── Configuration, never evidence ───────────────────────────────
 *
 * These rows can be edited and deleted freely because nothing depends on them
 * surviving: publishing copies the wording into the version, and a version is
 * written once. See InspectionCode.
 */
class InspectionCodesController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json([
            'data' => InspectionCode::query()
                ->orderBy('code')
                ->get()
                ->map(fn (InspectionCode $code) => $this->present($code))
                ->values(),
        ]);
    }

    public function show(Request $request, InspectionCode $inspectionCode): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json(['data' => $this->present($inspectionCode)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeWrite($request);

        $code = InspectionCode::create($this->validated($request));

        $this->audit->log('inspection.code_added', $code);

        return response()->json(['data' => $this->present($code)], Response::HTTP_CREATED);
    }

    public function update(Request $request, InspectionCode $inspectionCode): JsonResponse
    {
        $this->authorizeWrite($request);

        $inspectionCode->fill($this->validated($request, partial: true))->save();

        $this->audit->log('inspection.code_changed', $inspectionCode);

        return response()->json(['data' => $this->present($inspectionCode->fresh())]);
    }

    public function destroy(Request $request, InspectionCode $inspectionCode): JsonResponse
    {
        $this->authorizeWrite($request);

        /*
         * Removed outright, with nothing to cascade.
         *
         * A published version already holds its own copy of the wording, and a
         * draft citing this row simply publishes without a citation rather than
         * refusing — see InspectionDefinitionsController::freezeCitations.
         * Refusing to delete while any form mentions it would make one library
         * row permanent the moment somebody cited it by accident.
         */
        $this->audit->log('inspection.code_removed', $inspectionCode);

        $inspectionCode->delete();

        return response()->json(['data' => ['removed' => true]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return $request->validate([
            'code' => [...$sometimes, 'required', 'string', 'max:60'],
            'title' => [...$sometimes, 'required', 'string', 'max:255'],
            // No length cap: this is the requirement itself, quoted, and a
            // truncated code is a code that says something else.
            'body' => [...$sometimes, 'required', 'string'],
        ]);
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless(
            $request->user()->hasPermission(InspectionAccess::VIEW),
            Response::HTTP_FORBIDDEN,
            'You do not have permission to see inspection codes.',
        );
    }

    private function authorizeWrite(Request $request): void
    {
        abort_unless(
            $request->user()->hasPermission('inspection.edit'),
            Response::HTTP_FORBIDDEN,
            'You do not have permission to change the inspection code library.',
        );
    }

    /** @return array<string, mixed> */
    private function present(InspectionCode $code): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'title' => $code->title,
            'body' => $code->body,
        ];
    }
}
