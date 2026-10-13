<?php

namespace App\Support\Social;

use App\Jobs\SendWhatsAppPeriodReports;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppReportBatch;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\PeriodReport;
use Illuminate\Support\Carbon;

/**
 * Weekly and monthly PDFs, approved on WhatsApp first (docs/REPLACING-RODRIGO-BOT.md,
 * row C - Rodrigo's bot waits for Merav, the bookkeeper, to say "Approved").
 *
 * The members of the `approval` group get the consolidated PDF and "Reply
 * APPROVE"; their APPROVE sends every branch its PDF and the consolidated one
 * to `financial`. REFRESH rebuilds the preview - the P&L comes from Airtable,
 * which the bookkeeper fills in during the week.
 */
final class WhatsAppPeriodReports
{
    public const APPROVAL = 'approval';

    public function __construct(private readonly WhatsAppGroups $groups) {}

    /** The batch for the period that just ended, made once. */
    public function batchFor(string $period, ?Carbon $today = null): WhatsAppReportBatch
    {
        $today = ($today ?? Carbon::now('America/New_York'))->copy()->startOfDay();

        [$from, $to] = $period === 'month'
            ? [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()]
            : [$today->copy()->startOfWeek()->subWeek(), $today->copy()->startOfWeek()->subDay()];

        return WhatsAppReportBatch::firstOrCreate(
            ['period' => $period, 'starts_on' => $from->toDateString()],
            ['ends_on' => $to->toDateString()],
        );
    }

    /** Send the approver the consolidated PDF and the question. Inside the tenant. */
    public function preview(WhatsAppReportBatch $batch, PeriodReport $report): int
    {
        if ($batch->approved_at !== null) {
            return 0;
        }

        $label = $this->label($batch);

        $reached = $this->groups->deliverDocument(self::APPROVAL, $label.' (for approval)',
            $report->consolidated(Organization::findOrFail($batch->organization_id), $batch->starts_on->toDateString(), $batch->ends_on->toDateString()),
            $this->filename($batch, 'All companies'));

        $this->groups->deliver(self::APPROVAL, $label,
            "\u{1F4CA} *".ucfirst($label)." - ready for approval*\n\n"
            ."\u{2705} Reply *APPROVE* to send them to every group\n"
            ."\u{1F504} Reply *REFRESH* for a new copy once the Airtable P&L is in");

        return $reached;
    }

    /** Approved: each branch its PDF, and the consolidated one to `financial`. Inside the tenant. */
    public function send(WhatsAppReportBatch $batch, PeriodReport $report): int
    {
        [$from, $to] = [$batch->starts_on->toDateString(), $batch->ends_on->toDateString()];
        $label = $this->label($batch);
        $sent = 0;

        foreach (WhatsAppGroup::query()->where('purpose', 'like', 'daily:%')->get() as $group) {
            $branch = Franchise::query()->find(substr($group->purpose, strlen('daily:')));

            if ($branch !== null) {
                $sent += $this->groups->deliverDocument($group->purpose, BranchName::of($branch).' '.$label,
                    $report->forFranchise($branch, $from, $to), $this->filename($batch, BranchName::of($branch)));
            }
        }

        $sent += $this->groups->deliverDocument('financial', 'All companies '.$label,
            $report->consolidated(Organization::findOrFail($batch->organization_id), $from, $to),
            $this->filename($batch, 'All companies'));

        return $sent;
    }

    /**
     * An approver's APPROVE or REFRESH. True when it was one, from somebody in
     * the approval group, so nothing else answers it. Inside the tenant.
     */
    public function reply(WhatsAppNumber $number, string $from, ?string $text): bool
    {
        $word = strtoupper(trim((string) $text, " \t\n\r.!"));

        if (! in_array($word, ['APPROVE', 'APPROVED', 'REFRESH'], true)) {
            return false;
        }

        $approver = $this->approver($from);
        $batch = WhatsAppReportBatch::query()->whereNull('approved_at')->latest('starts_on')->first();

        if ($approver === null || $batch === null) {
            return false;
        }

        if ($word === 'REFRESH') {
            SendWhatsAppPeriodReports::dispatch($batch->getKey(), null);
        } else {
            $batch->forceFill(['approved_at' => now(), 'approved_by' => $approver->getKey()])->save();
            SendWhatsAppPeriodReports::dispatch($batch->getKey(), $approver->getKey());
        }

        $this->groups->deliver(self::APPROVAL, $this->label($batch), $word === 'REFRESH'
            ? "\u{1F504} *Building a new copy now.*"
            : "\u{2705} *Approved!*\n\n\u{1F4E4} Sending the ".$this->label($batch).' to every group now.');

        return true;
    }

    private function approver(string $from): ?User
    {
        $group = WhatsAppGroup::query()->where('purpose', self::APPROVAL)->first();

        return $group?->members()->where('is_active', true)->get()->first(
            fn (User $u) => ltrim((string) ThumbtackAssociatePhoneNumbers::e164((string) $u->phone), '+') === $from,
        );
    }

    private function label(WhatsAppReportBatch $batch): string
    {
        return $batch->period === 'month'
            ? $batch->starts_on->format('F Y').' monthly reports'
            : 'weekly reports for '.$batch->starts_on->format('M j').' - '.$batch->ends_on->format('M j');
    }

    private function filename(WhatsAppReportBatch $batch, string $who): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9 _-]+/', '', $who).' '.ucfirst($batch->period).' '.$batch->starts_on->toDateString(), 0, 100).'.pdf';
    }
}
