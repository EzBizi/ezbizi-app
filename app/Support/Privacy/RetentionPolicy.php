<?php

namespace App\Support\Privacy;

use InvalidArgumentException;

/**
 * How long one table's rows stay, and what makes that true (§9, §10).
 *
 * ── Why this is an object and not a sentence ─────────────────────
 *
 * `personalDataRetention()` used to return prose, and the prose was honest:
 * "kept for as long as the record it belongs to", "there is no automatic expiry
 * today". What it could not be was ENFORCED. A number inside an English sentence
 * is a number nothing can read, so every one of those policies was a description
 * of an intention rather than a description of the system — and the two drift
 * apart silently, because nothing fails when they do.
 *
 * So the policy is stated once, as this, and read twice: `sentence()` is what the
 * data inventory publishes to a person asking, and `months`/`column` are what
 * `ezbizi:expire-data` acts on. One home for the fact (R6), and the sweep and the
 * published promise cannot disagree because they are the same declaration.
 *
 * ── The four honest answers ──────────────────────────────────────
 *
 * Forcing every table into "N days" was the temptation, and it misrepresents
 * most of them. A contract signature does not expire. An onboarding answer goes
 * when its onboarding goes and not a day before. An audit event has four
 * different windows depending on who acted. Each of those is a different KIND of
 * answer, not a different number, so there are four constructors and each names
 * what enforces it:
 *
 *   window()      a sweep deletes rows past it — the only kind that expires
 *   withParent()  the foreign key cascade removes it with the record it hangs off
 *   elsewhere()   a named mechanism already does it, and the name is stated
 *   forever()     deliberately kept, and the reason is stated
 *
 * `forever()` is not a failure to decide. Some of this is evidence and the
 * product would be broken without it — but it has to be a decision somebody made
 * and wrote down, which is why the reason is required rather than defaulted.
 */
final class RetentionPolicy
{
    /** A sweep removes rows past the window. */
    public const WINDOW = 'window';

    /** Removed with the record it belongs to, by the cascade. */
    public const WITH_PARENT = 'with_parent';

    /** A named mechanism other than the generic sweep enforces it. */
    public const ELSEWHERE = 'elsewhere';

    /** Kept indefinitely, on purpose. */
    public const FOREVER = 'forever';

    private function __construct(
        public readonly string $kind,
        /** Written for the person asking what you hold about them, not for a colleague. */
        public readonly string $because,
        public readonly ?int $months = null,
        /** The timestamp the window is measured from. */
        public readonly ?string $column = null,
        /** What enforces it, when that is not the generic sweep. */
        public readonly ?string $enforcedBy = null,
    ) {}

