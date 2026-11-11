<?php

namespace App\Http\Controllers\Api;

use App\Models\TaskBoard;
use App\Models\TaskBoardView;
use App\Support\Audit\AuditLogger;
use App\Support\Tasks\TaskBoards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Saved views (Jose, 2026-11-09, monday's "Save as new view"). Anyone who can
 * see the board saves their own; a shared one needs the board's edit right.
 * The filtering itself is the client's, over the tasks the board already sends.
 */
class TaskBoardViewsController extends Controller
{
    public function __construct(
        private readonly TaskBoards $boards,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);
        $input = $request->validate($this->rules(true));
        $shared = (bool) ($input['shared'] ?? false);
        abort_if($shared && ! $this->boards->canEdit($request->user(), $taskBoard),
            Response::HTTP_FORBIDDEN, 'Only people who can edit this board can share a view.');

        $view = TaskBoardView::query()->create([
            'organization_id' => $taskBoard->organization_id,
            'board_id' => $taskBoard->id,
            'user_id' => $shared ? null : $request->user()->id,
            'name' => $input['name'],
            'config' => $input['config'],
            'position' => (int) TaskBoardView::query()->where('board_id', $taskBoard->id)->max('position') + 1,
        ]);
        $this->audit->log('task_board.view_created', $view, actor: $request->user());

        return response()->json(['data' => self::present($view)], 201);
    }

    public function update(Request $request, TaskBoard $taskBoard, string $view): JsonResponse
    {
        $row = $this->writable($request, $taskBoard, $view);
        $input = $request->validate($this->rules(false));
        $row->fill(array_intersect_key($input, array_flip(['name', 'config', 'position'])))->save();
        $this->audit->log('task_board.view_updated', $row, actor: $request->user());

        return response()->json(['data' => self::present($row)]);
    }

    public function destroy(Request $request, TaskBoard $taskBoard, string $view): Response
    {
        $row = $this->writable($request, $taskBoard, $view);
        $this->audit->log('task_board.view_deleted', $row, actor: $request->user());
        $row->delete();

        return response()->noContent();
    }

    /** The board's shared views and this person's own, in order. */
    public static function visible(TaskBoard $board, string $userId): array
    {
        return TaskBoardView::query()->where('board_id', $board->id)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
            ->orderBy('position')->get()->map(fn (TaskBoardView $v) => self::present($v))->all();
    }

    public static function present(TaskBoardView $v): array
    {
        return ['id' => $v->id, 'name' => $v->name, 'shared' => $v->user_id === null, 'config' => $v->config];
    }

    private function writable(Request $request, TaskBoard $board, string $id): TaskBoardView
    {
        $this->authorize('view', $board);
        $row = TaskBoardView::query()->where('board_id', $board->id)->findOrFail($id);
        $user = $request->user();
        abort_unless($row->user_id === null ? $this->boards->canEdit($user, $board) : $row->user_id === $user->id,
            Response::HTTP_FORBIDDEN, 'This view is not yours to change.');

        return $row;
    }

    private function rules(bool $required): array
    {
        $req = $required ? ['required'] : ['sometimes'];

        return [
            'name' => [...$req, 'string', 'max:100'],
            'shared' => ['sometimes', 'boolean'],
            'config' => [...$req, 'array'],
            'config.match' => ['sometimes', 'in:all,any'],
            'config.filters' => ['sometimes', 'array', 'max:20'],
            'config.filters.*.field' => ['required', 'string', 'max:64'],
            'config.filters.*.op' => ['required', 'in:is,is_not,contains,empty,not_empty,before,after'],
            'config.filters.*.value' => ['nullable'],
            'config.sort' => ['sometimes', 'array', 'max:3'],
            'config.sort.*.field' => ['required', 'string', 'max:64'],
            'config.sort.*.dir' => ['required', 'in:asc,desc'],
            'config.people' => ['sometimes', 'array', 'max:50'],
            'config.people.*' => ['string', 'max:64'],
            'config.hidden' => ['sometimes', 'array', 'max:100'],
            'config.hidden.*' => ['string', 'max:64'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
