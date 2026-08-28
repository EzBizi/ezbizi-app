<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class TaskImportsController extends TaskApiController
{
    public function __construct(private readonly TaskTransfer $transfer) {}

    public function preview(Request $request, TaskBoard $board): JsonResponse
    {
        $scope = ViewScope::forUser($request->user());
        $this->transfer->authorizeImport(
            $request->user(), $board, $scope, (string) $request->input('active_view_id'),
        );
        $input = $request->validate([
            'active_view_id' => ['required', 'uuid'],
            'mapping' => ['required', 'string', 'max:262144'],
            'file' => ['required', 'file', 'max:10240'],
        ]);
        try {
            $mapping = json_decode($input['mapping'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['mapping' => 'The import mapping must be valid JSON.']);
        }
        if (! is_array($mapping)) {
            throw ValidationException::withMessages(['mapping' => 'The import mapping must be a JSON object.']);
        }

        return response()->json(['data' => $this->transfer->preview(
            $request->user(), $board, $scope,
            $input['active_view_id'], $input['file'], $mapping,
        )]);
    }

    public function commit(Request $request, TaskBoard $board): JsonResponse
    {
        $scope = ViewScope::forUser($request->user());
        $this->transfer->authorizeImport(
            $request->user(), $board, $scope, (string) $request->input('active_view_id'),
        );
        $input = $request->validate([
            'active_view_id' => ['required', 'uuid'],
            'session_token' => ['required', 'string', 'size:64'],
        ]);

        return response()->json(['data' => $this->transfer->commit(
            $request->user(), $board, $scope, $input['active_view_id'], $input['session_token'],
        )], Response::HTTP_CREATED);
    }
}
