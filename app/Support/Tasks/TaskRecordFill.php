<?php

namespace App\Support\Tasks;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkizJob;
use App\Support\Settings\SettingsResolver;
use Illuminate\Validation\ValidationException;

/**
 * The board's own columns from the record a task is about (Jose, 2026-11-19:
 * "area and reason can be filled based on the job information"; "same for the
 * Address field"). Area, Reason, Address, Client name, Phone number: only an
 * EMPTY cell is filled, only with a label the board already has, and
 * Resolution never (people fill it).
 */
final class TaskRecordFill
{
    /**
     * Reason by what the text says, first match wins.
     * ponytail: keyword rules, not a model; add a rule when a label keeps landing on Other.
     */
    private const REASONS = [
        ['/\bvoice ?mail\b/i', 'Missed Voicemail'],
        ['/\bmissed (a |the |my )?call\b|\bno answer\b/i', 'Missed Call'],
        ['/\brefund\b/i', 'Issue Refund'],
        ['/\bre-?do\b|\bredo\b/i', 'Redo Needed'],
        ['/\bcancel/i', 'Cancel'],
        ['/\breschedul|\bmove (the|my|his|her) (visit|appointment)/i', 'Reschedule'],
        ['/\b(eta|on (the|their|his|her) way|running late|arriv(e|al) time|still coming|when .* arrive)\b/i', 'ETA'],
        ['/\b(invoice|receipt)\b/i', 'Invoice Request'],
        ['/\b(paid|payment|pay|balance|deposit)\b/i', 'Waiting Payment'],
        ['/\b(estimate|quote|pric(e|ing)|how much)\b/i', 'Estimate Inquiry'],
        ['/\b(no[- ]show|never (showed|came|arrived)|didn.t (show|come)|rude|damage|mess|soot|upset|unhappy|complain|dissatisf|unprofessional|manager)\b/i', 'Complaint'],
        ['/\b(book|schedul|appointment|inspection|clean|sweep|wants a)\b/i', 'Call To Book'],
    ];

