<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Tasks\TaskBoardAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskBoardRecoveryController extends TaskApiController
{
    public function __construct(private readonly TaskBoardAccess $access) {}

    public function store(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate(['new_owner_user_id' => ['required', 'uuid']]);
        $newOwner = User::query()->findOrFail($input['new_owner_user_id']);

        return response()->json([
            'data' => $this->access->recoverOrphanedOwner($request->user(), $board, $newOwner),
        ]);
    }
}
