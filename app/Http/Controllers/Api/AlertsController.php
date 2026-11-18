<?php

namespace App\Http\Controllers\Api;

use App\Models\Alert;
use App\Models\AlertReply;
use App\Models\Notification;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * One person alerting chosen colleagues — Communication ▸ Alerts.
 *
 * Jose, 2026-10-07: "I'd like to be able to send a notificiations to specific
 * users, like an alert system, perhaps something to be added on the
 * communcation tab ... This sort of alert notifications should be a pop up on
 * the screen, that they can dismiss".
 *
 * ── Who may send: the tab IS the permission ──────────────────────
 *
 * `phone.alerts.view` is the Alerts tab's own key (the module convention: a tab
 * is `{module}.{tab}.view`), and opening a tab whose only purpose is sending
 * alerts is the same grant as being allowed to send one. One key, granted in
 * the roles editor under Communication ▸ Tabs ▸ Alerts, rather than a second
 * permission that could be ticked without the tab and do nothing.
 *
 * ── Delivery is the dispatcher's, deliberately ───────────────────
 *
 * Each alert is an ordinary notification with source `alert`: a bell row, the
 * desktop pop-up, the phone push, and each recipient's own "Alert me" switch
 * all apply exactly as for every other notification. What makes it an ALERT is
 * on the client: the bell raises an unread `alert` row as a banner that stays
 * on screen until dismissed.
 */
class AlertsController extends Controller
{
    private const PERMISSION = 'phone.alerts.view';

    private const MAX_RECIPIENTS = 50;

