<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Services\Leads\LeadInsights;
use App\Support\Ai\NameDecision;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Grok reads new leads off the sync's back (Grok takes ~8 s a call): the
 * pile and one line, and the name and ZIP a call or message gave that the
 * source did not (Jose, 2026-10-09: "fill in the gaps, like in the LS Calls").
 * A call is read once its transcript exists. Never overwrites what we hold.
 */
class LeadsRead extends Command
{
    protected $signature = 'ezbizi:leads-read {--limit=12} {--lead=* : read these again, at any age (Re-classify on the lead)}';

    protected $description = 'Sort new leads and fill a missing name or ZIP from what the customer said';

    public function handle(LeadInsights $insights, TenantContext $tenant, EventEmitter $events): int
    {
        if (! $insights->enabled()) {
            return self::SUCCESS;   // nothing configured, nothing to do
        }

        $leads = Lead::query()->withoutGlobalScopes()->whereNull('hidden_at')   // AI never reads a hidden lead
            ->when($this->option('lead') !== [],
                fn ($q) => $q->whereIn('id', $this->option('lead')),
                // Unread, or the customer wrote again since the last read (the
                // reply reader, Jose 2026-11-13: Rose Wyka's reply was never read).
                fn ($q) => $q->where(fn ($w) => $w
                    ->where(fn ($n) => $n->whereNull('classification')
                        ->where('received_at', '>=', max(now()->subDays(3), \App\Support\Ai\GrokCli::since())))
                    ->orWhereExists(fn ($e) => $e->from('lead_conversations')
                        ->whereColumn('lead_conversations.lead_id', 'leads.id')
                        ->where('participant', LeadConversation::FROM_CONSUMER)
                        ->where('occurred_at', '>=', now()->subDays(3))
                        ->whereColumn('occurred_at', '>', 'leads.classified_at'))))
            ->where('is_test', false)
            // Something said in words: a message, or a call already transcribed.
            ->whereExists(fn ($q) => $q->from('lead_conversations')
                ->whereColumn('lead_conversations.lead_id', 'leads.id')
                ->where('participant', LeadConversation::FROM_CONSUMER)
                // A call's text is our tag, not its words (RingCentralLeadCalls).
                ->whereRaw("COALESCE(NULLIF(TRIM(CASE WHEN channel = 'PHONE_CALL' THEN NULL ELSE text END), ''), NULLIF(TRIM(transcript), '')) IS NOT NULL"))
            ->orderByDesc('received_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $read = 0;
        foreach ($leads->groupBy('organization_id') as $orgId => $group) {
            $tenant->run(Organization::withoutGlobalScopes()->findOrFail($orgId), function () use ($group, $insights, $events, &$read) {
                // Side by side (GrokCli::askMany); a failed lead stays unread
                // and is asked again next run.
                $answers = $insights->readMany($group->values()->all());

                foreach ($group->values() as $n => $lead) {
                    $answer = $answers[$n];
                    if ($answer instanceof RuntimeException) {
                        $this->warn('Grok: '.$answer->getMessage());

                        continue;
                    }
                    if ($answer === null) {
                        continue;
                    }

                    // Spam, a vendor, a wrong number: tagged Not a lead so it
                    // leaves the queue (Jose, 2026-10-09: "check the forms ...
                    // and tag them appropriately ... this one is clearly not a
                    // lead"). Only a lead nobody has decided on yet.
                    $before = $lead->status;
                    // Its own flag, not ARCHIVE: Grok files a declined quote or a
                    // service we may not offer as ARCHIVE too, and those are people.
                    $applicant = $answer['job_applicant'] && $before === Lead::STATUS_NEW;
                    // Never somebody who is already a customer (Jose, 2026-10-16:
                    // James Blanke, C-06974, was set Not a lead from a 5-second call).
                    $junk = ! $applicant && $answer['not_a_lead'] && $before === Lead::STATUS_NEW
                        && ! \App\Support\Leads\KnownCustomer::is($lead->phone);
                    $lost = ! $applicant && ! $junk && $answer['lost'] && $before === Lead::STATUS_NEW;
                    // Booked on the call (Jose, 2026-10-13, Rodrigo's verdict
                    // with its booking gate - LeadInsights::gate).
                    $booked = ! $applicant && ! $junk && ! $lost && $answer['booked'] && $before === Lead::STATUS_NEW;
                    $decided = $applicant || $junk || $lost || $booked;

                    $answer = self::withForm($lead, $answer);

                    // Rodrigo's §3 merge table (NameDecision): fill a blank,
                    // enrich a first name, correct AI's own weak spelling; a
                    // name a person set, or a shared phone, is only flagged.
                    [$nameAction, $name] = NameDecision::decide(
                        $lead->contact_name,
                        $lead->ai_set['contact_name'] ?? null,
                        $answer['name'],
                        $answer['name_basis'] ?? 'self_introduction',
                        $answer['name'] !== null && self::phoneShared($lead, $answer['name']),
                    );

                    // What AI wrote, so the AI mark shows while the value is
                    // still AI's and the weekly check counts what people changed.
                    $aiSet = array_filter([
                        'status' => $decided ? match (true) {
                            $applicant => Lead::STATUS_JOB_APPLICANT,
                            $junk => Lead::STATUS_NOT_A_LEAD,
                            $booked => Lead::STATUS_BOOKED,
                            default => Lead::STATUS_LOST,
                        } : null,
                        // Why, shown on the AI mark.
                        'reason' => $decided ? $answer['reason'] : null,
                        'contact_name' => in_array($nameAction, ['fill', 'enrich', 'correct'], true) ? $name : null,
                        'name_conflict' => $nameAction === 'conflict' ? $name : null,
                        'postal_code' => $lead->postal_code ? null : $answer['zip'],
                    ], fn ($v) => $v !== null && $v !== '');

                    // The reply reader: a blank is filled, a different value is
                    // only offered ("Use (516) 404-1690?"), never overwritten.
                    $contacts = self::contacts($lead, $answer);
                    $aiSet += $contacts['fill'];
                    $reading = array_filter([
                        'suggest' => $contacts['suggest'] ?: null,
                        'scope' => $answer['scope'] ?? null,
                        'deadline' => $answer['deadline'] ?? null,
                        'availability' => $answer['availability'] ?? null,
                    ]);
                    if ($reading !== [] || $contacts['fill'] !== []) {
                        // What was read and from which message, for the lead's activity.
                        $reading['source'] = mb_substr((string) LeadConversation::query()->where('lead_id', $lead->getKey())
                            ->where('participant', LeadConversation::FROM_CONSUMER)->latest('occurred_at')->value('text'), 0, 500);
                        $aiSet['reading'] = $reading + ['filled' => array_keys($contacts['fill'])];
                    }
                    $summary = $answer['summary'] !== '' ? $answer['summary'] : $lead->summary;
                    $when = implode('; ', array_filter([$answer['availability'] ?? null, $answer['deadline'] ?? null]));
                    if ($answer['summary'] !== '' && $when !== '') {
                        $summary = mb_substr($summary.' · '.$when, 0, 255);
                    }

                    $lead->forceFill([
                        // Kept across reads: a later reply adds to what the first one set.
                        'ai_set' => $aiSet !== [] ? ['at' => now()->toIso8601String()] + $aiSet + ($lead->ai_set ?? []) : $lead->ai_set,
                        ...$contacts['fill'],
                        'phone_digits' => isset($contacts['fill']['phone']) ? Lead::digits($contacts['fill']['phone']) : $lead->phone_digits,
                        'status' => match (true) {
                            $applicant => Lead::STATUS_JOB_APPLICANT,
                            $junk => Lead::STATUS_NOT_A_LEAD,
                            $lost => Lead::STATUS_LOST,
                            $booked => Lead::STATUS_BOOKED,
                            default => $before,
                        },
                        // Google Local Services only: Google refunds its own leads.
                        'credit_reason' => $lead->source === Lead::SOURCE_LOCAL_SERVICES ? $answer['google_credit'] : null,
                        'classification' => $answer['classification'],
                        'summary' => $summary,
                        'classified_at' => now(),
                        'contact_name' => in_array($nameAction, ['fill', 'enrich', 'correct'], true) ? $name : $lead->contact_name,
                        'postal_code' => $lead->postal_code ?: $answer['zip'],
                    ])->save();

                    if ($decided) {
                        $events->emit('lead.status_changed', $lead, [
                            'before' => ['status' => $before],
                            'after' => ['status' => $lead->status],
                        ]);
                    }

                    $events->emit('lead.classified', $lead, ['after' => [
                        'classification' => $lead->classification,
                        'summary' => $lead->summary,
                        'contact_name' => $lead->contact_name,
                        'phone' => $lead->phone,
                    ]]);
                    $read++;
                }
            });
        }

        $this->info("Read {$read} lead(s).");

        return self::SUCCESS;
    }

    /**
     * Our own booking widget's message ("Name: / Phone: / Address: / ZIP:",
     * WebsiteEnquiries) is read by its labels, not by a model, and wins over
     * the model (Jose, 2026-11-13: Charlotte Owens' booking left the lead
     * unnamed, and her free-text "available tomorrow" landed in the address).
     */
    public static function withForm(Lead $lead, array $answer): array
    {
        $texts = LeadConversation::query()->where('lead_id', $lead->getKey())
            ->where('participant', LeadConversation::FROM_CONSUMER)->latest('occurred_at')->pluck('text');
        $form = [];
        foreach ($texts as $text) {
            if (preg_match_all('/^(Name|Phone|Address|ZIP):[ \t]*(\S.*?)\s*$/mi', (string) $text, $m, PREG_SET_ORDER) >= 3) {
                foreach ($m as [, $label, $value]) {
                    $form[strtolower($label)] = $value;
                }
                break;
            }
        }
        if (isset($form['name'])) {
            [$answer['name'], $answer['name_basis']] = [$form['name'], 'spelled_or_corrected'];
        }
        if (isset($form['phone'])) {
            $answer['phones'] = [substr((string) preg_replace('/^1(?=\d{10}$)/', '', (string) Lead::digits($form['phone'])), 0, 10), ...($answer['phones'] ?? [])];
        }
        if (isset($form['address'])) {
            $answer['address'] = $form['address'];
        }
        if (isset($form['zip']) && preg_match('/^\d{5}$/', $form['zip'])) {
            $answer['zip'] = $form['zip'];
        }

        return $answer;
    }

    /**
     * Phone, email and address the customer gave: blanks to fill, and what
     * differs from what the lead holds, to offer.
     *
     * @return array{fill: array<string, string>, suggest: array<string, string>}
     */
    public static function contacts(Lead $lead, array $answer): array
    {
        $fill = [];
        $suggest = [];

        $phone = collect($answer['phones'] ?? [])->first(fn ($d) => $d !== substr((string) Lead::digits($lead->phone), -10));
        if ($phone !== null) {
            $shown = sprintf('(%s) %s-%s', substr($phone, 0, 3), substr($phone, 3, 3), substr($phone, 6));
            blank($lead->phone) ? $fill['phone'] = '+1'.$phone : $suggest['phone'] = $shown;
        }
        $email = collect($answer['emails'] ?? [])->first(fn ($e) => $e !== mb_strtolower((string) $lead->email));
        if ($email !== null) {
            blank($lead->email) ? $fill['email'] = $email : $suggest['email'] = $email;
        }
        $address = $answer['address'] ?? null;
        // A held address the reading already contains is the same place; anything else is offered.
        $held = mb_strtolower(trim((string) $lead->address));
        if ($address !== null && ($held === '' || ! str_contains(mb_strtolower($address), $held))) {
            blank($lead->address) ? $fill['address'] = $address : $suggest['address'] = $address;
        }

        return ['fill' => $fill, 'suggest' => $suggest];
    }

    /** Another lead on this phone goes by a different name: one number, maybe two people. */
    private static function phoneShared(Lead $lead, string $name): bool
    {
        if (blank($lead->phone_digits)) {
            return false;
        }

        return Lead::query()->withoutGlobalScopes()->whereNull('hidden_at')
            ->where('organization_id', $lead->organization_id)
            ->where('phone_digits', $lead->phone_digits)
            ->whereKeyNot($lead->getKey())
            ->whereNotNull('contact_name')
            ->whereRaw('LOWER(contact_name) <> ?', [mb_strtolower($name)])
            ->exists();
    }
}
