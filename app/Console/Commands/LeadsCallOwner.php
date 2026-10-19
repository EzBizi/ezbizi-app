<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Support\Ai\GrokCli;
use App\Support\Audit\AuditLogger;
use App\Support\Leads\LeadThread;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A Google call filed under somebody else's message enquiry, moved to a lead
 * of its own (Jose, 2026-10-16: "the chats came from someone, but the call
 * that shows below is from an entirely different client").
 *
 * Google itself attributes calls to a message lead (measured: call 3405794171
 * under lead 344772988), and a message lead carries no phone, so nothing but
 * the words can tell. Grok compares the call's transcript with the messages;
 * a clear "different person" moves the call to a new Local Services call lead
 * on the same account, with a note saying where it came from, audited. A later
 * sync does not move it back: LeadThread matches the row by Google's id and
 * leaves its lead alone. Each call is asked once.
 */
class LeadsCallOwner extends Command
{
    protected $signature = 'ezbizi:leads-call-owner {--days=7 : calls this recent} {--dry : say, do not move}';

    protected $description = 'Move a Google call filed under another person\'s message lead to a lead of its own';

    public function handle(TenantContext $tenant, AuditLogger $audit, LeadThread $thread): int
    {
        if (! GrokCli::configured()) {
            return self::SUCCESS;
        }

        $rows = LeadConversation::query()->withoutGlobalScopes()
            ->join('leads', 'leads.id', '=', 'lead_conversations.lead_id')
            ->where('leads.source', Lead::SOURCE_LOCAL_SERVICES)->where('leads.lead_type', 'MESSAGE')->whereNull('leads.phone_digits')
            ->where('lead_conversations.channel', LeadConversation::CHANNEL_PHONE_CALL)
            ->where('lead_conversations.participant', LeadConversation::FROM_CONSUMER)
            ->whereNotNull('lead_conversations.transcript')
            ->where('lead_conversations.occurred_at', '>=', now()->subDays((int) $this->option('days')))
            ->get(['lead_conversations.*'])
            ->reject(fn (LeadConversation $c) => Cache::has('call-owner:'.$c->id));

        foreach ($rows->groupBy('organization_id') as $orgId => $calls) {
            $tenant->run(Organization::withoutGlobalScopes()->findOrFail($orgId), function () use ($calls, $audit, $thread) {
                $prompts = $calls->map(fn (LeadConversation $c) => self::prompt($c))->values()->all();
                $answers = GrokCli::askMany($prompts, self::SCHEMA);

                foreach ($calls->values() as $n => $call) {
                    $a = $answers[$n];
                    if ($a instanceof RuntimeException) {
                        continue;
                    }
                    // A dry run remembers nothing, or the real run after it would skip every call.
                    if (! $this->option('dry')) {
                        Cache::put('call-owner:'.$call->id, $a['same'], now()->addDays(60));
                    }
                    $this->line("{$call->lead_id} {$call->id}: {$a['same']} - {$a['reason']}");
                    if ($a['same'] !== 'different' || $this->option('dry')) {
                        continue;
                    }
                    $this->move($call, (string) $a['reason'], $audit, $thread);
                }
            });
        }

        return self::SUCCESS;
    }

    private function move(LeadConversation $call, string $reason, AuditLogger $audit, LeadThread $thread): void
    {
        DB::transaction(function () use ($call, $reason, $audit, $thread) {
            $from = Lead::query()->findOrFail($call->lead_id);
            $to = Lead::create([
                'organization_id' => $from->organization_id, 'company_id' => $from->company_id, 'franchise_id' => $from->franchise_id,
                'connection_id' => $from->connection_id, 'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => 'call_'.$call->remote_id, 'lead_type' => 'PHONE_CALL', 'category' => $from->category,
                'status' => Lead::STATUS_NEW, 'received_at' => $call->occurred_at,
                'note' => 'Google filed this call under another customer\'s message enquiry; EzBizi moved it here because '
                    .'it is a different person ('.mb_substr($reason, 0, 200).').',
                'note_updated_at' => now(),
            ]);
            $call->forceFill(['lead_id' => $to->getKey()])->save();
            $thread->refresh($from);
            $thread->refresh($to);
            $audit->log('lead.call_moved', $to, ['lead_id' => ['from' => $from->getKey(), 'to' => $to->getKey()]],
                ['conversation_id' => $call->id, 'google_conversation_id' => $call->remote_id, 'reason' => $reason]);
        });
    }

    private const SCHEMA = ['type' => 'object', 'properties' => [
        'same' => ['type' => 'string', 'enum' => ['same', 'different', 'unclear']],
        'reason' => ['type' => 'string'],
    ], 'required' => ['same', 'reason']];

    private static function prompt(LeadConversation $call): string
    {
        $messages = LeadConversation::query()->where('lead_id', $call->lead_id)
            ->where('channel', '!=', LeadConversation::CHANNEL_PHONE_CALL)->where('participant', LeadConversation::FROM_CONSUMER)
            ->whereNotNull('text')->orderBy('occurred_at')->limit(30)->pluck('text')->implode("\n");

        return 'A home-services company. Below are the text messages of one customer enquiry, and a phone call that '
            .'Google attached to the same enquiry. Is the CALLER the same person (or their household, about the same '
            .'job) as the one who wrote the messages? Compare names, addresses, the job asked for. Answer "different" '
            .'only when the call is clearly another customer; "unclear" when it cannot be told. One short reason.'
            ."\n\nMessages:\n".mb_substr($messages, 0, 4000)."\n\nCall transcript:\n".mb_substr((string) $call->transcript, 0, 6000);
    }
}
