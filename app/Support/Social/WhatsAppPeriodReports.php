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
 * The members of the `approval` group (set in Settings, WhatsApp groups)
 * get the consolidated PDF and the choices. APPROVE sends every branch its PDF
 * and the consolidated one to `financial`, once. REFRESH rebuilds the preview
 * (the P&L comes from Airtable, filled in during the week). HOLD keeps it from
 * going until they approve or refresh. SEPARATELY sends them, privately, each
 * company's PDF and the consolidated one, and nothing to the groups.
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
        // Approved, or held by the approver until they say otherwise.
        if ($batch->approved_at !== null || $batch->held_at !== null) {
            return 0;
        }

        $label = $this->label($batch);

        $reached = $this->groups->deliverDocument(self::APPROVAL, $label.' (for approval)',
            $report->consolidated(Organization::findOrFail($batch->organization_id), $batch->starts_on->toDateString(), $batch->ends_on->toDateString()),
            $this->filename($batch, 'All companies'));

        $this->groups->deliver(self::APPROVAL, $label,
            "\u{1F4CA} *".ucfirst($label)." - ready for approval*\n\n"
            ."\u{2705} Reply *APPROVE* to send them to every group\n"
            ."\u{1F4E5} Reply *SEPARATELY* to get each company's PDF here, privately\n"
            ."\u{23F8} Reply *HOLD* to keep them back for now\n"
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
     * Each company's PDF and the consolidated one to one approver, privately
     * (SEPARATELY). Inside the tenant.
     */
    public function sendPrivately(WhatsAppReportBatch $batch, PeriodReport $report, User $to): int
    {
        [$from, $until] = [$batch->starts_on->toDateString(), $batch->ends_on->toDateString()];
        $label = $this->label($batch);
        $sent = 0;

        foreach (WhatsAppGroup::query()->where('purpose', 'like', 'daily:%')->get() as $group) {
            $branch = Franchise::query()->find(substr($group->purpose, strlen('daily:')));

            if ($branch !== null) {
                $sent += $this->groups->deliverDocumentToMember(self::APPROVAL, $to, BranchName::of($branch).' '.$label,
                    $report->forFranchise($branch, $from, $until), $this->filename($batch, BranchName::of($branch))) ? 1 : 0;
            }
        }

        return $sent + ($this->groups->deliverDocumentToMember(self::APPROVAL, $to, 'All companies '.$label,
            $report->consolidated(Organization::findOrFail($batch->organization_id), $from, $until),
            $this->filename($batch, 'All companies')) ? 1 : 0);
    }

    /**
     * An approver's APPROVE, SEPARATELY, HOLD or REFRESH. True when it was
     * one, from somebody in the approval group, so nothing else answers it.
     * Inside the tenant.
     */
    public function reply(WhatsAppNumber $number, string $from, ?string $text): bool
    {
        $word = strtoupper(trim((string) $text, " \t\n\r.!"));
        $word = match ($word) {
            'APPROVED' => 'APPROVE',
            'SEPARATE', 'SEND SEPARATELY', 'SEND EACH SEPARATELY' => 'SEPARATELY',
            default => $word,
        };

        if (! in_array($word, ['APPROVE', 'REFRESH', 'HOLD', 'SEPARATELY'], true)) {
            return false;
        }

        $approver = $this->approver($from);
        $batch = WhatsAppReportBatch::query()->whereNull('approved_at')->latest('starts_on')->first();

        if ($approver === null || $batch === null) {
            return false;
        }

        if ($word === 'REFRESH') {
            $batch->forceFill(['held_at' => null, 'held_by' => null])->save();
            SendWhatsAppPeriodReports::dispatch($batch->getKey(), null);
        } elseif ($word === 'HOLD') {
            $batch->forceFill(['held_at' => now(), 'held_by' => $approver->getKey()])->save();
        } elseif ($word === 'SEPARATELY') {
            SendWhatsAppPeriodReports::dispatch($batch->getKey(), null, $approver->getKey());
        } else {
            $batch->forceFill(['approved_at' => now(), 'approved_by' => $approver->getKey(), 'held_at' => null])->save();
            SendWhatsAppPeriodReports::dispatch($batch->getKey(), $approver->getKey());
        }

        $label = $this->label($batch);
        $this->groups->deliver(self::APPROVAL, $label, match ($word) {
            'REFRESH' => "\u{1F504} *Building a new copy now.*",
            'HOLD' => "\u{23F8} *On hold.* Nothing goes to the groups. Reply *APPROVE* when it is ready, or *REFRESH* for a new copy.",
            'SEPARATELY' => "\u{1F4E5} *Sending you each company's PDF now, privately.* Nothing goes to the groups.",
            default => "\u{2705} *Approved!*\n\n\u{1F4E4} Sending the ".$label.' to every group now.',
        });

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
