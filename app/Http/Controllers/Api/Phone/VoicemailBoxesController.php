<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Support\Phone\PhoneScope;
use App\Models\PhoneConnection;
use App\Models\PhoneVoicemailBox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;
use App\Models\PhoneRingGroup;
use App\Models\PhoneNumber;
use App\Models\Company;

/**
 * Voicemail boxes: the greeting a caller hears, and who is told about it.
 *
 * Every line used to take a message with the same sentence, with no way to say
 * who the caller has reached or when somebody will ring back. That greeting is
 * the last thing a customer hears before deciding whether to try a competitor.
 */
class VoicemailBoxesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/voicemail-boxes */
    public function index(Request $request): JsonResponse
    {
        $connections = $this->reachableConnectionIds($request);

        if ($connections === []) {
            return response()->json(['data' => []]);
        }

        $rows = PhoneScope::apply(
            PhoneVoicemailBox::query()->whereIn('connection_id', $connections),
            $request->user(),
        )
            ->orderBy('name')
            ->get()
            ->map(fn (PhoneVoicemailBox $box) => $this->row($box))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/voicemail-boxes */
    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request);

        $connection = PhoneConnection::query()->find($input['connection_id']);
        abort_if($connection === null, Response::HTTP_NOT_FOUND);
        $this->authorize('update', $connection);

        [$scopeType, $scopeId] = PhoneScope::filing($request->user());

        $box = PhoneVoicemailBox::query()->create([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            ...$input,
            'organization_id' => $this->tenant->idOrFail(),
        ]);

        // THE FIRST BOX BECOMES THE COMPANY'S, because otherwise it is a box
        // nothing points at.
        //
        // A line's voicemail box is `number ?? connection`, so a freshly made
        // box catches nothing until somebody also sets it on a line or as the
        // company default — two more screens, neither of them mentioned where
        // the box was made. Jose made "A-1 Chimney Mailbox", eleven lines went
        // on taking messages under no box at all, and clicking his own box
        // showed an empty list. Measured: own-lines 0, connection default
        // none, eleven lines with no box of their own.
        //
        // Only when the company has NO default yet, so this can never take a
        // choice away from somebody who has made one. A second box is still an
        // ordinary box and still has to be pointed at deliberately.
        if (! $connection->default_voicemail_box_id) {
            $connection->forceFill(['default_voicemail_box_id' => $box->id])->save();
        }

        return response()->json(['data' => $this->row($box)], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/phone/voicemail-boxes/{box} */
    public function update(Request $request, PhoneVoicemailBox $box): JsonResponse
    {
        $connection = $box->connection;
        abort_if($connection === null, Response::HTTP_NOT_FOUND);
        $this->authorize('update', $connection);

        $input = $this->validated($request, creating: false);
        unset($input['connection_id']);

        $box->fill($input)->save();

        return response()->json(['data' => $this->row($box->fresh())]);
    }

    /** DELETE /api/v1/phone/voicemail-boxes/{box} */
    public function destroy(Request $request, PhoneVoicemailBox $box): JsonResponse
    {
        $connection = $box->connection;
        abort_if($connection === null, Response::HTTP_NOT_FOUND);
        $this->authorize('update', $connection);

        // Lines pointing here fall back to the built-in greeting rather than
        // to silence. Leaving a dangling id would have the renderer look up
        // nothing and take a message with no greeting at all.
        \App\Models\PhoneNumber::query()
            ->where('voicemail_box_id', $box->id)
            ->update(['voicemail_box_id' => null]);

        // AND THE COMPANY, which was the half this forgot.
        //
        // Lines were repointed and the connection was not, so deleting the
        // company's default left every line inheriting an id that resolves to
        // nothing — the same "box nothing points at" defect, arrived at from
        // the other end, and this time wearing a setting that looks deliberate.
        //
        // Handed to the next oldest box rather than nulled, because a company
        // with a box and no default is precisely the state that had eleven of
        // Jose's lines taking messages nobody could find. Null only when that
        // was the last box, where there is genuinely nothing to point at.
        if ($connection->default_voicemail_box_id === $box->id) {
            $next = PhoneVoicemailBox::query()
                ->where('connection_id', $connection->id)
                ->whereKeyNot($box->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            $connection->forceFill([
                'default_voicemail_box_id' => $next?->id,
            ])->save();
        }

        $box->delete();

        return response()->json(['data' => ['id' => $box->id, 'deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'connection_id' => [$creating ? 'required' : 'sometimes', 'uuid'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],

            'owner_type' => ['sometimes', Rule::in(PhoneVoicemailBox::OWNERS)],

            // Null for the company-wide box. "The company" is not a row, so
            // there is nothing honest to point at.
            'owner_id' => ['sometimes', 'nullable', 'uuid'],

            'greeting_mode' => ['sometimes', Rule::in(['tts', 'audio'])],
            'greeting_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'greeting_voice' => ['sometimes', 'nullable', 'string', 'max:48'],
            'greeting_language' => ['sometimes', 'string', 'max:16'],
            'greeting_audio_url' => ['sometimes', 'nullable', 'url', 'max:2048'],

            // Twilio's own bounds. Below ten seconds a caller cannot finish a
            // sentence; above an hour is not a voicemail.
            'max_seconds' => ['sometimes', 'integer', 'min:10', 'max:3600'],

            'transcribe' => ['sometimes', 'boolean'],
            'notify_emails' => ['sometimes', 'array', 'max:20'],
            'notify_emails.*' => ['email'],
        ]);
    }

    /** @return list<string> */
    private function reachableConnectionIds(Request $request): array
    {
        return PhoneConnection::query()->get()
            ->filter(fn (PhoneConnection $c) => $request->user()->can('view', $c))
            ->pluck('id')
            ->all();
    }

    /** The owner's name, or null where the box belongs to no one in particular. */
    private function ownerName(PhoneVoicemailBox $box): ?string
    {
        $id = (string) $box->owner_id;
        if ($id === '') {
            return null;
        }

        return match ($box->owner_type) {
            PhoneVoicemailBox::OWNER_USER => User::query()->find($id)?->name,
            PhoneVoicemailBox::OWNER_RING_GROUP => PhoneRingGroup::query()->find($id)?->name,
            PhoneVoicemailBox::OWNER_NUMBER => PhoneNumber::query()->find($id)?->e164,
            PhoneVoicemailBox::OWNER_COMPANY => Company::query()->find($id)?->name,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function row(PhoneVoicemailBox $box): array
    {
        return [
            'id' => $box->id,
            'connection_id' => $box->connection_id,
            'name' => $box->name,
            'owner_type' => $box->owner_type,
            'owner_id' => $box->owner_id,

            // WHOSE box, in words. The id alone means a screen listing boxes
            // has to look every owner up itself to draw a name, and a screen
            // that cannot say "Ana's" is a list of boxes nobody can tell apart.
            'owner_name' => $this->ownerName($box),

            // How many lines actually send voicemail here.
            //
            // A box with none is the failure this module keeps producing: it
            // exists, it lists, it opens, and it is empty forever — and an
            // empty box is indistinguishable on screen from a quiet week. The
            // count is what lets the Voicemail screen say "nothing routes here
            // yet" instead of "nothing waiting".
            'line_count' => PhoneNumber::query()
                ->where('voicemail_box_id', $box->id)
                ->count(),
            'greeting_mode' => $box->greeting_mode,
            'greeting_text' => $box->greeting_text,
            'greeting_voice' => $box->greeting_voice,
            'greeting_language' => $box->greeting_language,
            'greeting_audio_url' => $box->greeting_audio_url,
            'max_seconds' => (int) $box->max_seconds,
            'transcribe' => (bool) $box->transcribe,
            'notify_emails' => $box->notify_emails ?? [],

            // Exactly what a caller hears, decided by the model rather than
            // guessed on screen — a preview that disagrees with the call is
            // worse than no preview.
            'spoken_greeting' => $box->spokenGreeting()
                ?? 'Please leave a message after the tone.',
        ];
    }
}
