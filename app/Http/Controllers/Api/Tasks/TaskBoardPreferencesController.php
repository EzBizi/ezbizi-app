<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskBoardPreference;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskBoardPreferencesController extends TaskApiController
{
    public function __construct(private readonly TaskBoardAccess $access) {}

    public function update(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'is_favorite' => ['sometimes', 'boolean'],
            'mark_opened' => ['sometimes', 'boolean'],
            'user_id' => ['prohibited'],
        ]);
        $this->access->viewDecision($request->user(), $board, ViewScope::forUser($request->user()))->authorize();

        $preference = TaskBoardPreference::query()->firstOrNew([
            'board_id' => $board->id,
            'user_id' => $request->user()->id,
        ]);
        if (array_key_exists('is_favorite', $input)) {
            $preference->is_favorite = $input['is_favorite'];
        }
        if (($input['mark_opened'] ?? false) === true) {
            $preference->last_opened_at = now();
        }
        $preference->save();

        return response()->json(['data' => [
            'board_id' => $board->id,
            'is_favorite' => (bool) $preference->is_favorite,
            'last_opened_at' => $preference->last_opened_at?->toIso8601String(),
        ]]);
    }
}
