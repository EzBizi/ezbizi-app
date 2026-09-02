<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\TrainingAssignment;
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
