<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskView;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskListQuery;
use App\Support\Tasks\TaskViewCodec;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskBoardRowsController extends TaskApiController
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskViewCodec $codec,
        private readonly TaskListQuery $rows,
    ) {}

    public function index(Request $request, TaskBoard $board): Response
    {
        $scope = ViewScope::forUser($request->user());
        $this->access->viewDecision($request->user(), $board, $scope)->authorize();
        $input = $request->validate([
            'view_id' => ['sometimes', 'uuid'],
            'configuration' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $view = $this->view($board, $request->user(), $scope, $input['view_id'] ?? null);
        $configuration = $view->configuration;
        if (isset($input['configuration'])) {
            $decoded = json_decode($input['configuration'], true);
            if (! is_array($decoded)) {
                return response()->json(['message' => 'The configuration must be valid JSON.', 'errors' => [
                    'configuration' => ['The configuration must be valid JSON.'],
                ]], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $configuration = $decoded;
        }
        $configuration = $this->codec->decode($board, $configuration);
        $result = $this->rows->boardRows(
            $request->user(),
            $scope,
            $board,
            $configuration,
            (int) ($input['page'] ?? 1),
            (int) ($input['per_page'] ?? 50),
        );
        $etag = hash('sha256', json_encode([
            $result['fingerprint'], $view->id, (int) $view->version, $configuration,
            $result['page'], $result['per_page'],
        ], JSON_THROW_ON_ERROR));
        $response = response()->json([
            'data' => $result['data'],
            'meta' => [
                'total' => $result['total'], 'page' => $result['page'],
                'per_page' => $result['per_page'], 'last_page' => $result['last_page'],
                'group_counts' => $result['group_counts'],
                'status_summaries' => $result['status_summaries'],
                'view_id' => $view->id, 'view_version' => (int) $view->version,
                'configuration' => $configuration,
            ],
        ])->setEtag($etag);
        $response->isNotModified($request);

        return $response;
    }

    private function view(TaskBoard $board, User $user, ViewScope $scope, ?string $id): TaskView
    {
        $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $user, $scope)
            ->select('task_boards.id');
        $query = TaskView::query()->where('board_id', $board->id)->whereIn('board_id', $visibleBoards)
            ->where(fn ($visible) => $visible->where('visibility', 'shared')->orWhere('owner_user_id', $user->id));

        return $id === null
            ? $query->where('is_permanent_main', true)->firstOrFail()
            : $query->findOrFail($id);
    }
}
