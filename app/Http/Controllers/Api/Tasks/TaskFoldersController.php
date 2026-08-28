<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskFolder;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskFolders;
use App\Support\Tasks\TaskPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskFoldersController extends TaskApiController
{
    public function __construct(
        private readonly TaskFolders $folders,
        private readonly TaskBoardAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TaskBoard::class);
        $input = $request->validate(['archived' => ['sometimes', 'boolean']]);
        $scope = ViewScope::forUser($request->user());
        $place = TaskPlace::fromView($scope);
        $archived = (bool) ($input['archived'] ?? false);
        if ($archived) {
            $this->access->createDecision($request->user(), $scope)->authorize();
        }
        $folders = TaskFolder::query()
            ->when($archived, fn ($query) => $query->archived(), fn ($query) => $query->active())
            ->where('company_id', $place['company_id'])
            ->where('franchise_id', $place['franchise_id'])
            ->orderBy('position')->get();

        return response()->json(['data' => $folders->map(fn (TaskFolder $folder) =>
            $this->present($folder, $request, $scope))]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'organization_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'franchise_id' => ['prohibited'],
        ]);
        $folder = $this->folders->create($request->user(), ViewScope::forUser($request->user()), $input['name']);

        return response()->json(['data' => $this->present(
            $folder, $request, ViewScope::forUser($request->user()),
        )], Response::HTTP_CREATED);
    }

    public function update(Request $request, TaskFolder $folder): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'organization_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'franchise_id' => ['prohibited'],
        ]);
        $result = $this->folders->update(
            $request->user(), $folder, ViewScope::forUser($request->user()), $input['name'],
        );

        return response()->json(['data' => $this->present(
            $result, $request, ViewScope::forUser($request->user()),
        )]);
    }

    public function archive(Request $request, TaskFolder $folder): JsonResponse
    {
        return $this->archiveState($request, $folder, true);
    }

    public function restore(Request $request, TaskFolder $folder): JsonResponse
    {
        return $this->archiveState($request, $folder, false);
    }

    private function archiveState(Request $request, TaskFolder $folder, bool $archived): JsonResponse
    {
        $result = $this->folders->setArchived(
            $request->user(), $folder, ViewScope::forUser($request->user()), $archived,
        );

        return response()->json(['data' => $this->present(
            $result, $request, ViewScope::forUser($request->user()),
        )]);
    }

    private function present(TaskFolder $folder, Request $request, ViewScope $scope): array
    {
        $canManage = $this->folders->canManage($request->user(), $folder, $scope);

        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'company_id' => $folder->company_id,
            'franchise_id' => $folder->franchise_id,
            'position' => (int) $folder->position,
            'archived' => $folder->archived_at !== null,
            'actions' => $canManage
                ? ($folder->archived_at === null ? ['update', 'archive'] : ['restore'])
                : [],
        ];
    }
}
