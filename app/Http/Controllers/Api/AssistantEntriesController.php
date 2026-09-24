<?php

namespace App\Http\Controllers\Api;

use App\Models\AssistantEntry;
use App\Models\Company;
use App\Models\Franchise;
use App\Support\Audit\AuditLogger;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The AI Assistance Library: the answers the assistant gives, edited by the
 * business. Shaped after Training's GuidesController - same place engine on
 * the list, same ownership checks on a write, same audit rows.
 *
 * The lengths are WhatsApp's, enforced here as well as in the columns so the
 * person typing is told which field is too long, instead of a database error.
 */
class AssistantEntriesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/ai-assistance/entries */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssistantEntry::class);

        $user = $request->user();

        return response()->json([
            'data' => InventoryVisibility::constrain(AssistantEntry::query(), $user, ViewScope::forUser($user))
                ->orderBy('position')->orderBy('title')
                ->get()
                ->map(fn (AssistantEntry $e) => $this->present($e)),
        ]);
    }

    /** POST /api/v1/ai-assistance/entries */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        $company = isset($input['company_id']) ? Company::findOrFail($input['company_id']) : null;
        $franchise = isset($input['franchise_id']) ? Franchise::findOrFail($input['franchise_id']) : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'answer');

        $company === null
            ? $this->authorize('createOnOrganization', AssistantEntry::class)
            : $this->authorize('create', [AssistantEntry::class, $company, $franchise]);

        $entry = DB::transaction(function () use ($input) {
            $entry = AssistantEntry::create($input);
            $this->audit->log('assistant_entry.created', $entry);

            return $entry;
        });

        return response()->json(['data' => $this->present($entry->fresh())], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/ai-assistance/entries/{entry} */
    public function update(Request $request, AssistantEntry $entry): JsonResponse
    {
        $this->authorize('update', $entry);

        $input = $request->validate($this->rules(updating: true));

        if (array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input)) {
            $companyId = array_key_exists('company_id', $input) ? $input['company_id'] : $entry->company_id;
            $franchiseId = array_key_exists('franchise_id', $input) ? $input['franchise_id'] : $entry->franchise_id;

            $company = $companyId === null ? null : Company::findOrFail($companyId);
            $franchise = $franchiseId === null ? null : Franchise::findOrFail($franchiseId);

            PlaceInView::assert($request->user(), $company, $franchise, 'answer');

            // Moving an answer is writing it somewhere new: the new place
            // must be one this person may write to, not only the old one.
            if ($companyId !== $entry->company_id || $franchiseId !== $entry->franchise_id) {
                $company === null
                    ? $this->authorize('createOnOrganization', AssistantEntry::class)
                    : $this->authorize('create', [AssistantEntry::class, $company, $franchise]);
            }
        }

        DB::transaction(function () use ($entry, $input) {
            $entry->fill($input);

            if (! $entry->isDirty()) {
                return;
            }

            $this->audit->logChange('assistant_entry.updated', $entry);
            $entry->save();
        });

        return response()->json(['data' => $this->present($entry->fresh())]);
    }

    /** DELETE /api/v1/ai-assistance/entries/{entry} */
    public function destroy(AssistantEntry $entry): Response
    {
        $this->authorize('delete', $entry);

        DB::transaction(function () use ($entry) {
            $this->audit->log('assistant_entry.deleted', $entry, metadata: ['title' => $entry->title]);
            $entry->delete();
        });

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $inTenant = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $this->tenant->idOrFail());

        return [
            'section' => [$required, 'string', 'max:'.AssistantEntry::MAX_SECTION],
            'title' => [$required, 'string', 'max:'.AssistantEntry::MAX_TITLE],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.AssistantEntry::MAX_DESCRIPTION],
            'body' => [$required, 'string', 'max:'.AssistantEntry::MAX_BODY],
            'audience' => [$required, 'string', Rule::in(AssistantEntry::AUDIENCES)],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'company_id' => ['sometimes', 'nullable', 'uuid', $inTenant('companies')],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $inTenant('franchises')],
        ];
    }

    /** @return array<string, mixed> */
    private function present(AssistantEntry $entry): array
    {
        return $entry->only([
            'id', 'section', 'title', 'description', 'body', 'audience', 'position', 'company_id', 'franchise_id',
        ]);
    }
}
