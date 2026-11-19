<?php

namespace App\Support\Tasks;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Support\Settings\SettingsResolver;

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
        ['/\b(no[- ]show|never (showed|came)|rude|damage|mess|soot|upset|unhappy|complain|dissatisf|unprofessional|manager)\b/i', 'Complaint'],
        ['/\b(book|schedul|appointment|inspection|clean|sweep|wants a)\b/i', 'Call To Book'],
    ];

    /** @return array<string, string> column id => value written */
    public function fill(Task $task, ?Lead $lead, User $actor, bool $review = false, ?Job $job = null): array
    {
        $board = $task->board;
        $job ??= $lead?->job_id ? Job::query()->withoutGlobalScopes()->find($lead->job_id) : null;
        $customer = $job?->customer_id ? Customer::query()->withoutGlobalScopes()->find($job->customer_id) : null;

        $text = trim(implode(' ', array_filter([$task->title, $lead?->summary, $lead?->category])));
        $address = $customer
            ? trim(implode(', ', array_filter([trim($customer->address_line1.' '.$customer->address_line2), $customer->city, trim($customer->state.' '.$customer->postal_code)])))
            : trim(implode(', ', array_filter([$lead?->address, $lead?->city, trim(($lead?->service_state ?? '').' '.($lead?->postal_code ?? ''))])));

        $wanted = [
            'Area' => $this->area($customer?->state ?? $lead?->service_state, $job?->franchise_id ?? $lead?->franchise_id, $board->franchise_id),
            'Reason' => $review ? ['Bad Review'] : $this->reasons($text),
            'Address' => $address,
            'Client name' => $customer?->name ?? $lead?->contact_name,
            'Phone number' => $customer?->phone ?? $lead?->phone,
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
        if ($set !== []) {
            app(TaskWriter::class)->update($task->load('board'), ['values' => $set], $actor);
        }

        return $set;
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
