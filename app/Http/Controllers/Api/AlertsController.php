<?php

namespace App\Http\Controllers\Api;

use App\Models\Alert;
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
                'message' => $alert->message,
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
        ]);

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
        ]);

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

    private function authorizeSend(Request $request): void
    {
        abort_unless(
            $request->user()->hasPermission(self::PERMISSION),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('send alerts')->message(),
        );
    }
}
