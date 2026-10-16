<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneConnection;
use App\Models\PhoneFlow;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Support\Phone\PhoneScope;
use App\Support\Phone\TwimlRenderer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone call flows — the menu a caller hears (spec §7).
 *
 * ── Why a flow is one menu and not a tree ────────────────────────
 *
 * The spec allows nodes to nest. `TwimlRenderer` reads exactly one: it finds
 * the first `menu` and routes on the digit pressed. Shipping an editor that
 * could draw a three-level tree the renderer walks past would be a screen that
 * lies — somebody would build "press 2 then 4 for accounts" and callers would
 * never reach it.
 *
 * So this is deliberately one menu with up to ten options. The stored shape is
 * already the nested one (`{type: menu, options: [...]}`), so deepening it
 * later is a renderer change and a second editor, not a migration.
 *
 * ── What an option may do ────────────────────────────────────────
 *
 * ring_group · voicemail · forward · hangup · repeat · extension. Every one
 * of those is something the renderer actually performs today.
 */
class FlowsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** GET /api/v1/phone/flows */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);

        // This had NO filter at all: every menu in the tenant, to everybody,
        // whichever company they were looking at. The others at least filtered
        // by reach; this one did not filter.
        $rows = PhoneScope::apply(PhoneFlow::query(), $request->user())
            ->orderBy('name')
            ->get()
            ->map(fn (PhoneFlow $flow) => $this->row($flow))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/flows */
    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request, creating: true);

        $connection = PhoneConnection::query()->findOrFail($input['connection_id']);
        $this->authorize('update', $connection);

        $this->assertGroupsOnSameAccount($input['options'], $connection);

        [$scopeType, $scopeId] = PhoneScope::filing($request->user());

        $flow = PhoneFlow::query()->create([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'connection_id' => $connection->id,
            'name' => trim((string) $input['name']),
            'nodes' => $this->nodes($input),
        ]);

        return response()->json(['data' => $this->row($flow)], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/phone/flows/{flow} */
    public function update(Request $request, PhoneFlow $flow): JsonResponse
    {
        $connection = $flow->connection;
        abort_if($connection === null, Response::HTTP_NOT_FOUND, 'That flow has no phone account.');

        $this->authorize('update', $connection);

        $input = $this->validated($request, creating: false);

        $this->assertGroupsOnSameAccount($input['options'], $connection);

        $flow->name = trim((string) $input['name']);
        $flow->nodes = $this->nodes($input);
        $flow->save();

        return response()->json(['data' => $this->row($flow->fresh())]);
    }

    /** DELETE /api/v1/phone/flows/{flow} */
    public function destroy(Request $request, PhoneFlow $flow): JsonResponse
    {
        $connection = $flow->connection;
        abort_if($connection === null, Response::HTTP_NOT_FOUND, 'That flow has no phone account.');

        $this->authorize('update', $connection);

        // A line pointing at a deleted flow would gather against a menu that no
        // longer exists, so the lines are cleared first rather than left to
        // discover it on the next call.
        PhoneNumber::query()->where('flow_id', $flow->id)->update(['flow_id' => null]);

        $flow->delete();

        return response()->json(['message' => 'That menu is gone.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'connection_id' => [
                $creating ? 'required' : 'sometimes',
                'uuid',
                $this->existsInTenant('phone_connections'),
            ],
            'name' => ['required', 'string', 'max:191'],
            'prompt' => ['required', 'string', 'max:1000'],

            // A recording played instead of the text (Jose, 2026-10-05: "text
            // to speech or recorded"). The name POST /phone/audio returned;
            // the text stays, as what the chart shows and the fallback.
            'prompt_audio' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-f-]{36}\.(mp3|wav)$/'],

            // WHEN NOBODY PRESSES A KEY. Jose, 2026-09-28: *"after the menu
            // ends, it tries to connect the call, instead of ending the call,
            // where do i set it up"*. How long to wait for a key, how many
            // times to play it again, then ring the line or end the call.
            'wait_seconds' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'replays' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'no_input' => ['sometimes', Rule::in(['ring', 'hangup'])],

            // Ten, because a caller cannot press an eleventh key.
            'options' => ['present', 'array', 'max:10'],
            'options.*.digit' => ['required', 'string', 'distinct', 'regex:/^[0-9*#]$/'],
            'options.*.action' => ['required', Rule::in(['ring_group', 'voicemail', 'forward', 'hangup', 'repeat', 'extension'])],
            'options.*.ring_group_id' => [
                'nullable',
                'required_if:options.*.action,ring_group',
                'uuid',
                $this->existsInTenant('phone_ring_groups'),
            ],
            'options.*.number' => [
                'nullable',
                'required_if:options.*.action,forward',
                'string',
                'max:16',
            ],
            'options.*.say' => ['nullable', 'string', 'max:500'],

            // Same shape ExtensionsController stores, because these digits are
            // looked up against those rows at call time. Anything else is a key
            // that tells every caller who presses it there is no such extension.
            'options.*.extension' => [
                'nullable',
                'required_if:options.*.action,extension',
                'string',
                'regex:/^\d{2,8}$/',
            ],
        ]);
    }

    /**
     * A ring group on another Twilio account expands to Client identities this
     * call can never reach, so the Dial would ring nobody and look correct.
     * Same check the Numbers screen makes for `ring_group_id`.
     *
     * @param  array<int, array<string, mixed>>  $options
     */
    private function assertGroupsOnSameAccount(array $options, PhoneConnection $connection): void
    {
        foreach ($options as $index => $option) {
            $groupId = $option['ring_group_id'] ?? null;

            if (! is_string($groupId) || $groupId === '') {
                continue;
            }

            $group = PhoneRingGroup::query()->find($groupId);

            if ($group === null || $group->connection_id !== $connection->id) {
                throw ValidationException::withMessages([
                    "options.$index.ring_group_id" => 'That ring group belongs to a different phone account, so this line could never reach it.',
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function nodes(array $input): array
    {
        return [
            'type' => 'menu',
            'prompt' => trim((string) $input['prompt']),
            'prompt_audio' => $input['prompt_audio'] ?? null,
            'wait_seconds' => (int) ($input['wait_seconds'] ?? 5),
            'replays' => (int) ($input['replays'] ?? 0),
            'no_input' => (string) ($input['no_input'] ?? 'ring'),
            'options' => array_values(array_map(
                fn (array $option) => array_filter([
                    'digit' => (string) $option['digit'],
                    'action' => (string) $option['action'],
                    'ring_group_id' => $option['ring_group_id'] ?? null,
                    'number' => $option['number'] ?? null,
                    'say' => $option['say'] ?? null,
                    'extension' => $option['extension'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''),
                $input['options'],
            )),
        ];
    }

    /** @return array<string, mixed> */
    private function row(PhoneFlow $flow): array
    {
        $nodes = is_array($flow->nodes) ? $flow->nodes : [];

        return [
            'id' => $flow->id,
            'connection_id' => $flow->connection_id,
            'name' => $flow->name,
            'prompt' => $nodes['prompt'] ?? '',
            'prompt_audio' => $nodes['prompt_audio'] ?? null,
            'wait_seconds' => (int) ($nodes['wait_seconds'] ?? 5),
            'replays' => (int) ($nodes['replays'] ?? 0),
            'no_input' => (string) ($nodes['no_input'] ?? 'ring'),
            'options' => array_values(array_filter(
                (array) ($nodes['options'] ?? []),
                'is_array',
            )),

            // WHETHER THIS MENU EVER PLAYS.
            //
            // A menu can be built, named, given keys and options and attached
            // to a line, and still contain no `menu` node — at which point the
            // renderer skips it and the caller goes straight past. Nothing
            // fails: the menu saves, this list shows it, and no call ever
            // reaches it. Nobody complains either, because the caller does not
            // know they missed a menu and the owner does not know theirs is
            // skipped.
            //
            // Answered by the renderer's own lookup rather than by counting
            // options here, so the screen cannot disagree with the call.
            'plays' => TwimlRenderer::menuNodeOf($flow) !== null,

            // Which lines play this menu. Deleting one is a decision about
            // those lines, so the screen has to be able to say which.
            'number_ids' => PhoneNumber::query()
                ->where('flow_id', $flow->id)
                ->pluck('id')
                ->all(),
        ];
    }
}
