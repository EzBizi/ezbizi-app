<?php

namespace App\Http\Controllers\Api;

use App\Models\TaskBoardFolder;
use App\Models\Setting;
use App\Support\Settings\SettingDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Folders on the Task Board list (Jose 11-13): shared for the organization.
 * Anyone who sees boards reads them; making, renaming, colouring, nesting,
 * ordering and deleting needs tasks.create_boards, the same right as making
 * a board. Deleting a folder leaves its boards loose and its subfolders go.
 */
class TaskBoardFoldersController extends Controller
{
    /** A colour is "#rrggbb", like a status's; Monday's folder colours import as their hex. */
    public const COLOUR = 'regex:/^#[0-9a-fA-F]{6}$/';

    public function index(): JsonResponse
    {
        return response()->json(['data' => TaskBoardFolder::query()
            ->orderBy('position')->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'colour', 'position'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate($request);
        $data = $request->validate($this->rules(true));

        return response()->json(['data' => TaskBoardFolder::query()->create($data)], 201);
    }

    public function update(Request $request, TaskBoardFolder $folder): JsonResponse
    {
        $this->gate($request);
        $data = $request->validate($this->rules(false));
        abort_if(isset($data['parent_id']) && $this->wouldLoop($folder, $data['parent_id']), 422, 'A folder cannot go inside itself.');
        $folder->update($data);

        return response()->json(['data' => $folder->fresh()]);
    }

    public function destroy(Request $request, TaskBoardFolder $folder): JsonResponse
    {
        $this->gate($request);
        $folder->delete();

        return response()->json(null, 204);
    }

    /** The settings key: {"company:<name>" | "branch:<franchise id>": "#rrggbb"}. */
    public const GROUP_COLOURS = 'tasks.board_group_colours';

    /** The colours of the board list's automatic company and branch groups, the organization's. */
    public function groupColours(Request $request): JsonResponse
    {
        return response()->json(['data' => (object) $this->readGroupColours($request)]);
    }

    /** Sets or clears (null) one group's colour; everyone in the organization sees it. */
    public function updateGroupColours(Request $request): JsonResponse
    {
        $this->gate($request);
        $data = $request->validate([
            'group' => ['required', 'string', 'max:300', 'regex:/^(company|branch):/'],
            'colour' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $colours = $this->readGroupColours($request);
        if ($data['colour'] === null) {
            unset($colours[$data['group']]);
        } else {
            $colours[$data['group']] = strtolower($data['colour']);
        }
        abort_if(count($colours) > 500, 422, 'Too many group colours.');
        $org = (string) $request->user()->organization_id;
        Setting::query()->updateOrCreate(
            ['scope_type' => SettingDefinition::SCOPE_ORGANIZATION, 'scope_id' => $org, 'key' => self::GROUP_COLOURS],
            ['organization_id' => $org, 'value' => $colours],
        );

        return response()->json(['data' => (object) $colours]);
    }

    /** @return array<string, string> */
    private function readGroupColours(Request $request): array
    {
        $value = Setting::query()
            ->where('scope_type', SettingDefinition::SCOPE_ORGANIZATION)
            ->where('scope_id', (string) $request->user()->organization_id)
            ->where('key', self::GROUP_COLOURS)
            ->value('value');

        return is_array($value) ? $value : [];
    }

    private function gate(Request $request): void
    {
        abort_unless($request->user()->hasPermission('tasks.create_boards'), 403, 'Only somebody who may make boards can arrange the board list.');
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'colour' => ['sometimes', 'nullable', self::COLOUR],
            // Checked through the tenant-scoped model, so another organization's folder is "not found".
            'parent_id' => ['sometimes', 'nullable', 'uuid', fn ($a, $v, $fail) => $v === null || TaskBoardFolder::query()->whereKey($v)->exists() ?: $fail('That folder is not in this organization.')],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    private function wouldLoop(TaskBoardFolder $folder, string $parentId): bool
    {
        for ($id = $parentId; $id !== null; $id = TaskBoardFolder::query()->whereKey($id)->value('parent_id')) {
            if ($id === $folder->id) {
                return true;
            }
        }

        return false;
    }
}
