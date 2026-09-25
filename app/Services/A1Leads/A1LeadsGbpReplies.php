<?php

namespace App\Services\A1Leads;

use App\Models\A1LeadsReplyReceipt;
use App\Models\Automation;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * THE one path from EzBizi to a public reply on A-1 Chimney's Google reviews.
 *
 * A person pressing "Post it" on the A1Leads GBP tab and an automation Bar set
 * up (Jose, 2026-09-25: "this will be set using EzBizi Automations that Bar
 * himself will set up for the reply") both come through here, so there is one
 * place that talks to Rodrigo's live writer, one audit shape and one receipt.
 * A1 Leads stays the single Google writer; this only asks it to write.
 *
 * WHO may call it is the caller's question, not this class's: the controller
 * checks the signed-in user's `a1leads_gbp.reply` and the company in view; the
 * automation action checks the rule author's. This validates the request,
 * speaks to the writer, and records the outcome - every outcome.
 *
 * Returns the writer's own answer, `['status' => int, 'body' => array]`, and
 * never throws for a refusal. The refusals a caller should expect, by
 * `body.error.code` (HTTP status in brackets):
 *
 *   invalid_review_name / invalid_reply_text   (422)  bad input
 *   already_answered                           (409)  the live review has a
 *                                                     reply; nothing was sent
 *   stale / revision codes from A1 Leads       (409)  the reviewer edited since
 *   waiting_for_owner_edge                     (503)  the writer is unreachable
 *   reply_outcome_unknown                      (503)  the connection dropped
 *                                                     DURING the post: Google
 *                                                     may have it. Never retry
 *                                                     blindly.
 *   reply_not_configured                       (503)  the key is unset
 *
 * A confirmed send is 2xx with `body.data.ok === true`.
 */
class A1LeadsGbpReplies
{
    public function __construct(
        private readonly A1LeadsReplyClient $client,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  string|null  $expectedRevision  the live `reviewUpdatedAt` the
     *                                         person saw. Null (an automation)
     *                                         reads it live first.
     * @return array{status: int, body: array<string, mixed>}
     */
    public function publish(
        string $googleReviewName,
        string $text,
        ?string $expectedRevision,
        ?User $actor,
        ?Automation $automation = null,
    ): array {
        $name = trim($googleReviewName);
        $text = trim($text);

        if (! self::validReviewName($name)) {
            return $this->refused($name, self::failure(422, 'invalid_review_name', 'Choose a review from this page.'), $actor, $automation);
        }
        if ($text === '' || mb_strlen($text) > 4000) {
            return $this->refused($name, self::failure(422, 'invalid_reply_text', 'Write a reply of up to 4,000 characters.'), $actor, $automation);
        }

        $expected = trim((string) $expectedRevision);
        if ($expected === '') {
            // Nobody saw the review: read it live, exactly as the editor does,
            // and never answer one that already has a reply.
            $live = $this->client->preflight($name);
            if ($live['status'] < 200 || $live['status'] >= 300) {
                return $this->refused($name, $live, $actor, $automation);
            }
            if (($live['body']['data']['canReply'] ?? false) !== true) {
                return $this->refused($name, self::failure(409, 'already_answered',
                    'This review already has a reply on Google. Nothing was sent.'), $actor, $automation);
            }
            $expected = (string) ($live['body']['data']['reviewUpdatedAt'] ?? '');
        }
        if ($expected === '' || strlen($expected) > 80) {
            return $this->refused($name, self::failure(422, 'missing_review_revision', 'Check the live review again before sending.'), $actor, $automation);
        }

        $result = $this->client->publish(
            $name,
            $text,
            $expected,
            $actor !== null ? (string) $actor->getKey() : 'automation:'.$automation?->getKey(),
        );

        if ($result['status'] < 200 || $result['status'] >= 300 || ($result['body']['data']['ok'] ?? false) !== true) {
            // Anything short of a confirmed send - refused by A1 Leads, a stale
            // revision, an already-answered review, or a connection that
            // dropped mid-POST with the outcome unknown - is an attempt, and
            // is recorded.
            return $this->refused($name, $result, $actor, $automation);
        }

        $data = $result['body']['data'];
        $said = (string) ($data['text'] ?? $text);

        try {
            A1LeadsReplyReceipt::query()->updateOrCreate(
                ['organization_id' => $this->tenant->idOrFail(), 'google_review_name' => $name],
                [
                    'reply_text' => $said,
                    'sent_at' => isset($data['sentAt']) ? Carbon::parse($data['sentAt'])->utc() : null,
                    'actor_id' => $actor?->getKey(),
                ],
            );
            $result['body']['data']['receiptPersisted'] = true;
        } catch (\Throwable $error) {
            report($error);
            // Google has already accepted the reply. Never turn that fact
            // into an apparent failed send that invites a duplicate retry.
            $result['body']['data']['receiptPersisted'] = false;
        }

        // Outside the receipt's try: a failure to write the receipt must not
        // also lose the record of who spoke for the business.
        $this->audit->log(
            'a1leads_gbp.review_replied',
            null,
            ['reply' => $said],
            array_filter([
                'google_review_name' => $name,
                'sent_at' => $data['sentAt'] ?? null,
                'automation_id' => $automation?->getKey(),
            ], fn ($v) => $v !== null),
            $actor,
        );

        return $result;
    }

    /**
     * Record a reply attempt that did NOT end in a confirmed send, and return
     * the refusal unchanged.
     *
     * Who tried, which review, the status and the machine code - and never the
     * reply text. A refused reply was not published, so the words are not the
     * business's public statement. `reply_outcome_unknown` is recorded here
     * too: the connection dropped during the POST, and the audit must not read
     * as "nothing happened" when Google may have accepted it.
     *
     * Public so the controller's own refusals (no permission, wrong company)
     * are recorded in the same shape.
     *
     * @param  array{status: int, body: array<string, mixed>}  $result
     * @return array{status: int, body: array<string, mixed>}
     */
    public function refused(string $name, array $result, ?User $actor, ?Automation $automation = null): array
    {
        $this->audit->log(
            'a1leads_gbp.review_reply_failed',
            null,
            [],
            array_filter([
                'google_review_name' => $name !== '' ? mb_substr($name, 0, 300) : null,
                'status' => $result['status'],
                'code' => $result['body']['error']['code'] ?? null,
                'automation_id' => $automation?->getKey(),
            ], fn ($v) => $v !== null),
            $actor,
        );

        return $result;
    }

    public static function validReviewName(string $name): bool
    {
        return strlen($name) <= 300
            && preg_match('#^accounts/[^/]+/locations/[^/]+/reviews/[^/]+$#D', $name) === 1;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function failure(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => compact('code', 'message')]];
    }
}
