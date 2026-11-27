<?php

namespace App\Support\CustomerExperience;

use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Review;

/**
 * Reviews & Complaints triage (Shoval, 2026-11-20). Is the person one of our clients? Is it Priority
 * (BBB, Attorney General, a lawsuit, or words that say so) and so due within 24 hours?
 *
 * Client means a customer record matches: the review already linked to one (by a person, by a review
 * link, or on import), a lead tied to a customer or whose phone is a customer's, or a customer of the
 * same company whose name is the reviewer's name. Anything else is "not a client": the case Google's
 * "report a review" exists for.
 */
final class ComplaintTriage
{
    public const DUE_HOURS = 24;

    private const PRIORITY_WORDS = '/\b(attorney general|lawsuit|lawyer|attorney|sue|suing|small claims|better business bureau|bbb)\b/i';

    public static function apply(Complaint $c): Complaint
    {
        $source = match ($c->source_type) {
            'review' => Review::query()->find($c->source_id),
            'lead' => Lead::query()->find($c->source_id),
            default => null,
        };
        $c->client_status ??= match (true) {
            $source instanceof Review => self::reviewIsClient($source) ? 'client' : 'not_client',
            $source instanceof Lead => self::leadIsClient($source) ? 'client' : 'not_client',
            default => $c->client_status,
        };

        $words = trim(implode(' ', array_filter([$c->title, $c->summary,
            $source instanceof Review ? $source->comment : null, $source instanceof Lead ? $source->summary : null])));
        if (in_array($c->kind, Complaint::PRIORITY_KINDS, true) || preg_match(self::PRIORITY_WORDS, $words)) {
            $c->priority = true;
            $c->due_at ??= ($c->raised_at ?? now())->copy()->addHours(self::DUE_HOURS);
        }

        return $c;
    }

    private static function reviewIsClient(Review $r): bool
    {
        if ($r->customer_id !== null) {
            return true;
        }
        $name = trim((string) $r->reviewer_name);

        return mb_strlen($name) > 3 && str_contains($name, ' ')
            && Customer::query()->when($r->company_id, fn ($q, $co) => $q->whereHas('owningFranchise', fn ($f) => $f->where('company_id', $co)))
                ->where('name', $name)->exists();
    }

    private static function leadIsClient(Lead $l): bool
    {
        if (($l->customer_id ?? null) !== null) {
            return true;
        }
        $digits = substr(preg_replace('/\D/', '', (string) $l->phone), -10);

        return strlen($digits) === 10 && Customer::query()->where('phone', 'like', '%'.$digits)->exists();
    }
}
