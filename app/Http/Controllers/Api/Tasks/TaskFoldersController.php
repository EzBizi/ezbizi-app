<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskFolder;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskFolders;
use App\Support\Tasks\TaskPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskFoldersController extends TaskApiController
{
    public function __construct(private readonly TaskFolders $folders) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TaskBoard::class);
        $place = TaskPlace::fromView(ViewScope::forUser($request->user()));
        $folders = TaskFolder::query()->active()
            ->where('company_id', $place['company_id'])
            ->where('franchise_id', $place['franchise_id'])
            ->orderBy('position')->get();

        return response()->json(['data' => $folders->map(fn (TaskFolder $folder) => $this->present($folder))]);
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

        return response()->json(['data' => $this->present($folder)], Response::HTTP_CREATED);
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

        return response()->json(['data' => $this->present($result)]);
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

        return response()->json(['data' => $this->present($result)]);
    }

    private function present(TaskFolder $folder): array
    {
        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'company_id' => $folder->company_id,
            'franchise_id' => $folder->franchise_id,
            'position' => (int) $folder->position,
            'archived' => $folder->archived_at !== null,
        ];
    }
}
