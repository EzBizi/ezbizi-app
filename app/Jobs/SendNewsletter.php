<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\NewsletterSend;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Newsletter\BlockRenderer;
use App\Support\Newsletter\NewsletterSections;
use App\Support\Newsletter\NewsletterIdentity;
use App\Support\Newsletter\NewsletterSenders;
use App\Support\Newsletter\NewsletterSendFailed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hand one newsletter to the account that carries it.
 *
 * ── `tries = 1`, and it matters more here than anywhere ─────────
 *
 * Mailchimp's send has no idempotency key. A retry after a timeout cannot tell
 * "the campaign did not send" from "it sent and the answer did not come back",
 * and the failure mode is twelve hundred people receiving the same mailing
 * twice. So an unknown outcome stops and says so, and sending again is a
 * person's decision. This is the same reasoning `PublishSocialTarget` carries,
 * with a larger blast radius.
 */
final class SendNewsletter implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * A customer list is pushed 500 addresses a call, but a large one still
     * outlasts the default worker's 60 s. `database-long` runs with a 160 s
     * worker timeout and retry_after 200, both above this.
     */
    public int $timeout = 150;

    /** The sentence a send carries when it stopped without an answer. */
    public const OUTCOME_UNKNOWN = 'The send stopped before Mailchimp answered, so its outcome is unknown. '
        .'Check Mailchimp before sending again.';

    public function __construct(
        public readonly string $sendId,
        public readonly string $connectionId,
    ) {
        // Where queued work runs at all; a sync queue (tests, local) runs it inline.
        if (config('queue.default') !== 'sync') {
            $this->onConnection('database-long')->onQueue('long');
        }
    }

    /** A timeout or crash: the send must not stay "sending" for good. */
    public function failed(?Throwable $e = null): void
    {
        $send = NewsletterSend::query()->withoutGlobalScopes()->find($this->sendId);
        if ($send === null || $send->status !== NewsletterSend::STATUS_SENDING) {
            return;
        }
        $send->forceFill(['status' => NewsletterSend::STATUS_FAILED, 'failure' => self::OUTCOME_UNKNOWN])->saveQuietly();
        Newsletter::query()->withoutGlobalScopes()->whereKey($send->newsletter_id)
            ->where('status', Newsletter::STATUS_SENDING)
            ->update(['status' => Newsletter::STATUS_FAILED]);
    }

    public function handle(TenantContext $tenant, EventEmitter $events, BlockRenderer $renderer): void
    {
        // Outside the tenant scope: a queued job is nobody until it enters one.
        $send = NewsletterSend::query()->withoutGlobalScopes()->find($this->sendId);

        if ($send === null) {
            return;
        }

        $organization = Organization::query()->withoutGlobalScopes()->find($send->organization_id);

        if ($organization === null) {
            return;
        }

        $tenant->run($organization, function () use ($send, $events, $renderer, $organization) {
            $claimed = DB::transaction(function () use ($send) {
                $current = NewsletterSend::query()->lockForUpdate()->find($send->id);
                if ($current === null || $current->status !== NewsletterSend::STATUS_SENDING || $current->started_at !== null) {
                    return null;
                }
                $current->forceFill(['started_at' => now()])->save();

                return $current;
            });
            if ($claimed !== null) {
                $this->deliver($claimed, $events, $renderer, $organization);
            }
        });
    }

    private function deliver(
        NewsletterSend $send,
        EventEmitter $events,
        BlockRenderer $renderer,
        Organization $organization,
    ): void {
        $newsletter = $send->newsletter;
        // Stored identity is authoritative; an old job argument cannot substitute an account.
        $connection = $send->connection_id === null ? null : SocialConnection::query()->find($send->connection_id);

        if ($newsletter === null || $connection === null) {
            $this->fail($send, 'The newsletter or the account it was going through is gone.');

            return;
        }

        $sender = NewsletterSenders::for($connection->providerEnum());

        if ($sender === null) {
            $this->fail($send, (string) NewsletterSenders::refusalFor($connection->providerEnum()));

            return;
        }

        $footer = NewsletterIdentity::footer($newsletter) + ['unsubscribe_url' => '*|UNSUB|*'];
        $html = $renderer->document(NewsletterSections::expand((array) ($newsletter->blocks ?? [])), $newsletter->html_override,
            $newsletter->preheader, $footer, $newsletter->brand);

        try {
            $result = $sender->send($connection, $newsletter, $html, $this->people($newsletter));
        } catch (NewsletterSendFailed $e) {
            $this->fail($send, $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->fail($send,
                'The outcome from '.$connection->providerLabel().' is unknown. Check the account before sending again.');

            return;
        }

        $send->markSent($result['remote_id'], $result['recipients']);
        $refused = (int) ($result['refused'] ?? 0);
        if ($refused > 0) {
            $send->forceFill(['notice' => $refused === 1
                ? '1 address was refused by Mailchimp and skipped.'
                : $refused.' addresses were refused by Mailchimp and skipped.'])->save();
        }
        $newsletter->forceFill(['status' => Newsletter::STATUS_SENT])->save();

        $events->emit('newsletter.sent', $newsletter, [
            'subject' => $newsletter->subject,
            'audience' => $newsletter->audience?->name,
            'recipients' => $result['recipients'],
            'provider' => $connection->provider,
        ]);
    }

    /**
     * The addresses EzBizi holds for this audience, minus anybody who left.
     *
     * Empty for a Mailchimp-held audience: those addresses are theirs and we do
     * not have them, which is the point of that kind existing.
     *
     * @return array<int, array{email: string, first_name?: string, last_name?: string}>
     */
    public static function people(Newsletter $newsletter): array
    {
        $audience = $newsletter->audience;

        if ($audience === null || $audience->kind !== NewsletterAudience::KIND_CUSTOMERS) {
            return [];
        }

        $gone = NewsletterSenders::unsubscribedIn($newsletter);

        $tag = trim((string) $audience->tag);

        // The audience's own scope decides whose customers it holds; a sibling
        // company's customers are never on it.
        $query = NewsletterIdentity::customersOf(Customer::query(),
            $audience->scope_type ?? $newsletter->scope_type, $audience->scope_id ?? $newsletter->scope_id);

        return $query
            ->when($tag !== '', fn ($q) => $q->where(fn ($w) => $w
                // The customer's own tag (any case) or a tag on one of their jobs.
                ->whereRaw("JSON_SEARCH(LOWER(customers.tags), 'one', ?) IS NOT NULL", [addcslashes(mb_strtolower($tag), '%_'.chr(92))])
                ->orWhereHas('jobs', fn ($j) => $j->whereJsonContains('tags', $tag))))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('is_active', true)
            ->whereNull('email_opt_out_at')
            ->get()
            ->reject(fn (Customer $c) => in_array(mb_strtolower((string) $c->email), $gone, true))
            ->map(function (Customer $c) {
                // `customers` holds one name; its first word is the FNAME merge field.
                $parts = preg_split('/\s+/', trim((string) $c->name), 2) ?: [];

                return array_filter([
                    'email' => (string) $c->email,
                    'first_name' => (string) ($parts[0] ?? ''),
                    'last_name' => (string) ($parts[1] ?? ''),
                ], fn ($v) => $v !== '');
            })
            ->values()
            ->all();
    }

    /**
     * No event is emitted here, deliberately: this module declares no
     * "failed to send" trigger, and inventing one to fill the symmetry would
     * put a trigger in the builder that fires on a state nobody has decided
     * what to do about. The red row and its sentence are the report.
     */
    private function fail(NewsletterSend $send, string $because): void
    {
        $send->markFailed($because);
        $send->newsletter?->forceFill(['status' => Newsletter::STATUS_FAILED])->save();
    }
}
