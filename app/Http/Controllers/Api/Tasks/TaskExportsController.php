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
        $input = $request->validate(['include_archived' => ['sometimes', 'boolean']]);

        return $this->transfer->export(
            $request->user(), $board, ViewScope::forUser($request->user()), $format,
            (bool) ($input['include_archived'] ?? false),
        );
    }
}
