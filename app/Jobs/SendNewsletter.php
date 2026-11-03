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

    public function __construct(
        public readonly string $sendId,
        public readonly string $connectionId,
    ) {}

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

        $html = is_string($newsletter->html_override) && $newsletter->html_override !== ''
            ? $newsletter->html_override
            : $renderer->render((array) ($newsletter->blocks ?? []), [
                'company' => (string) $organization->name,
                'address' => (string) ($organization->address ?? ''),
                'unsubscribe_url' => '*|UNSUB|*',
            ]);

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
    private function people(Newsletter $newsletter): array
    {
        $audience = $newsletter->audience;

        if ($audience === null || $audience->kind !== NewsletterAudience::KIND_CUSTOMERS) {
            return [];
        }

        $gone = NewsletterSenders::unsubscribedIn($newsletter);

        $tag = trim((string) $audience->tag);

        return Customer::query()
            ->when($tag !== '', fn ($q) => $q->whereHas('jobs', fn ($j) => $j->whereJsonContains('tags', $tag)))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get()
            ->reject(fn (Customer $c) => in_array(mb_strtolower((string) $c->email), $gone, true))
            ->map(fn (Customer $c) => array_filter([
                'email' => (string) $c->email,
                'first_name' => (string) ($c->first_name ?? ''),
                'last_name' => (string) ($c->last_name ?? ''),
            ], fn ($v) => $v !== ''))
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
