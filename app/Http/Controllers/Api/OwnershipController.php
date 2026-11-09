<?php

namespace App\Http\Controllers\Api;

use App\Models\Assignment;
use App\Models\Ownership;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The ownership chart's data (Jose, 2026-11-03, design 175): who answers to
 * whom, who owns an area of a company or branch, and who owns each step of the
 * work. Reading is HR's `hr.view`; setting an owner is `company.edit`, at a place
 * the editor reaches. Who answers to whom is set on the person
 * (PATCH hr/people/{user} reports_to_user_id).
 */
class OwnershipController extends Controller
{
    public function __construct(private readonly ScopeReach $reach, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('hr.view'), 403, Refusal::permission('see the ownership chart')->message());

        $people = User::query()->where('is_active', true)
            ->with(['role:id,name', 'assignments.assignedCompany:id,name', 'assignments.assignedFranchise:id,name'])
            ->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->getKey(), 'name' => $u->name,
                'role' => $u->role === null ? null : ['id' => $u->role->getKey(), 'name' => $u->role->name],
                'reports_to_user_id' => $u->reports_to_user_id,
                'assigned' => $u->assignments->map(fn (Assignment $a) => [
                    'type' => $a->scopeType(), 'id' => $a->scopeId(),
                    'company' => $a->assignedCompany?->name, 'branch' => $a->assignedFranchise?->name,
                ])->values(),
            ]);

        return response()->json(['data' => [
            'people' => $people,
            'owners' => Ownership::query()->with('user:id,name')->get()->map(fn (Ownership $o) => $this->present($o)),
            'steps' => Ownership::STEPS,
        ]]);
    }

    /** Set or clear the owner of one area or step at one place (or the whole organization). */
    public function update(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('company.edit'), 403, Refusal::permission('change who owns what')->message());

        $input = $request->validate([
            'kind' => ['required', Rule::in(Ownership::KINDS)],
            'key' => ['required', 'string', 'max:60', Rule::when($request->input('kind') === 'process', Rule::in(Ownership::STEPS))],
            'scope_type' => ['nullable', Rule::in(ScopeReach::TYPES)],
            'scope_id' => ['required_with:scope_type', 'nullable', 'uuid'],
            'user_id' => ['present', 'nullable', 'uuid', Rule::exists('users', 'id')->where('organization_id', $actor->organization_id)],
        ]);
        if (! empty($input['scope_type'])) {
            $this->reach->assertCovers($actor, $input['scope_type'], $input['scope_id']);
        }

        $where = ['kind' => $input['kind'], 'key' => $input['key'],
            'scope_type' => $input['scope_type'] ?? null, 'scope_id' => $input['scope_id'] ?? null];
        $existing = Ownership::query()->where($where)->first();
        if ($input['user_id'] === null) {
            $existing?->delete();
            $row = null;
        } else {
            $row = $existing ?? new Ownership($where + ['organization_id' => $actor->organization_id]);
            $row->user_id = $input['user_id'];
            $row->save();
        }
        $this->audit->log('person.ownership_changed', metadata: $where + ['user_id' => $input['user_id']]);

        return response()->json(['data' => $row === null ? null : $this->present($row->load('user:id,name'))]);
    }

    private function present(Ownership $o): array
    {
        return ['id' => $o->getKey(), 'kind' => $o->kind, 'key' => $o->key, 'scope_type' => $o->scope_type,
            'scope_id' => $o->scope_id, 'user' => ['id' => $o->user_id, 'name' => $o->user?->name]];
    }
}
