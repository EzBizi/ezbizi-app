<?php

namespace App\Http\Controllers\Api;

use App\Services\A1Leads\A1LeadsGbpReplies;
use App\Services\A1Leads\A1LeadsOwner;
use App\Services\A1Leads\A1LeadsReviewOpsClient;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The A1Leads GBP tab's review operations, moved over from A1 Tools before it
 * is retired (Rodrigo's cutover checklist, rows 1-4; his routes of 25 Sep):
 *
 *   Draft with AI            POST reviews/{id}/draft        a1leads_gbp.reply
 *   Workiz match suggestions GET  reviews/{id}/suggestions  a1leads_gbp.manage
 *   Pin or clear the match   POST reviews/{id}/match        a1leads_gbp.manage
 *   One workflow-card change POST cards/mark                a1leads_gbp.manage
 *   Queue a policy report    POST report                    a1leads_gbp.manage
 *   Report queue and cap     GET  report/status             a1leads_gbp.view
 *
 * Every one is refused unless the caller is at the organization A1 Leads is
 * named to and has A-1 Chimney in view (`A1LeadsOwner`), and holds the
 * permission beside it - checked HERE, because A1 Leads trusts the actorId we
 * send and so the question of who may is ours. The actor is always the
 * signed-in user; nothing in a request body names one.
 *
 * Nothing here posts to Google. A draft is text for a person to read and edit;
 * the public reply still goes only through `A1LeadsGbpReplies`.
 */
class A1LeadsReviewOpsController extends Controller
{
    /** The four statuses an operator may set. "Reported" and "Removed" are A1 Leads' own. */
    public const STATUSES = ["Can't locate", 'Working on it', 'Awaiting report', 'Done'];

    public function __construct(
        private readonly A1LeadsReviewOpsClient $ops,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function draft(Request $request, string $id): JsonResponse
    {
        if ($refusal = $this->refusal($request, 'a1leads_gbp.reply', $id)) {
            return $refusal;
        }

        return $this->answer($this->ops->draft((int) $id, $this->actor($request)));
    }

    public function suggestions(Request $request, string $id): JsonResponse
    {
        if ($refusal = $this->refusal($request, 'a1leads_gbp.manage', $id)) {
            return $refusal;
        }

        return $this->answer($this->ops->suggestions((int) $id, mb_substr((string) $request->query('q', ''), 0, 120)));
    }

    public function match(Request $request, string $id): JsonResponse
    {
        if ($refusal = $this->refusal($request, 'a1leads_gbp.manage', $id)) {
            return $refusal;
        }

        $clear = $request->boolean('clear');
        $tenant = trim((string) $request->input('tenant', ''));
        $jobId = trim((string) $request->input('jobId', ''));
        if (! $clear && ($tenant === '' || $jobId === '' || strlen($tenant) > 40 || strlen($jobId) > 60)) {
            return self::error(422, 'invalid_match', 'Choose a Workiz job from the suggestions.');
        }

        $result = $this->ops->match((int) $id, $this->actor($request), $tenant, $jobId, $clear);

        return $this->answer($result, 'a1leads_gbp.match_changed', array_filter([
            'review_id' => (int) $id,
            'cleared' => $clear ?: null,
            'workiz_tenant' => $clear ? null : $tenant,
            'workiz_job_id' => $clear ? null : $jobId,
        ]));
    }

    public function markCard(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request, 'a1leads_gbp.manage')) {
            return $refusal;
        }

        $ref = trim((string) $request->input('ref', ''));
        if (! A1LeadsGbpReplies::validReviewName($ref)) {
            return self::error(422, 'invalid_review_name', 'Choose a review from this page.');
        }

        // Exactly ONE change per request, as A1 Leads requires.
        $status = $request->input('status');
        $note = $request->input('add_update');
        if (is_string($status) && $note === null) {
            if (! in_array($status, self::STATUSES, true)) {
                return self::error(422, 'invalid_status', 'Choose one of: '.implode(', ', self::STATUSES).'.');
            }
            $change = ['status' => $status];
        } elseif (is_string($note) && $status === null) {
            $note = trim($note);
            if ($note === '' || mb_strlen($note) > 4000) {
                return self::error(422, 'invalid_update', 'Write a note of up to 4,000 characters.');
            }
            $change = ['add_update' => $note];
        } else {
            return self::error(422, 'one_change', 'Change the status or add a note - one at a time.');
        }

        $result = $this->ops->markCard($ref, $this->actor($request), $change);

        // The note's words are the operator's, and stay in A1 Leads' timeline;
        // our audit records that one was added, and the status when it is one.
        return $this->answer($result, 'a1leads_gbp.card_changed', array_filter([
            'google_review_name' => $ref,
            'status' => $change['status'] ?? null,
            'note_added' => isset($change['add_update']) ?: null,
        ]));
    }

    public function report(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request, 'a1leads_gbp.manage')) {
            return $refusal;
        }

        $ids = array_values(array_filter((array) $request->input('reviewIds', []), fn ($v) => is_numeric($v) && (int) $v > 0));
        $reason = (string) $request->input('reason', '');
        if ($ids === [] || count($ids) > 50) {
            return self::error(422, 'invalid_reviews', 'Choose between 1 and 50 reviews to report.');
        }
        if (preg_match('/^[a-z_]{3,40}$/', $reason) !== 1) {
            return self::error(422, 'invalid_reason', 'Choose the policy reason for the report.');
        }
        if ($request->input('confirmed') !== true) {
            return self::error(422, 'not_confirmed', 'Confirm the report before it is queued.');
        }

        $result = $this->ops->queueReport(array_map('intval', $ids), $reason, $this->actor($request));

        return $this->answer($result, 'a1leads_gbp.report_queued', [
            'review_ids' => array_map('intval', $ids),
            'reason' => $reason,
        ]);
    }

    public function reportStatus(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request, 'a1leads_gbp.view')) {
            return $refusal;
        }

        return $this->answer($this->ops->reportStatus());
    }

    /**
     * Why this person may not do this here, or null when they may.
     *
     * The organization first, then A-1 Chimney in view, then the permission -
     * the same order the tab itself asks them in, so the sentence names the
     * first thing to change.
     */
    private function refusal(Request $request, string $permission, ?string $reviewId = null): ?JsonResponse
    {
        $org = config('services.a1leads.organization_id');
        $company = config('services.a1leads.company_id');
        $user = $request->user();

        if (! is_string($org) || $org === '' || $this->tenant->id() !== $org
            || ! is_string($company) || $company === '' || $user === null) {
            return self::error(403, 'wrong_organization', 'A1 Leads holds one business\'s Business Profile, and this organization is not the one it belongs to.');
        }
        if (! A1LeadsOwner::inView($user, $company)) {
            return self::error(403, 'wrong_company_view', "These are A-1 Chimney's Google reviews. Switch to A-1 Chimney to see them.");
        }
        if (! $user->hasPermission($permission)) {
            return self::error(403, 'no_permission', $permission === 'a1leads_gbp.reply'
                ? 'Drafting a reply needs the A1Leads GBP Reply permission.'
                : 'Changing a review\'s card, match or report needs the A1Leads GBP Manage permission.');
        }
        if ($reviewId !== null && preg_match('/^[1-9][0-9]{0,18}$/', $reviewId) !== 1) {
            return self::error(422, 'invalid_review', 'Choose a review from this page.');
        }

        return null;
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getKey();
    }

    /**
     * A1 Leads' answer, unchanged - and, for a write it ACCEPTED, one audit row.
     *
     * @param  array{status: int, body: array<string, mixed>}  $result
     * @param  array<string, mixed>  $metadata
     */
    private function answer(array $result, ?string $auditAction = null, array $metadata = []): JsonResponse
    {
        if ($auditAction !== null && $result['status'] >= 200 && $result['status'] < 300) {
            $this->audit->log($auditAction, null, [], $metadata);
        }

        return response()->json($result['body'], $result['status']);
    }

    private static function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => compact('code', 'message')], $status);
    }
}
