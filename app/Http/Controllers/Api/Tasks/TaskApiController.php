<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Api\Controller;
use App\Models\TaskBoard;
use App\Support\Tasks\TaskStructureConflict;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class TaskApiController extends Controller
{
    protected function structureMutation(
        TaskBoard $board,
        callable $mutation,
        callable $present,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        try {
            $result = $mutation();
        } catch (TaskStructureConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'meta' => ['current_structure_version' => $conflict->currentVersion],
            ], Response::HTTP_CONFLICT);
        }

        $versionBoard = $result instanceof TaskBoard ? $result : $board;

        return response()->json([
            'data' => $present($result),
            'meta' => ['structure_version' => (int) $versionBoard->fresh()->structure_version],
        ], $status);
    }
}
