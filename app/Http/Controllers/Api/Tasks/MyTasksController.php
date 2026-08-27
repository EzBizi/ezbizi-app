<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskListQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MyTasksController extends TaskApiController
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskListQuery $tasks,
    ) {}

    public function index(Request $request): Response
    {
        $scope = ViewScope::forUser($request->user());
        $this->access->directoryDecision($request->user(), $scope)->authorize();
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $result = $this->tasks->myTasks(
            $request->user(), $scope,
            (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 50),
        );
        $etag = hash('sha256', json_encode([
            $scope->type, $scope->id, $result['fingerprint'], $result['page'], $result['per_page'],
        ], JSON_THROW_ON_ERROR));
        $response = response()->json([
            'data' => $result['data'],
            'meta' => [
                'total' => $result['total'], 'page' => $result['page'],
                'per_page' => $result['per_page'], 'last_page' => $result['last_page'],
            ],
        ])->setEtag($etag);
        $response->isNotModified($request);

        return $response;
    }
}
