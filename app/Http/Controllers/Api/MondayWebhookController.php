<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SyncMondayItem;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/integrations/monday/webhook — Monday says an item changed.
 *
 * Unauthenticated by necessity (Monday calls it); what makes it Monday is the
 * JWT in the Authorization header, signed with the app's Signing Secret
 * (MondayWebhookSignature). An unsigned or wrongly signed event is a 401 and
 * changes nothing. The event names an item; the item is re-read from Monday
 * (SyncMondayItem), so nothing in the request body is written anywhere.
 *
 * Monday's one-time `challenge` on registration is echoed back: it carries no
 * data and reaches nothing.
 */
class MondayWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (is_string($request->json('challenge')) && $request->json('event') === null) {
            return response()->json(['challenge' => $request->json('challenge')]);
        }

        $claims = MondayWebhookSignature::verify($request->header('Authorization'), (string) config('services.monday.signing_secret'));
        if ($claims === null) {
            return response()->json(['message' => 'Not signed by Monday.'], 401);
        }

        $event = (array) $request->json('event', []);
        $itemId = (string) ($event['parentItemId'] ?? $event['pulseId'] ?? '');
        $boardId = (string) ($event['boardId'] ?? '');
        if ($itemId === '' || ! ctype_digit($itemId)) {
            return response()->json(['queued' => 0]);
        }

        // Where it lands: the task already holding the item, else the board
        // holding Monday's board (a new item). Every organization that has it.
        $targets = Task::query()->withoutGlobalScopes()->where('tasks.external_ref', 'monday:'.$itemId)->whereNull('tasks.deleted_at')
            ->join('task_boards', 'task_boards.id', '=', 'tasks.board_id')
            ->get(['tasks.organization_id', 'task_boards.external_ref'])
            ->map(fn ($r) => [$r->organization_id, $r->external_ref]);
        if ($targets->isEmpty() && ctype_digit($boardId)) {
            $targets = TaskBoard::query()->withoutGlobalScopes()->where('external_ref', 'monday:'.$boardId)->whereNull('deleted_at')
                ->get(['organization_id', 'external_ref'])->map(fn ($b) => [$b->organization_id, $b->external_ref]);
        }

        $queued = 0;
        foreach ($targets as [$organizationId, $boardRef]) {
            if (! is_string($boardRef) || ! str_starts_with($boardRef, 'monday:')) {
                continue;
            }
            $connection = SocialConnection::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('provider', SocialProvider::Monday->value)
                ->where('status', SocialConnection::STATUS_CONNECTED)
                // The account the token was signed for, when Monday says which.
                ->when(isset($claims['accountId']), fn ($q) => $q->where('remote_id', (string) $claims['accountId']))
                ->first();
            if ($connection !== null) {
                SyncMondayItem::dispatch((string) $connection->getKey(), substr($boardRef, strlen('monday:')), $itemId);
                $queued++;
            }
        }

        return response()->json(['queued' => $queued]);
    }
}
