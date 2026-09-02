<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskListQuery;
use App\Support\Tasks\TaskPresentation;
use App\Support\Tasks\TaskWindow;
use App\Support\Tasks\TaskWorkloadQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class MyTasksController extends TaskApiController
{
    /**
     * Presentations this endpoint can show across the current view.
     * Kanban is deliberately absent — a bucket field is board-structure
     * -specific (columns, custom fields, option lists) and has no
     * cross-board meaning; opening the board is the honest way to see a
     * Kanban of it, exactly as dragging a card here would need the target
     * board's write authorization resolved per card (task-14 brief).
     */
    private const CROSS_BOARD_PRESENTATIONS = [
        TaskPresentation::Table, TaskPresentation::Calendar,
        TaskPresentation::Timeline, TaskPresentation::Workload,
    ];

    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskListQuery $tasks,
        private readonly TaskWorkloadQuery $workload,
    ) {}

    public function index(Request $request): Response
    {
        $scope = ViewScope::forUser($request->user());
        // THE SAME ACCESS PATH `myTasks()` ALREADY USES, reused verbatim for
        // every presentation below — `directoryDecision()` (module + permission
        // + current-view reach) plus `TaskBoardAccess::constrainVisible()`
        // (scope-visible boards union active private membership) inside
        // `TaskListQuery`/`TaskWorkloadQuery`. Phase 1 proved this exact
        // prefilter against a two-process membership-revocation race; Task 14
        // adds no second one — see `TaskListQuery::crossBoardRows()`.
        $this->access->directoryDecision($request->user(), $scope)->authorize();
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'configuration' => ['sometimes', 'string'],
        ]);
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 50);

        [$presentation, $window] = $this->decodeConfiguration($input['configuration'] ?? null);

        if ($presentation === TaskPresentation::Table) {
            $result = $this->tasks->myTasks($request->user(), $scope, $page, $perPage);
        } else {
            $result = $this->tasks->crossBoardRows($request->user(), $scope, $presentation, $window, $page, $perPage);
            if ($presentation === TaskPresentation::Workload) {
                $workload = $this->workload->rows($request->user(), $scope, null, $window);
                $result['data'] = $workload['data'];
                $result['omitted']['unowned'] = $workload['omitted']['unowned'];
            }
        }

        $etag = hash('sha256', json_encode([
            $scope->type, $scope->id, $result['fingerprint'], $result['page'], $result['per_page'],
            $presentation->value, $window?->toArray(),
        ], JSON_THROW_ON_ERROR));
        $meta = [
            'total' => $result['total'], 'page' => $result['page'],
            'per_page' => $result['per_page'], 'last_page' => $result['last_page'],
        ];
        // Additive only: a frozen client that never sends `configuration`
        // always gets Table, and Table's meta shape stays byte-identical to
        // what it was before this endpoint could show anything else.
        if ($presentation !== TaskPresentation::Table) {
            $meta['omitted'] = $result['omitted'];
            $meta['window'] = $result['window'];
        }
        $response = response()->json([
            'data' => $result['data'],
            'meta' => $meta,
        ])->setEtag($etag);
        $response->isNotModified($request);

        return $response;
    }

    /** @return array{0:TaskPresentation,1:?TaskWindow} */
    private function decodeConfiguration(?string $raw): array
    {
        if ($raw === null) {
            return [TaskPresentation::Table, null];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_string($decoded['presentation'] ?? null)) {
            throw ValidationException::withMessages([
                'configuration' => ['The configuration must be valid JSON with a presentation.'],
            ]);
        }
        $presentation = TaskPresentation::tryFrom($decoded['presentation']);
        if ($presentation === null || ! in_array($presentation, self::CROSS_BOARD_PRESENTATIONS, true)) {
            throw ValidationException::withMessages([
                'configuration.presentation' => ['This presentation is not available across boards.'],
            ]);
        }
        if ($presentation === TaskPresentation::Table) {
            return [TaskPresentation::Table, null];
        }

        return [$presentation, TaskWindow::parse($presentation, $decoded['window'] ?? null)];
    }
}
