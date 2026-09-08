<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\Role;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Training\AssignmentReach;
use App\Support\Training\AssignmentWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Giving a guide or a test to a person or a role (training task-9 brief).
 *
 * Gated on `training.assign` — the fourth of the module's four permissions
 * (TrainingGuidePolicy's docblock: "monitor sees other people's results,
 * manage writes the guides and the tests, assign gives one to somebody") —
 * and reach-bounded (`App\Support\Training\AssignmentReach`). Both checks
 * live in `AssignmentWriter::create`, not here: this controller used to
 * carry its own copy of the permission check, which review (task-9 round 2)
 * found meant `training.assign` was enforced on THIS path only — a rule
 * using the `training.assign` automation action ran with no permission
 * check at all. Removed rather than kept alongside `AssignmentWriter`'s,
 * so there is exactly one gate on the one write path this controller
 * shares with `ActionRunner::assignTraining` — see `AssignmentWriter`'s own
 * docblock.
 *
 * No listing, updating or deleting here — the brief scopes this task to
 * GIVING training, and a role assignment is never expanded into per-user
 * rows (decision 1: `LearnController::assignedQuery` unions "assigned to me"
 * with "assigned to a role I hold" at read time).
 */
class AssignmentsController extends Controller
{
    public function __construct(private readonly AssignmentWriter $writer) {}

    /** POST /api/v1/training/assignments */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            // Presence/absence — not "exactly one" — is checked here; the
            // XOR itself is AssignmentWriter's, so the controller and the
            // automation action refuse it identically rather than each
            // carrying its own copy of the rule.
            'guide_id' => ['sometimes', 'nullable', 'uuid'],
            'test_id' => ['sometimes', 'nullable', 'uuid'],
            'assignee_type' => ['required', 'string', Rule::in([TrainingAssignment::USER, TrainingAssignment::ROLE])],
            'assignee_id' => ['required', 'uuid'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $assignment = $this->writer->create($request->user(), $input);

        // 200 for a repeat that resolved to the row that already existed
        // (AssignmentWriter::create's own docblock, "a repeat assignment
        // updates in place, never a second row"), 201 only for a genuine
        // insert — Eloquent's own `wasRecentlyCreated` already means
        // exactly this, so no second flag has to travel with the model.
        $status = $assignment->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK;

        return response()->json(['data' => $this->present($assignment)], $status);
    }

    /**
     * GET /api/v1/training/assignees
     *
     * Who the client's assign picker may offer, for `?type=user` (searched
     * by name/email, bounded to who {@see AssignmentReach::mayAssignTo}
     * actually allows) or `?type=role` (the organization's whole role
     * list, each marked `assignable` — the same "show and mark" shape
     * `HrPeopleController::scopeOptions`' role list already uses, rather
     * than a role simply being absent with no way to tell "we have none"
     * from "not yours to give").
     *
     * Gated on `training.assign` alone, same as {@see AssignmentWriter::create}
     * — never `hr.view` or `roles.view`. Every other people/role listing on
     * this platform sits behind one of those two, and `training.assign` was
     * created precisely so a supervisor could hold it without holding
     * either — see AssignmentReach's own docblock. Without a lookup gated
     * the same way, the write endpoint's contract is real but nothing
     * reachable from `training.assign` alone could ever discover an
     * `assignee_id` to send it.
     *
     * `type=user` never returns somebody `mayAssignTo` would refuse — the
     * module's own rule against offering what the write would then reject
     * (AssignmentWriter's own docblock, "ONE write path"). `type=role`
     * returns every role either way, since {@see AssignmentReach::mayAssignToRole}
     * is a single yes/no about the ASSIGNER's own reach, not about any one
     * role — hiding the list entirely would look identical to "this
     * organization has no roles", and marking-not-hiding says which.
     */
    public function assignees(Request $request): JsonResponse
    {
        $assigner = $request->user();

        if (! $assigner->hasPermission('training.assign')) {
            Refusal::permission('assign training')->authorize();
        }

        $input = $request->validate([
            'type' => ['required', 'string', Rule::in([TrainingAssignment::USER, TrainingAssignment::ROLE])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return $input['type'] === TrainingAssignment::ROLE
            ? $this->roleCandidates($assigner)
            : $this->userCandidates($assigner, trim((string) ($input['search'] ?? '')));
    }

    private function roleCandidates(User $assigner): JsonResponse
    {
        // AssignmentReach::mayAssignToRole reduces, for a role in the
        // ASSIGNER's own organization, to exactly this one check (its own
        // docblock, "The role case — organization reach, always") — asked
        // once here rather than once per row, since no role in the list
        // below can ever get a different answer from another.
        $assignable = $assigner->canActOnOrganization($assigner->organization);

        $roles = Role::query()
            ->where('organization_id', $assigner->organization_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name, 'assignable' => $assignable])
            ->values();

        return response()->json(['data' => $roles]);
    }

    private function userCandidates(User $assigner, string $search): JsonResponse
    {
        $candidates = User::query()
            ->where('organization_id', $assigner->organization_id)
            ->where('is_active', true)
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->limit(50)
            // Full rows, not a narrow `select` — `AssignmentReach::mayAssignTo`
            // reads `is_active` and `organization_id` off the candidate, and a
            // model missing those attributes reads both as null, which
            // `refusalForAssignee` takes as "inactive" and refuses every row
            // (found running this method's own RED: every candidate came back
            // filtered out, including the assigner's own crew).
            ->get();

        $data = $candidates
            // Filtered, not marked-and-shown — see this method's own
            // docblock. A person outside §4 reach is not named here at all,
            // unlike a role's uniform allow/refuse.
            ->filter(fn (User $candidate) => AssignmentReach::mayAssignTo($assigner, $candidate))
            ->map(fn (User $candidate) => ['id' => $candidate->id, 'name' => $candidate->name, 'email' => $candidate->email])
            ->values();

        return response()->json(['data' => $data]);
    }

    /** @return array<string, mixed> */
    private function present(TrainingAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'guide_id' => $assignment->guide_id,
            'test_id' => $assignment->test_id,
            'assignee_type' => $assignment->assignee_type,
            'assignee_id' => $assignment->assignee_id,
            'due_on' => $assignment->due_on?->toDateString(),
            'assigned_by_user_id' => $assignment->assigned_by_user_id,
        ];
    }
}