    /** @return array<string, string> column id => value written */
    public function fill(Task $task, ?Lead $lead, ?User $actor, bool $review = false, ?Job $job = null, ?TaskWriter $writer = null): array
    {
        $writer ??= app(TaskWriter::class);
        $board = $task->board;
        $job ??= $lead?->job_id ? Job::query()->withoutGlobalScopes()->find($lead->job_id) : null;
        $job ??= $this->jobFor($task, $lead);
        $customer = $job?->customer_id ? Customer::query()->withoutGlobalScopes()->find($job->customer_id) : null;

        $text = trim(implode(' ', array_filter([$task->title, $lead?->summary, $lead?->category])));
        $address = $customer
            ? trim(implode(', ', array_filter([trim($customer->address_line1.' '.$customer->address_line2), $customer->city, trim($customer->state.' '.$customer->postal_code)])))
            : trim(implode(', ', array_filter([$lead?->address, $lead?->city, trim(($lead?->service_state ?? '').' '.($lead?->postal_code ?? ''))])));

        $wanted = [
            'Area' => $this->area($customer?->state ?? $lead?->service_state, $job?->franchise_id ?? $lead?->franchise_id, $board->franchise_id),
            'Reason' => $review ? ['Bad Review'] : ($this->reasons($text) ?: ['Other']),
            'Address' => $address,
            'Client name' => $customer?->name ?? $lead?->contact_name,
            'Phone number' => $customer?->phone ?? $lead?->phone,
            'EzBizi Job #' => $job?->number,
            // When the call, review or lead came in; the last time we heard from them.
            'Date Received' => ($lead?->received_at ?? $task->created_at)?->toDateString(),
            'Last Contact Date' => $lead?->last_contact_at?->toDateString(),
            'Last Customer Contact' => $lead?->last_contact_at?->toDateString(),
            'Booked by' => $job?->created_by_label,
            // "Date": the job's booked visit (desk for Jose, 11-19); empty without a job.
            // Only on a Customer Relations task: elsewhere "Date" means something else (All Calls: the call's day; 11-19 undo).
            'Date' => str_contains(mb_strtolower((string) $task->group?->name), 'customer relations') ? $job?->scheduled_for?->toDateString() : null,
            'Technician' => $job?->performed_by_user_id,
            'Notes' => $lead?->summary,
            'Workiz Job ID' => $job ? (string) (WorkizJob::query()->where('job_id', $job->id)->value('serial_id') ?? '') : null,
        ];

        $set = [];
        foreach ($board->columns()->get() as $column) {
            $value = $wanted[trim($column->name)] ?? null;
            if ($value === null || $value === [] || (is_string($value) && trim($value) === '') || $task->values()->where('column_id', $column->id)->exists()) {
                continue;
            }
            $options = (array) $column->options();
            if ($options !== []) {
                $match = fn (string $label) => collect($options)->first(fn ($o) => mb_strtolower(trim((string) ($o['label'] ?? ''))) === mb_strtolower($label));
                // A Reason takes the first matching label this board has, else Other.
                $hit = collect((array) $value)->map(fn ($l) => $match((string) $l))->filter()->first()
                    ?? (trim($column->name) === 'Reason' ? $match('Other') : null);
                if ($hit === null) {
                    continue; // the board has no such label: left for a person, never guessed
                }
                $value = $hit['id'];
            }
            $set[$column->id] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        // A person cell can be refused (not on this board): written on its own so the rest still lands.
        $people = $board->columns()->where('type', 'person')->pluck('id')->all();
        $person = array_intersect_key($set, array_flip($people));
        $set = array_diff_key($set, $person);
        if ($set !== []) {
            $writer->update($task->load('board'), ['values' => $set], $actor);
        }
        foreach ($person as $columnId => $userId) {
            try {
                $writer->update($task->load('board'), ['values' => [$columnId => $userId]], $actor);
                $set[$columnId] = $userId;
            } catch (ValidationException) {
            }
        }

        return $set;
    }

    /**
     * The task's job from ANY identifier it carries (Jose, 2026-11-20: "fill
     * from any identifier"), first hit wins: EzBizi Job # / Workiz Job ID
     * cells, the Phone number cell or a number in the title, the client name
     * with the address, then the name alone when exactly one customer has it.
     * The customer's open job, else the newest.
     */
    public function jobFor(Task $task, ?Lead $lead = null): ?Job
    {
        $cell = fn (string $name) => trim((string) $task->values()->whereHas('column', fn ($c) => $c->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)]))->value('value_text'));
        if (($no = $cell('EzBizi Job #')) !== '' && ($job = Job::query()->withoutGlobalScopes()->whereIn('number', [$no, 'JOB-'.preg_replace('/^JOB-/i', '', $no)])->first())) {
            return $job;
        }
        // A Workiz number: the cell, or "Job #300894261 - Katelyn Banks" in the title.
        $serial = $cell('Workiz Job ID') ?: (preg_match('/\bJob\s*#\s*(\d{6,})|^\s*(\d{6,})\b/i', (string) $task->title, $m) === 1 ? ($m[1] ?: $m[2]) : ''); // or "600025660 Albert" (Ohio PM, row 37)
        if ($serial !== '' && ($id = \App\Models\WorkizJob::query()->where('serial_id', $serial)->orWhere('remote_id', $serial)->value('job_id')) && ($job = Job::query()->withoutGlobalScopes()->find($id))) {
            return $job;
        }
        $raw = (string) ($lead?->phone ?: $cell('Phone number'));
        if ($raw === '' && preg_match('/(?:\+?1[\s.-]*)?\(?\d{3}\)?[\s.-]*\d{3}[\s.-]*\d{4}/', (string) $task->title, $m) === 1) {
            $raw = $m[0];
        }
        $ten = substr((string) preg_replace('/\D+/', '', $raw), -10);
        $customers = strlen($ten) === 10 ? Customer::query()->withoutGlobalScopes()->tap(fn ($q) => Customer::phoneLike($q, $ten))->pluck('id') : collect();
        // The caller's number is often a job's second phone, not the customer's
        // own (11-20: 16 of 35 empty complaints). ponytail: a LIKE scan of payloads, only on a miss.
        if ($customers->isEmpty() && strlen($ten) === 10 && ($id = \App\Models\WorkizJob::query()->where('payload', 'like', '%'.$ten.'%')->latest()->value('job_id'))
            && ($job = Job::query()->withoutGlobalScopes()->find($id))) {
            $customers = collect([$job->customer_id])->filter();
        }
        $name = trim((string) preg_replace('/\s+/', ' ', $cell('Client name') ?: ($lead?->contact_name ?: preg_replace('/^Job\s*#\s*\d+\s*-\s*/i', '', (string) $task->title))));
        if ($customers->isEmpty() && str_contains($name, ' ') && mb_strlen($name) <= 60) {
            $named = Customer::query()->withoutGlobalScopes()->where('name', $name);
            $street = trim(strtok($cell('Address'), ',') ?: '');
            $customers = $street !== '' ? (clone $named)->where('address_line1', 'like', mb_substr($street, 0, 12).'%')->pluck('id') : collect();
            // ponytail: the name alone only when one customer has it; two "John Smith"s stay empty.
            if ($customers->isEmpty() && ($all = $named->limit(2)->pluck('id'))->count() === 1) {
                $customers = $all;
            }
        }
        if ($customers->isEmpty()) {
            return null;
        }
        $jobs = Job::query()->withoutGlobalScopes()->whereIn('customer_id', $customers)->latest()->get();

        return $jobs->first(fn (Job $j) => ! in_array(mb_strtolower((string) $j->status), ['completed', 'cancelled', 'canceled', 'closed', 'done'], true)) ?? $jobs->first();
    }

    /** @return list<string> every label the text matches, in rule order */
    public function reasons(string $text): array
    {
        return array_values(array_map(fn ($r) => $r[1], array_filter(self::REASONS, fn ($r) => preg_match($r[0], $text) === 1)));
    }

    /** The market the customer is in (their state through leads.line_markets), else the job's or lead's branch, else the board's. */
    private function area(?string $state, ?string $franchiseId, ?string $boardFranchiseId): ?string
    {
        $markets = array_change_key_case((array) SettingsResolver::forOrganization('leads.line_markets'), CASE_UPPER);
        $code = strtoupper(trim((string) $state));
        if ($code !== '' && isset($markets[$code])) {
            return $markets[$code];
        }

        return Franchise::query()->whereKey($franchiseId ?? $boardFranchiseId)->value('name');
    }
}
