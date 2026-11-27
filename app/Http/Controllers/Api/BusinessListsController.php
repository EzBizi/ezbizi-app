<?php

namespace App\Http\Controllers\Api;

use App\Models\BusinessListItem;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Settings\Lists\ListDefinition;
use App\Support\Settings\Lists\ListRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings › Lists over the API (Jose, 2026-11-04): a row is added, changed or
 * removed in ONE call, addressed by its own key, so the ezbizi MCP can add an
 * alias without rewriting the list.
 *
 *   GET    /settings/lists
 *   POST   /settings/lists/{list}/items          {key, value, sort?}
 *   PATCH  /settings/lists/{list}/items/{item}   {value?, sort?}
 *   DELETE /settings/lists/{list}/items/{item}
 *
 * The first write to a list that still runs on its code defaults copies those
 * defaults in first, so adding one row never silently drops the others.
 * Every write is audited against the organization, with before and after.
 */
class BusinessListsController
{
    public function __construct(
        private readonly ListRegistry $registry,
        private readonly BusinessLists $lists,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => array_values(array_map(fn (ListDefinition $d) => [
            'key'      => $d->key,
            'label'    => $d->label,
            'module'   => $d->module,
            'help'     => $d->help,
            'columns'  => $d->columns,
            'edited'   => $this->lists->edited($d->key),
            'may_edit' => $user->hasPermission($d->editPermission),
            'items'    => $this->lists->rows($d->key),
        ], $this->registry->all()))]);
    }

    public function store(Request $request, string $list): JsonResponse
    {
        $definition = $this->writable($request, $list);
        $data = $request->validate([
            // Optional: people never type a key, so it is the first text
            // column's text (UI/UX design 181), made unique within the list.
            'key'   => ['sometimes', 'nullable', 'string', 'max:191'],
            'value' => ['present', 'array'],
            'sort'  => ['sometimes', 'integer', 'min:0'],
        ]);
        $value = $this->cast($definition, $data['value']);
        $key = filled($data['key'] ?? null) ? trim($data['key']) : $this->derivedKey($definition, $value);

        $row = DB::transaction(function () use ($definition, $key, $value, $data, $request) {
            $this->materialise($definition, $request);

            $existing = BusinessListItem::query()->where('list_key', $definition->key)->where('item_key', $key)->first();

            if ($existing?->active) {
                throw ValidationException::withMessages(['key' => "'{$key}' is already in this list."]);
            }

            $row = $existing ?? new BusinessListItem([
                'organization_id' => $request->user()->organization_id,
                'list_key'        => $definition->key,
                'item_key'        => $key,
            ]);
            $row->fill([
                'value'  => $value,
                'sort'   => $data['sort'] ?? (int) BusinessListItem::query()->where('list_key', $definition->key)->max('sort') + 1,
                'active' => true,
            ])->save();

            $this->audited('settings.list_item_added', $request, $definition, $key, null, $value);

            return $row;
        });

        return response()->json(['data' => $this->present($row)], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $list, string $item): JsonResponse
    {
        $definition = $this->writable($request, $list);
        $data = $request->validate([
            'value' => ['sometimes', 'array'],
            'sort'  => ['sometimes', 'integer', 'min:0'],
        ]);

        $row = DB::transaction(function () use ($definition, $item, $data, $request) {
            $this->materialise($definition, $request);
            $row = $this->activeRow($definition, $item);
            $before = $row->value;

            $row->fill(array_filter([
                'value' => array_key_exists('value', $data) ? [...($row->value ?? []), ...$this->cast($definition, $data['value'])] : null,
                'sort'  => $data['sort'] ?? null,
            ], fn ($v) => $v !== null))->save();

            $this->audited('settings.list_item_changed', $request, $definition, $item, $before, $row->value);

            return $row;
        });

        return response()->json(['data' => $this->present($row)]);
    }

    public function destroy(Request $request, string $list, string $item): JsonResponse
    {
        $definition = $this->writable($request, $list);

        DB::transaction(function () use ($definition, $item, $request) {
            $this->materialise($definition, $request);
            $row = $this->activeRow($definition, $item);
            $row->forceFill(['active' => false])->save();

            $this->audited('settings.list_item_removed', $request, $definition, $item, $row->value, null);
        });

        return response()->json(['data' => null]);
    }

    /**
     * POST /settings/lists/{list}/reset — back to the code defaults.
     *
     * Every stored row goes, so the list reads its defaults again; the rows
     * are recorded in the audit first, so nothing is lost without a trace.
     */
    public function reset(Request $request, string $list): JsonResponse
    {
        $definition = $this->writable($request, $list);

        DB::transaction(function () use ($definition, $request) {
            $rows = BusinessListItem::query()->where('list_key', $definition->key)->get();
            $this->audited('settings.list_reset', $request, $definition, '*',
                ['rows' => $rows->map(fn ($r) => ['key' => $r->item_key, 'value' => $r->value, 'active' => $r->active])->all()], null);
            BusinessListItem::query()->where('list_key', $definition->key)->delete();
        });

        return response()->json(['data' => $this->lists->rows($definition->key)]);
    }

    private function derivedKey(ListDefinition $definition, array $value): string
    {
        $first = collect($definition->columns)->first(fn ($c) => ($c['type'] ?? 'text') === 'text');
        // The text itself, not a slug: an email or a phone IS the row
        // (a slug would turn a@b.com into ab_com).
        $base = trim((string) ($value[$first['key'] ?? ''] ?? ''));

        if ($base === '') {
            throw ValidationException::withMessages(['value' => "Fill in '".($first['label'] ?? 'the first column')."' first."]);
        }

        $taken = [
            ...array_column($definition->defaults, 'key'),
            ...BusinessListItem::query()->where('list_key', $definition->key)->where('active', true)->pluck('item_key')->all(),
        ];
        // Case-insensitive, as the column's collation is: "Bar" IS "bar" to
        // the unique index.
        $taken = array_map('mb_strtolower', $taken);
        $key = $base;
        for ($n = 2; in_array(mb_strtolower($key), $taken, true); $n++) {
            $key = "{$base} ({$n})";
        }

        return $key;
    }

    private function writable(Request $request, string $list): ListDefinition
    {
        $definition = $this->registry->get($list);
        abort_if($definition === null, Response::HTTP_NOT_FOUND, "Unknown list '{$list}'.");

        abort_unless(
            $request->user()->hasPermission($definition->editPermission),
            Response::HTTP_FORBIDDEN,
            Refusal::permission("change the ‘{$definition->label}’ list")->message(),
        );

        return $definition;
    }

    /** Copy the code defaults in on the first write, so the list keeps them. */
    private function materialise(ListDefinition $definition, Request $request): void
    {
        if ($this->lists->edited($definition->key)) {
            return;
        }

        foreach ($definition->defaults as $i => $row) {
            BusinessListItem::create([
                'organization_id' => $request->user()->organization_id,
                'list_key'        => $definition->key,
                'item_key'        => $row['key'],
                'value'           => $row['value'],
                'sort'            => $i,
                'active'          => true,
            ]);
        }
    }

    private function activeRow(ListDefinition $definition, string $item): BusinessListItem
    {
        $row = BusinessListItem::query()->where('list_key', $definition->key)
            ->where('item_key', $item)->where('active', true)->first();
        abort_if($row === null, Response::HTTP_NOT_FOUND, "'{$item}' is not in this list.");

        return $row;
    }

    private function cast(ListDefinition $definition, array $value): array
    {
        try {
            return $definition->cast($value);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['value' => $e->getMessage()]);
        }
    }

    private function audited(string $action, Request $request, ListDefinition $d, string $item, ?array $before, ?array $after): void
    {
        $this->audit->log(
            action: $action,
            target: $request->user()->organization,
            changes: ['list' => $d->key, 'item' => $item, 'before' => $before, 'after' => $after],
            scopeType: 'organization',
            scopeId: $request->user()->organization_id,
        );
    }

    private function present(BusinessListItem $row): array
    {
        return ['key' => $row->item_key, 'value' => $row->value ?? [], 'sort' => $row->sort];
    }
}
