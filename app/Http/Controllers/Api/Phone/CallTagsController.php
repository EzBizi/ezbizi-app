<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneCall;
use App\Models\PhoneCallTag;
use App\Support\Automations\EventEmitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The labels this organization counts its calls by (Settings > Tags).
 *
 * Editing the list is `phone.edit`. Putting a tag ON a call is not: whoever
 * answered the phone has to be able to say what it was about, and they will
 * usually be the person with `phone.view` and a line grant and nothing else.
 * Requiring edit here would mean the people doing the tagging cannot tag.
 */
class CallTagsController extends Controller
{
    public function __construct(private readonly EventEmitter $events) {}

    /** GET /api/v1/phone/tags */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCallTag::class);

        // Through row(), not a second copy of it.
        //
        // This built its own array, and when `color` was added to row() the
        // list kept returning tags without one. Create and update answered
        // with the colour, the LIST did not — so a swatch saved correctly,
        // the screen reloaded, and the colour vanished. It looked exactly like
        // clicking a colour did nothing.
        //
        // Two payloads for one resource is the same shape as two copies of a
        // rule: they agree until one of them is edited.
        $rows = PhoneCallTag::query()
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (PhoneCallTag $tag) => $this->row($tag))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/tags */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PhoneCallTag::class);

        $input = $request->validate([
            'name' => [
                'required', 'string', 'max:64',
                Rule::unique('phone_call_tags', 'name')
                    ->where('organization_id', $request->user()->organization_id),
            ],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            // Refused rather than coerced when unknown: a colour the client
            // cannot draw would render as no colour, which looks like the tag
            // simply did not save.
            'color' => ['sometimes', 'nullable', Rule::in(PhoneCallTag::COLORS)],
        ]);

        $tag = PhoneCallTag::query()->create([
            'name' => trim((string) $input['name']),
            'color' => $input['color'] ?? null,
            'is_active' => true,
            'position' => (int) ($input['position'] ?? 0),
        ]);

        return response()->json(['data' => $this->row($tag)], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/phone/tags/{tag} */
    public function update(Request $request, PhoneCallTag $tag): JsonResponse
    {
        $this->authorize('create', PhoneCallTag::class);

        $input = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:64',
                Rule::unique('phone_call_tags', 'name')
                    ->where('organization_id', $request->user()->organization_id)
                    ->ignore($tag->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            // Refused rather than coerced when unknown: a colour the client
            // cannot draw would render as no colour, which looks like the tag
            // simply did not save.
            'color' => ['sometimes', 'nullable', Rule::in(PhoneCallTag::COLORS)],
        ]);

        $tag->fill($input)->save();

        return response()->json(['data' => $this->row($tag->fresh())]);
    }

    /**
     * DELETE /api/v1/phone/tags/{tag}
     *
     * Refused once it has been used. Deleting would blank the label on every
     * call it described and silently change last month's totals; retiring it
     * keeps the history and takes it off the picker, which is what "delete"
     * almost always means here.
     */
    public function destroy(Request $request, PhoneCallTag $tag): JsonResponse
    {
        $this->authorize('create', PhoneCallTag::class);

        $used = $tag->calls()->count();

        if ($used > 0) {
            $tag->is_active = false;
            $tag->save();

            return response()->json([
                'data' => $this->row($tag->fresh()),
                'message' => 'That tag has been used on '.$used.' call(s), so it was '
                    .'retired instead of deleted. It stays on those calls and is no '
                    .'longer offered.',
            ]);
        }

        $tag->delete();

        return response()->json(['message' => 'That tag is gone.']);
    }

    /**
     * PATCH /api/v1/phone/calls/{call}/tag
     *
     * Only an ACTIVE tag may be put on a call. A retired one is history, not a
     * choice, and allowing it would make retiring meaningless.
     */
    public function tagCall(Request $request, PhoneCall $call): JsonResponse
    {
        $this->authorize('tag', PhoneCallTag::class);
        // And this call's line, when it has one (audit 11-27).
        abort_if(
            $call->number !== null
                && ! app(\App\Support\Phone\PhoneNumberAccess::class)->mayView($request->user(), $call->number),
            Response::HTTP_FORBIDDEN,
        );

        $input = $request->validate([
            'tag_id' => ['present', 'nullable', 'uuid'],
        ]);

        $tagId = $input['tag_id'];

        if ($tagId !== null) {
            $tag = PhoneCallTag::query()->where('is_active', true)->find($tagId);

            if ($tag === null) {
                return response()->json([
                    'message' => 'That tag is not one this organization offers any more.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $call->tag_id = $tagId;
        $call->tagged_by = $tagId === null ? null : $request->user()->id;
        $call->tagged_at = $tagId === null ? null : now();
        $call->save();

        // Raised only when a tag is PUT ON, never when one is taken off.
        //
        // Tagging is now required before somebody can hang up, so this is the
        // moment a call stops being "a call" and becomes "a complaint" or "a
        // booking" — which is the only moment a rule about complaints could
        // possibly fire. Without it the tag was a required field feeding a
        // report and nothing else: mandatory to enter, inert once entered.
        //
        // Clearing a tag raises nothing. "This turned out not to be a
        // complaint" is not an event anybody writes a rule against, and firing
        // on it would run the complaint rule a second time on the way out.
        if ($tagId !== null) {
            $this->events->emit('phone.call.tagged', $call, ['after' => [
                'id' => $call->id,
                'direction' => $call->direction,
                'from_e164' => $call->from_e164,
                'to_e164' => $call->to_e164,
                'number_id' => $call->number_id,
                'duration_seconds' => $call->duration_seconds,

                // The name as well as the id, because a rule reads "Complaint"
                // and an id means nothing to the person writing one.
                'tag_id' => $call->tag_id,
                'tag' => $tag?->name,
                'tagged_by' => $call->tagged_by,
            ]]);
        }

        return response()->json(['data' => [
            'id' => $call->id,
            'tag_id' => $call->tag_id,
            'tagged_at' => $call->tagged_at?->toIso8601String(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function row(PhoneCallTag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
            'is_active' => $tag->is_active,
            'position' => $tag->position,
            'call_count' => $tag->calls()->count(),
        ];
    }
}
