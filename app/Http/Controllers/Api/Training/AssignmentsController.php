<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Support\Access\Refusal;
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
 * manage writes the guides and the tests, assign gives one to somebody").
 * Reach is bounded by `App\Support\Training\AssignmentReach`, asked through
 * the one write path this controller shares with the automation engine's
 * `training.assign` action (`ActionRunner::assignTraining`) — see
 * `AssignmentWriter`'s own docblock for why that sharing is the point.
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
        $this->authorizeAssigner($request->user());

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

        return response()->json(['data' => $this->present($assignment)], Response::HTTP_CREATED);
    }

    private function authorizeAssigner(User $user): void
    {
        if (! $user->hasPermission('training.assign')) {
            Refusal::permission('assign training')->authorize();
        }
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
