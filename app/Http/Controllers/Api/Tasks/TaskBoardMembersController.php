<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\BoardMemberRole;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskBoardMemberships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class TaskBoardMembersController extends TaskApiController
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskBoardMemberships $memberships,
    ) {}

    public function index(Request $request, TaskBoard $board): JsonResponse
    {
        $this->access->manageDecision($request->user(), $board, ViewScope::forUser($request->user()))->authorize();
        $members = $board->members()->with('user')->orderBy('created_at')->get();

        return response()->json(['data' => $members->map(fn (TaskBoardMember $member) => $this->present($member))]);
    }

    public function store(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'user_id' => ['required', 'uuid'],
            'role' => ['required', Rule::in(BoardMemberRole::values())],
        ]);
        $candidate = User::query()->findOrFail($input['user_id']);
        $membership = $this->memberships->add(
            $request->user(), $board, $candidate, ViewScope::forUser($request->user()),
            BoardMemberRole::from($input['role']),
        );

        return response()->json(['data' => $this->present($membership)], Response::HTTP_CREATED);
    }

    public function update(Request $request, TaskBoard $board, TaskBoardMember $membership): JsonResponse
    {
        $input = $request->validate(['role' => ['required', Rule::in(BoardMemberRole::values())]]);
        $result = $this->memberships->changeRole(
            $request->user(), $board, $membership, ViewScope::forUser($request->user()),
            BoardMemberRole::from($input['role']),
        );

        return response()->json(['data' => $this->present($result)]);
    }

    public function destroy(Request $request, TaskBoard $board, TaskBoardMember $membership): JsonResponse
    {
        $result = $this->memberships->revoke(
            $request->user(), $board, $membership, ViewScope::forUser($request->user()),
        );

        return response()->json(['data' => $this->present($result)]);
    }

    private function present(TaskBoardMember $membership): array
    {
        return [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'role' => $membership->role->value,
            'active' => $membership->is_active && $membership->archived_at === null,
        ];
    }
}
