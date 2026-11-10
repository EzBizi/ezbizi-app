<?php

namespace App\Support\Social;

use App\Jobs\SendWhatsAppPeriodReports;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\ReportBatchFile;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppReportBatch;
use App\Support\Reports\ReportJobs;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\PeriodReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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
        $today = ($today ?? Carbon::now(\App\Support\Settings\OrgTimezone::of()))->copy()->startOfDay();

        [$from, $to] = $period === 'month'
            ? [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()]
            : [$today->copy()->startOfWeek()->subWeek(), $today->copy()->startOfWeek()->subDay()];

        return WhatsAppReportBatch::firstOrCreate(
            ['period' => $period, 'starts_on' => $from->toDateString()],
            ['ends_on' => $to->toDateString()],
        );
    }

    /**
     * Build every PDF of the batch once, as a new revision, and keep them
     * (report_batch_files): what the approver sees is what APPROVE sends
     * (the proof layer, 2026-10-20). Inside the tenant.
     *
     * @return list<ReportBatchFile> the branches' PDFs, then the consolidated one
     */
    public function freeze(WhatsAppReportBatch $batch, PeriodReport $report): array
    {
        [$from, $to] = [$batch->starts_on->toDateString(), $batch->ends_on->toDateString()];
        $label = $this->label($batch);
        $revision = $batch->revision + 1;
        // The draft's corrections (ReportChanges), if any.
        $report = $report->withOptions((array) ($batch->settings ?? []));
        $files = [];

        $keep = function (?string $branchId, string $title, string $pdf, string $filename) use ($batch, $revision, &$files) {
            $path = 'reports/'.$batch->getKey().'/'.$revision.'/'.$filename;
            Storage::disk('local')->put($path, $pdf);
            $files[] = ReportBatchFile::query()->create([
                'organization_id' => $batch->organization_id, 'batch_id' => $batch->getKey(), 'revision' => $revision,
                'franchise_id' => $branchId, 'title' => $title, 'filename' => $filename, 'path' => $path, 'sha256' => hash('sha256', $pdf),
            ]);
        };

        foreach (WhatsAppGroup::query()->where('purpose', 'like', 'daily:%')->get() as $group) {
            $branch = Franchise::query()->find(substr($group->purpose, strlen('daily:')));

            if ($branch !== null) {
                $keep($branch->getKey(), BranchName::of($branch).' '.$label,
                    $report->forFranchise($branch, $from, $to), $this->filename($batch, BranchName::of($branch)));
            }
        }

        $keep(null, 'All companies '.$label,
            $report->consolidated(Organization::findOrFail($batch->organization_id), $from, $to),
            $this->filename($batch, 'All companies'));

        $batch->forceFill(['revision' => $revision])->save();

        return $files;
    }

    /**
     * A file's figures as text, sent before it: its branch's, or every branch
     * with jobs for the consolidated one. ponytail: read now, not frozen with
     * the PDF; freeze it too if an approved batch's figures ever move.
     */
    private function text(WhatsAppReportBatch $batch, PeriodReport $report, string $title, ?string $branchId): string
    {
        $branches = $branchId !== null ? Franchise::query()->whereKey($branchId)->get()
            : Franchise::query()->where('is_active', true)->whereIn('id', ReportJobs::branchesWithJobs())->get();

        return $report->summaryText($title, $branches, $batch->starts_on->toDateString(), $batch->ends_on->toDateString());
    }

    /** The frozen PDFs of a revision, the consolidated one last. */
    public function files(WhatsAppReportBatch $batch, ?int $revision = null): Collection
    {
        return ReportBatchFile::query()->where('batch_id', $batch->getKey())->where('revision', $revision ?? $batch->revision)
            ->get()->sortBy(fn ($f) => $f->franchise_id === null)->values();
    }

    /** Freeze a new revision and send the approver its consolidated PDF and the question. Inside the tenant. */
    public function preview(WhatsAppReportBatch $batch, PeriodReport $report): int
    {
        // Approved, or held by the approver until they say otherwise.
        if ($batch->approved_at !== null || $batch->held_at !== null) {
            return 0;
        }

        $label = $this->label($batch);
        $consolidated = collect($this->freeze($batch, $report))->last();

        $reached = $this->groups->deliverDocument(self::APPROVAL, $label.' (for approval)', $consolidated->bytes(), $consolidated->filename,
            $this->text($batch, $report, ucfirst($label), null));

        $this->groups->deliver(self::APPROVAL, $label,
            "\u{1F4CA} *".ucfirst($label)." - ready for approval*\n"
            .'Version '.$batch->revision.' · '.substr($consolidated->sha256, 0, 8)."\n\n"
            ."\u{2705} Reply *APPROVE* to send them to every group\n"
            ."\u{1F4E5} Reply *SEPARATELY* to get each company's PDF here, privately\n"
            ."\u{23F8} Reply *HOLD* to keep them back for now\n"
            ."\u{1F504} Reply *REFRESH* for a new copy once the Airtable P&L is in", file: false);

        return $reached;
    }

    /**
     * Approved: the approved revision's own PDFs, each branch its PDF and the
     * consolidated one to `financial` - never a rebuild. Inside the tenant.
     */
    public function send(WhatsAppReportBatch $batch, PeriodReport $report): int
    {
        $sent = 0;
        $files = $this->files($batch, $batch->approved_revision ?? $batch->revision);

        // Approved with no preview built (nothing to compare): build it now.
        if ($files->isEmpty()) {
            $this->freeze($batch, $report);
            $files = $this->files($batch->refresh());
        }

        foreach ($files as $file) {
            $sent += $this->groups->deliverDocument($file->franchise_id === null ? 'financial' : 'daily:'.$file->franchise_id,
                $file->title, $file->bytes(), $file->filename, $this->text($batch, $report, $file->title, $file->franchise_id));
        }

        return $sent;
    }

    /**
     * Each company's PDF and the consolidated one to one approver, privately
     * (SEPARATELY). Inside the tenant.
     */
    public function sendPrivately(WhatsAppReportBatch $batch, PeriodReport $report, User $to): int
    {
        // A second SEPARATELY while the first is going sends nothing more.
        if (! Cache::add('separately:'.$batch->getKey().':'.$to->getKey(), 1, now()->addMinutes(10))) {
            return 0;
        }

        $files = $this->files($batch);
        if ($files->isEmpty()) {
            $this->freeze($batch, $report);
            $files = $this->files($batch->refresh());
        }

        $documents = $files->map(fn (ReportBatchFile $f) => ['title' => $f->title, 'pdf' => $f->bytes(), 'filename' => $f->filename,
            'text' => $this->text($batch, $report, $f->title, $f->franchise_id)])->all();
        $label = $this->label($batch);

        return $this->groups->deliverDocumentsToMember(self::APPROVAL, $to, ucfirst($label), $documents);
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
            $this->approve($batch, $approver);
        }

        $label = $this->label($batch);
        $this->groups->deliver(self::APPROVAL, $label, match ($word) {
            'REFRESH' => "\u{1F504} *Building a new copy now.*",
            'HOLD' => "\u{23F8} *On hold.* Nothing goes to the groups. Reply *APPROVE* when it is ready, or *REFRESH* for a new copy.",
            'SEPARATELY' => "\u{1F4E5} *Sending you each company's PDF now, privately.* Nothing goes to the groups.",
            default => "\u{2705} *Approved!*\n\n\u{1F4E4} Sending the ".$label.' to every group now.',
        }, file: false);

        return true;
    }

    /**
     * Approve the revision the approver saw (its consolidated PDF's hash) and
     * send exactly those files. From the WhatsApp reply or the Reports
     * workspace. Inside the tenant.
     */
    public function approve(WhatsAppReportBatch $batch, User $approver): void
    {
        $consolidated = $this->files($batch)->last();
        $batch->forceFill(['approved_at' => now(), 'approved_by' => $approver->getKey(), 'held_at' => null,
            'approved_revision' => $batch->revision, 'approved_sha256' => $consolidated?->sha256])->save();
        SendWhatsAppPeriodReports::dispatch($batch->getKey(), $approver->getKey());
    }

    /** Whether this person is in the approval group, who may APPROVE. */
    public static function mayApprove(User $user): bool
    {
        return (bool) WhatsAppGroup::query()->where('purpose', self::APPROVAL)->first()
            ?->members()->where('users.id', $user->getKey())->where('is_active', true)->exists();
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
