<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskTransfer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TaskExportsController extends TaskApiController
{
    public function __construct(private readonly TaskTransfer $transfer) {}

    public function show(Request $request, TaskBoard $board, string $format): StreamedResponse
    {
        $scope = ViewScope::forUser($request->user());
        $this->transfer->authorizeExport(
            $request->user(), $board, $scope, (string) $request->input('active_view_id'), false,
        );
        $input = $request->validate([
            'active_view_id' => ['required', 'uuid'],
            'include_archived' => ['sometimes', 'boolean'],
        ]);
        $includeArchived = (bool) ($input['include_archived'] ?? false);
        if ($includeArchived || $format === 'json') {
            $this->transfer->authorizeExport(
                $request->user(), $board, $scope, $input['active_view_id'], true,
            );
        }

        return $this->transfer->export(
            $request->user(), $board, $scope, $input['active_view_id'], $format, $includeArchived,
        );
    }
}
