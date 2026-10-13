<?php

namespace App\Http\Controllers\Api;

use App\Models\AssistantEntry;
use App\Models\Role;
use App\Models\StaffBotItem;
use App\Models\StaffBotMenu;
use App\Models\TrainingGuide;
use App\Support\Audit\AuditLogger;
use App\Support\Training\GuideScreens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI Assistance › Staff bot: the staff line's menus, one for each group of
 * roles, and what each row links to (Jose, 2026-10-13: "One number, menu by
 * role ... i also want to see it all in ezbizi so it can be modified as well";
 * UI queue 88). Organization-level, under `ai_assistance.manage` (the route's
 * middleware), as WhatsApp groups are.
 */
class StaffBotMenusController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Every menu with its items, and what an item can be pointed at. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => StaffBotMenu::query()->with('items.guide', 'items.entry')->orderBy('position')->orderBy('created_at')
                ->get()->map(fn (StaffBotMenu $m) => $this->present($m))->values(),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Role $r) => ['id' => $r->getKey(), 'name' => $r->name])->values(),
            // Each guide's chapters and the sections inside them, in the
            // guide's own order, for the guide › chapter › section picker.
            'guides' => TrainingGuide::query()->orderBy('title')->get()->map(fn (TrainingGuide $g) => [
                'id' => $g->getKey(),
                'title' => $g->title,
                'chapters' => collect(GuideScreens::topics($g))
                    ->groupBy(fn ($t) => (string) $t['chapter'])
                    ->map(fn ($topics, string $chapter) => [
                        'name' => $chapter === '' ? null : $chapter,
                        'sections' => $topics->pluck('section')->filter()->unique()->values(),
                    ])->values(),
            ])->values(),
            'entries' => AssistantEntry::query()->orderBy('section')->orderBy('title')->get(['id', 'section', 'title'])
                ->map(fn (AssistantEntry $e) => ['id' => $e->getKey(), 'section' => $e->section, 'title' => $e->title])
                ->values(),
            'reports' => collect(StaffBotItem::REPORTS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'max_items' => StaffBotMenu::MAX_ITEMS,
        ]);
    }

    public function storeMenu(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:24'],
            'role_ids' => ['array'],
            'role_ids.*' => ['uuid'],
        ]);

        $menu = StaffBotMenu::create([
            'name' => $input['name'],
            'role_ids' => $this->roles($input['role_ids'] ?? []),
            'position' => (int) StaffBotMenu::query()->max('position') + 1,
        ]);
        $this->audit->log('staff_bot.menu_created', $menu, metadata: ['name' => $menu->name]);

        return response()->json(['data' => $this->present($menu->load('items'))], Response::HTTP_CREATED);
    }

    public function updateMenu(Request $request, StaffBotMenu $menu): JsonResponse
    {
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:24'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['uuid'],
        ]);

        if (array_key_exists('role_ids', $input)) {
            $input['role_ids'] = $this->roles($input['role_ids']);
        }
        $menu->fill($input)->save();
        $this->audit->log('staff_bot.menu_updated', $menu, metadata: ['name' => $menu->name]);

        return response()->json(['data' => $this->present($menu->load('items.guide', 'items.entry'))]);
    }

    public function destroyMenu(StaffBotMenu $menu): JsonResponse
    {
        $this->audit->log('staff_bot.menu_deleted', $menu, metadata: ['name' => $menu->name]);
        $menu->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function storeItem(Request $request, StaffBotMenu $menu): JsonResponse
    {
        if ($menu->items()->count() >= StaffBotMenu::MAX_ITEMS) {
            throw ValidationException::withMessages(['title' => 'WhatsApp shows '.StaffBotMenu::MAX_ITEMS
                .' topics in a menu (the tenth row is Find a location). Remove one to add another.']);
        }

        $item = $menu->items()->create([
            ...$this->itemInput($request, required: true),
            'organization_id' => $menu->organization_id,
            'position' => (int) $menu->items()->max('position') + 1,
        ]);
        $this->audit->log('staff_bot.item_created', $item, metadata: ['title' => $item->title]);

        return response()->json(['data' => $this->presentItem($item->load('guide', 'entry'))], Response::HTTP_CREATED);
    }

    public function updateItem(Request $request, StaffBotItem $item): JsonResponse
    {
        $item->fill($this->itemInput($request, required: false))->save();
        $this->audit->log('staff_bot.item_updated', $item, metadata: ['title' => $item->title]);

        return response()->json(['data' => $this->presentItem($item->load('guide', 'entry'))]);
    }

    public function destroyItem(StaffBotItem $item): JsonResponse
    {
        $this->audit->log('staff_bot.item_deleted', $item, metadata: ['title' => $item->title]);
        $item->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** PUT menus/{menu}/order {item_ids: [...]}: the rows in WhatsApp's order. */
    public function order(Request $request, StaffBotMenu $menu): JsonResponse
    {
        $ids = $request->validate(['item_ids' => ['required', 'array'], 'item_ids.*' => ['uuid']])['item_ids'];
        $own = $menu->items()->pluck('id')->all();

        if (count($ids) !== count($own) || array_diff($own, $ids) !== []) {
            throw ValidationException::withMessages(['item_ids' => 'Send every item of this menu, once each.']);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                StaffBotItem::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });

        return response()->json(['data' => $this->present($menu->fresh()->load('items.guide', 'items.entry'))]);
    }

    /** @return array<string, mixed> */
    private function itemInput(Request $request, bool $required): array
    {
        $need = $required ? 'required' : 'sometimes';
        $input = $request->validate([
            'title' => [$need, 'string', 'max:'.StaffBotItem::MAX_TITLE],
            'line' => ['sometimes', 'nullable', 'string', 'max:'.StaffBotItem::MAX_LINE],
            'source' => [$need, Rule::in(StaffBotItem::SOURCES)],
            'training_guide_id' => ['sometimes', 'nullable', 'uuid'],
            'chapter' => ['sometimes', 'nullable', 'string', 'max:191'],
            'section' => ['sometimes', 'nullable', 'string', 'max:191'],
            'assistant_entry_id' => ['sometimes', 'nullable', 'uuid'],
            'report' => ['sometimes', 'nullable', Rule::in(array_keys(StaffBotItem::REPORTS))],
        ]);

        // Ids of this business only: both models are tenant-scoped, so a
        // foreign id is simply not found, and is refused rather than stored.
        foreach (['training_guide_id' => TrainingGuide::class, 'assistant_entry_id' => AssistantEntry::class] as $key => $model) {
            if (($input[$key] ?? null) !== null && ! $model::query()->whereKey($input[$key])->exists()) {
                throw ValidationException::withMessages([$key => 'That is not one of this business\'s.']);
            }
        }

        return $input;
    }

    /** @param list<string> $ids  @return list<string> */
    private function roles(array $ids): array
    {
        return Role::query()->whereIn('id', $ids)->pluck('id')->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(StaffBotMenu $menu): array
    {
        return [
            'id' => $menu->getKey(),
            'name' => $menu->name,
            'role_ids' => $menu->role_ids ?? [],
            'items' => $menu->items->map(fn (StaffBotItem $i) => $this->presentItem($i))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentItem(StaffBotItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'title' => $item->title,
            'line' => $item->line,
            'source' => $item->source,
            'training_guide_id' => $item->training_guide_id,
            'guide_title' => $item->guide?->title,
            'chapter' => $item->chapter,
            'section' => $item->section,
            'assistant_entry_id' => $item->assistant_entry_id,
            'entry_title' => $item->entry?->title,
            'report' => $item->report,
            // "Ready" when the bot has something to send; the editor shows
            // "Needs content" otherwise.
            'ready' => $item->answer() !== null,
        ];
    }
}