    private const MAX_MESSAGE = 500;

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /** GET /api/v1/alerts/recipients — the active people in this organization. */
    public function recipients(Request $request): JsonResponse
    {
        $this->authorizeSend($request);

        return response()->json([
            'data' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    /**
     * GET /api/v1/alerts — the alerts this person sent, newest first, each
     * with who it went to and when (or whether) they dismissed it.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSend($request);

        $alerts = Alert::query()
            ->where('sender_id', $request->user()->id)
            ->latest('created_at')
            ->limit(100)
            ->with('deliveries.user:id,name')
            ->get();

        return response()->json([
            'data' => $alerts->map(fn (Alert $alert) => [
                'id' => $alert->id,
                'kind' => $alert->kind,
                'message' => $alert->message,
                'answered' => $alert->isQuestion() ? $alert->replies()->count() : null,
                'created_at' => $alert->created_at?->toIso8601String(),
                'recipients' => $alert->deliveries->map(fn ($row) => [
                    'id' => $row->user_id,
                    'name' => $row->user?->name,
                    'dismissed_at' => $row->read_at?->toIso8601String(),
                ])->values(),
            ]),
        ]);
    }

    /** POST /api/v1/alerts — {user_ids: [...], message: "..."} */
    public function send(Request $request): JsonResponse
    {
        $this->authorizeSend($request);

        $input = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'user_ids.*' => ['required', 'string', 'distinct'],
            'message' => ['required', 'string', 'max:'.self::MAX_MESSAGE],
            // Questions (Jose 11-18): context, buttons and/or a reply box.
            'kind' => ['sometimes', Rule::in(['alert', Alert::KIND_QUESTION])],
            'context' => ['nullable', 'string', 'max:4000'],
            'choices' => ['nullable', 'array', 'max:8'],
            'choices.*' => ['required', 'string', 'max:200', 'distinct'],
            'allow_text' => ['sometimes', 'boolean'],
        ]);
        $question = ($input['kind'] ?? 'alert') === Alert::KIND_QUESTION;
        if ($question && empty($input['choices']) && ! ($input['allow_text'] ?? false)) {
            return response()->json([
                'message' => 'A question needs choices, a reply box (allow_text), or both, so it can be answered.',
            ], Status::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Tenant-scoped query, so an id from another organization simply is
        // not found — and a missing or inactive person is refused by name
        // rather than silently skipped, because "sent" must mean sent to
        // everybody the sender picked.
        $recipients = User::query()
            ->whereIn('id', $input['user_ids'])
            ->where('is_active', true)
            ->get();

        if ($recipients->count() !== count($input['user_ids'])) {
            return response()->json([
                'message' => 'One or more of those people is not an active user here, so nothing was sent.',
            ], Status::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sender = $request->user();

        // One Alert, and each recipient's notification points at it as its
        // subject: that is what lets the sender's list say "dismissed by N of
        // M" about ONE alert rather than M unrelated rows.
        $alert = Alert::create([
            'organization_id' => $sender->organization_id,
            'sender_id' => $sender->id,
            'message' => trim($input['message']),
            'kind' => $question ? Alert::KIND_QUESTION : 'alert',
            'context' => $question ? ($input['context'] ?? null) : null,
            'choices' => $question && ! empty($input['choices']) ? array_values($input['choices']) : null,
            'allow_text' => $question && ($input['allow_text'] ?? false),
        ]);
        if ($question) {
            app(AuditLogger::class)->log('alerts.question.sent', $alert, metadata: ['recipients' => $recipients->pluck('id')->all()]);
        }

        $told = 0;

        foreach ($recipients as $recipient) {
            $told += $this->dispatcher->dispatch(
                title: $alert->message,
                body: "From {$sender->name}",
                subject: $alert,
                onlyTo: $recipient,
                source: 'alert',
            ) > 0 ? 1 : 0;
        }

        return response()->json(['data' => ['id' => $alert->id, 'told' => $told]], Status::HTTP_CREATED);
    }

    /**
     * GET /api/v1/alerts/questions — questions put to ME, unanswered first,
     * so a dismissed pop-up is never lost (`unanswered` is the badge count).
     * Any signed-in person: being asked needs no tab.
     */
    public function questions(Request $request): JsonResponse
    {
        $me = $request->user()->id;
        $ids = Notification::query()->where('user_id', $me)->where('source', 'alert')
            ->where('subject_type', Alert::class)->pluck('subject_id');
        $rows = Alert::query()->whereIn('id', $ids)->where('kind', Alert::KIND_QUESTION)
            ->with(['sender:id,name', 'replies' => fn ($q) => $q->where('user_id', $me)->with('user:id,name')])
            ->latest('created_at')->limit(200)->get()
            ->map(fn (Alert $a) => $this->questionRow($a) + ['my_reply' => $this->replyRow($a->replies->first())])
            ->sortBy(fn (array $row) => $row['my_reply'] === null ? 0 : 1)
            ->values();

        return response()->json(['data' => $rows, 'meta' => ['unanswered' => $rows->whereNull('my_reply')->count()]]);
    }

    /** GET /api/v1/alerts/{alert}/replies — the asker reads every answer; a person asked reads only their own. */
    public function replies(Request $request, Alert $alert): JsonResponse
    {
        $me = $request->user()->id;
        $isSender = $alert->sender_id === $me;
        abort_unless($alert->isQuestion() && ($isSender || $this->wasAsked($alert, $me)), 404);

        $replies = $alert->replies()->with('user:id,name')->oldest('created_at')
            ->when(! $isSender, fn ($q) => $q->where('user_id', $me))->get();

        return response()->json(['data' => $this->questionRow($alert->load('sender:id,name')) + [
            'replies' => $replies->map(fn (AlertReply $r) => $this->replyRow($r))->values(),
        ]]);
    }

    /** POST /api/v1/alerts/{alert}/reply — {choice?, text?}; only the people asked, once each. */
    public function reply(Request $request, Alert $alert): JsonResponse
    {
        $me = $request->user();
        // Not found rather than forbidden: a 403 would confirm the question exists.
        abort_unless($alert->isQuestion() && $this->wasAsked($alert, $me->id), 404);

        $input = $request->validate([
            'choice' => ['nullable', 'string', Rule::in($alert->choices ?? [])],
            'text' => $alert->allow_text ? ['nullable', 'string', 'max:4000'] : ['prohibited'],
        ]);
        if (blank($input['choice'] ?? null) && blank($input['text'] ?? null)) {
            return response()->json(['message' => 'Pick a choice or write a reply.'], Status::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($alert->replies()->where('user_id', $me->id)->exists()) {
            return response()->json(['message' => 'You already answered this question.'], Status::HTTP_CONFLICT);
        }

        $reply = AlertReply::create([
            'organization_id' => $alert->organization_id,
            'alert_id' => $alert->id,
            'user_id' => $me->id,
            'choice' => $input['choice'] ?? null,
            'text' => filled($input['text'] ?? null) ? trim($input['text']) : null,
        ]);

        // Answering closes the pop-up.
        Notification::query()->where('user_id', $me->id)->where('source', 'alert')
            ->where('subject_type', Alert::class)->where('subject_id', $alert->id)
            ->whereNull('read_at')->update(['read_at' => now()]);
        app(AuditLogger::class)->log('alerts.question.answered', $alert, metadata: ['reply_id' => $reply->id]);

        // The asker's bell: "Bar answered: …".
        $sender = $alert->sender;
        if ($sender !== null && $sender->is_active) {
            $what = collect([$reply->choice, $reply->text])->filter()->implode(' — ');
            $this->dispatcher->dispatch(
                title: Str::limit("{$me->name} answered: {$what}", 200),
                body: Str::limit($alert->message, 200),
                subject: $alert,
                onlyTo: $sender,
                source: 'system',
                inAppOnly: true,
            );
        }

        return response()->json(['data' => $this->replyRow($reply->load('user:id,name'))], Status::HTTP_CREATED);
    }

    private function wasAsked(Alert $alert, string $userId): bool
    {
        return Notification::query()->where('user_id', $userId)->where('source', 'alert')
            ->where('subject_type', Alert::class)->where('subject_id', $alert->id)->exists();
    }

    private function questionRow(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'message' => $alert->message,
            'context' => $alert->context,
            'choices' => $alert->choices ?? [],
            'allow_text' => (bool) $alert->allow_text,
            'sender' => $alert->sender?->name,
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }

    private function replyRow(?AlertReply $reply): ?array
    {
        return $reply === null ? null : [
            'user_id' => $reply->user_id,
            'name' => $reply->user?->name,
            'choice' => $reply->choice,
            'text' => $reply->text,
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }

    private function authorizeSend(Request $request): void
    {
        abort_unless(
            $request->user()->hasPermission(self::PERMISSION),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('send alerts')->message(),
        );
    }
}
