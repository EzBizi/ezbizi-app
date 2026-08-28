<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskParticipantEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TaskParticipantsController extends TaskApiController
{
    private const PURPOSE_PERMISSIONS = [
        'task_create' => 'tasks.create',
        'owner' => 'tasks.edit',
        'people' => 'tasks.edit',
        'collaborator' => 'tasks.edit',
        'mention' => 'tasks.comment',
    ];

    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskParticipantEligibility $participants,
    ) {}

    public function index(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'purpose' => ['required', Rule::in(['membership', ...array_keys(self::PURPOSE_PERMISSIONS)])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $scope = ViewScope::forUser($request->user());
        $recovery = false;
        if ($input['purpose'] === 'membership') {
            $manage = $this->access->manageDecision($request->user(), $board, $scope);
            if ($manage->denied()) {
                $this->access->recoveryAtCurrentViewDecision(
                    $request->user(), $board, $scope,
                )->authorize();
                $recovery = true;
            }
        } else {
            $this->access->viewDecision($request->user(), $board, $scope)->authorize();
            $permission = self::PURPOSE_PERMISSIONS[$input['purpose']];
            if (! $request->user()->hasPermission($permission)) {
                Refusal::permission('choose task participants')->authorize();
            }
        }

        $search = trim((string) ($input['search'] ?? ''));
        $query = $input['purpose'] === 'membership'
            ? $this->participants->constrainForMembership(User::query(), $board)
            : $this->participants->constrainForTaskParticipation(User::query(), $board);
        $candidates = $query
            ->when($recovery, fn ($query) => $query->whereKeyNot($request->user()->id))
            ->when($search !== '', fn ($query) => $query->where(fn ($names) => $names
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')->limit(20)->get();

        return response()->json(['data' => $candidates->map(fn (User $candidate) => [
            'id' => $candidate->id,
            'name' => $candidate->name,
            'email' => $candidate->email,
        ])]);
    }
}