    /**
     * A window `ezbizi:expire-data` enforces.
     *
     * The column is named rather than assumed `created_at`, because an
     * append-only table generally does not have one — it switches `CREATED_AT`
     * off and times its rows by the thing that actually happened. `automation_events`
     * is the case in the tree: it is governed by this and measured from
     * `occurred_at`, and a sweep that assumed the conventional name would have
     * silently matched nothing in exactly the table that grows fastest, which is
     * indistinguishable from a table that was already clean.
     *
     * The 54 tables this governs today are `a1leads_review_events`
     * (created_at), `a1leads_review_reply_receipts`
     * (created_at), `a1leads_reviews` (updated_at), `ai_eval_cases` (created_at), `ai_traces` (created_at),
     * `airtable_record_versions` (seen_at), `airtable_records`
     * (fetched_at), `alerts` (created_at), `assistant_answers` (created_at), `assistant_jobs` (updated_at), `automation_events` (occurred_at), `call_log_days` (created_at),
     * `call_report_conversions` (created_at), `call_report_labels` (created_at), `call_report_overrides` (created_at),
     * `call_report_person_reviews` (created_at),
     * `call_report_scores` (created_at),
     * `call_report_transcripts` (created_at), `call_type_corrections` (created_at), `client_issues` (created_at),
     * `device_tokens` (last_registered_at),
     * `email_verifications` (created_at), `field_alerts` (created_at),
     * `field_geocodes` (updated_at), `field_locations` (recorded_at),
     * `field_presences` (last_seen_at), `financial_adjustments` (date), `financial_bills` (period_to), `financial_charge_sheets` (period_to), `financial_parity_checks` (day), `financial_repair_weeks` (week_start), `financial_results` (date), `invitations` (created_at), `invoices` (issued_on),
     * `monday_outbox` (created_at), `monitoring_activity` (occurred_at),
     * `notifications` (created_at), `password_resets` (created_at),
     * `phone_callbacks` (created_at), `phone_calls` (created_at), `phone_device_registrations` (last_seen_at),
     * `phone_messages` (created_at), `promotion_texts` (created_at), `promotions` (created_at), `qr_code_scans` (scanned_at), `report_batch_files` (created_at),
     * `scheduling_helper_bookings` (date), `scheduling_reschedule_offers` (created_at),
     * `staff_messages` (created_at),
     * `whatsapp_contacts` (updated_at), `whatsapp_pending_reports` (created_at),
     * `whatsapp_report_batches` (created_at), `whatsapp_messages` (created_at) and
     * `workiz_calls` (called_at). It said
     * four before Phone added call and SMS rows with the same three-month
     * window as business audit (spec §11), and seven before colleagues could
     * message each other — that one is two years rather than three months,
     * because a working conversation is not an audit trail and keeping it
     * forever would quietly make it one.
     *
     * The ninth is the shortest window in the list, and deliberately: a device
     * registration ties a named person to a particular handset, and it stops
     * being useful ninety minutes after that handset last asked for a token.
     * Anything kept past that is a record of which devices somebody owns,
     * held for no working reason.
     *
     * Others time their rows by something other than creation — `audit_events`
     * by `occurred_at`, `automation_runs` by `started_at` — but neither is swept
     * from here: the first states `elsewhere` and the second `withParent`, so the
     * constructor never sees them.
     *
     * RetentionTest::test_this_constructor_names_every_table_it_governs is what
     * keeps the list above true, rather than the next reader's diligence. It cuts
     * that ONE sentence out of this docblock and reads nothing else, and it wants
     * each table in the shape the sentence already uses — the name, then the
     * column in brackets. Keep that shape when the list changes: until the test
     * was narrowed to it, it searched the whole docblock, and because the prose
     * here names tables too, two of the four could have been dropped from the
     * list without anything going red.
     */
    public static function window(int $months, string $column, string $because): self
    {
        if ($months < 1) {
            throw new InvalidArgumentException(
                'A retention window of less than a month is not a policy this sweep can '
                .'honour: it runs nightly, so anything shorter than that would be enforced '
                .'to an accuracy the number does not claim.'
            );
        }

        return new self(self::WINDOW, $because, months: $months, column: $column);
    }

    /**
     * Goes when the record it hangs off goes.
     *
     * Enforced by the foreign key, which is why this is not "no policy": the
     * database removes the row, and it does so whether or not anybody remembers
     * to run anything. What it does NOT do is put an upper bound on the row's
     * age, and `sentence()` says so rather than implying a limit.
     */
    public static function withParent(string $because): self
    {
        return new self(self::WITH_PARENT, $because);
    }

    /** Enforced by something already built, named so a reader can go and check it. */
    public static function elsewhere(string $by, string $because): self
    {
        return new self(self::ELSEWHERE, $because, enforcedBy: $by);
    }

    /** Kept indefinitely, deliberately, and the reason is published. */
    public static function forever(string $because): self
    {
        return new self(self::FOREVER, $because);
    }

    /** True where `ezbizi:expire-data` is the thing that has to act. */
    public function isSwept(): bool
    {
        return $this->kind === self::WINDOW;
    }

    /**
     * What the inventory publishes.
     *
     * The mechanical half is composed from the declaration, so the number cannot
     * be typed twice and cannot be revised in one place only. The human half is
     * the sentence whoever decided the policy wrote, and it is appended rather
     * than replaced — "3 months" answers how long, and it never answers why.
     */
    public function sentence(): string
    {
        $lead = match ($this->kind) {
            self::WINDOW => sprintf(
                'Removed %d months after %s, by the nightly ezbizi:expire-data sweep.',
                $this->months, $this->column,
            ),
            self::WITH_PARENT => 'Kept for as long as the record it belongs to, and removed '
                .'with it. There is no separate expiry.',
            self::ELSEWHERE => sprintf('Removed by %s.', $this->enforcedBy),
            self::FOREVER => 'Kept indefinitely.',
        };

        return $lead.' '.$this->because;
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'months' => $this->months,
            'measured_from' => $this->column,
            'enforced_by' => $this->enforcedBy,
            'sentence' => $this->sentence(),
        ];
    }
}
