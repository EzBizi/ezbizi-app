<?php

namespace App\Http\Controllers\Api;

use App\Models\StaffMessage;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Colleagues messaging each other.
 *
 * ── Why there is no permission for this ─────────────────────────
 *
 * Every other surface in EzBizi is gated, and this one is not, deliberately.
 * The thing being protected elsewhere is a customer's data or a company's
 * money; here both ends are staff of the same organization, and a colleague
 * you may not message is not a concept this business has. Adding a permission
 * would invent one, and the first thing it would do is stop somebody asking a
 * question mid-shift.
 *
 * The boundary that DOES matter is the organization, and BelongsToTenant
 * enforces it on every query below.
 */
class StaffMessagesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/staff-messages?with={userId}
     *
     * A conversation, and marking it read in the same breath.
     *
     * Opening a thread IS reading it — there is no separate "mark as read"
     * because there is no moment where somebody has the messages on screen and
     * has not read them. A button for it would be a second thing to forget.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'with' => ['required', 'uuid'],
        ]);

        $me = $request->user();

        $other = User::query()->find($input['with']);
        abort_if($other === null, Response::HTTP_NOT_FOUND);

        $rows = StaffMessage::query()
            ->between($me->id, $other->id)
            // The NEWEST 200, oldest first on screen (audit 11-27: oldest 200
            // hid every new message in a busy thread).
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();

        // Only the ones written TO me. Marking my own sent messages read would
        // make "seen" mean nothing.
        StaffMessage::query()
            ->where('sender_id', $other->id)
            ->where('recipient_id', $me->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => $rows->map(fn (StaffMessage $m) => $this->row($m, $me->id))->all(),
        ]);
    }

    /**
     * GET /api/v1/staff-messages/unread
     *
     * How many are waiting, and from whom.
     *
     * Per sender rather than one total, because the Team panel needs to mark
     * the PERSON. A single number tells somebody they have messages without
     * telling them where to look, which on a list of colleagues is most of the
     * question.
     */
    public function unread(Request $request): JsonResponse
    {
        $counts = StaffMessage::query()
            ->where('recipient_id', $request->user()->id)
            ->whereNull('read_at')
            ->select('sender_id', DB::raw('COUNT(*) as waiting'))
            ->groupBy('sender_id')
            ->pluck('waiting', 'sender_id');

        return response()->json([
            'data' => $counts,
            'total' => (int) $counts->sum(),
        ]);
    }

    /** POST /api/v1/staff-messages */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'to' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $me = $request->user();

        $other = User::query()->find($input['to']);
        abort_if($other === null, Response::HTTP_NOT_FOUND);

        // Messaging yourself is not a feature, and allowing it would make the
        // thread query return the same row twice.
        abort_if($other->id === $me->id, Response::HTTP_UNPROCESSABLE_ENTITY);

        $message = StaffMessage::query()->create([
            'organization_id' => $this->tenant->idOrFail(),
            'sender_id' => $me->id,
            'recipient_id' => $other->id,
            'body' => trim($input['body']),
        ]);

        return response()->json(
            ['data' => $this->row($message, $me->id)],
            Response::HTTP_CREATED,
        );
    }

    /** @return array<string, mixed> */
    private function row(StaffMessage $message, string $meId): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'sent_at' => $message->created_at?->toIso8601String(),

            // Whose side of the thread this sits on, decided here so the
            // screen does not have to know who it is looking at.
            'mine' => $message->sender_id === $meId,

            // Only meaningful on messages I sent — nobody needs telling when
            // they read their own.
            'read_at' => $message->sender_id === $meId
                ? $message->read_at?->toIso8601String()
                : null,
        ];
    }
}
