<?php

namespace App\Jobs;

use App\Http\Controllers\Api\ReportPeriodController;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Support\Ai\ReportFileRequest;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Scoping\ViewScope;
use App\Support\Social\WhatsAppCloud;
use App\Support\Social\WhatsAppGroups;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * "Send me the CT report for last month" on the staff WhatsApp (takeover row
 * 50): Grok reads the ask, then GET /reports/period runs AS the asker — same
 * view, same reports.export for Excel — and the file comes back in the chat.
 */
class SendRequestedReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // a retry would send the file twice

    public int $timeout = 85;

    public function __construct(public string $numberId, public string $to, public string $userId, public string $text) {}

    public function handle(TenantContext $tenant, WhatsAppCloud $cloud): void
    {
        $number = WhatsAppNumber::query()->withoutGlobalScopes()->findOrFail($this->numberId);

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($number->organization_id), function () use ($number, $cloud) {
            $say = fn (string $body) => $cloud->replyFreeForm($number, $this->to,
                ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $body]], $body);
            $user = User::query()->find($this->userId);
            if ($user === null || ! $user->hasPermission('reports.view')) {
                return;
            }
            $branches = Franchise::query()->whereIn('id', ViewScope::forUser($user)->franchiseIds())->get()
                ->keyBy(fn ($b) => BranchName::of($b));

            try {
                $ask = ReportFileRequest::read($this->text, $branches->keys()->all(), CarbonImmutable::now(\App\Support\Settings\OrgTimezone::of()));
            } catch (RuntimeException) {
                $say("\u{26A0}\u{FE0F} I couldn't read that just now. Try again in a minute.");

                return;
            }
            if ($ask === null || $ask['clarify'] !== null) {
                $say("\u{1F914} ".($ask['clarify'] ?? 'Which report do you want? e.g. *send me the CT report for last month*.'));

                return;
            }

            $query = array_filter(['franchise_id' => $ask['branch'] === null ? null : $branches[$ask['branch']]->id,
                'from' => $ask['from'], 'to' => $ask['to'], 'format' => $ask['format'], 'report' => $ask['report']]);
            $request = Request::create('/api/v1/reports/period', 'GET', $query);
            $request->setUserResolver(fn () => $user);
            Auth::setUser($user);
            try {
                $file = app(ReportPeriodController::class)->show($request, app(PeriodReport::class));
            } catch (HttpException $e) {
                $say("\u{1F6AB} ".($e->getMessage() ?: "You don't have access to that report."));

                return;
            } catch (Throwable $e) {
                report($e);
                $say("\u{26A0}\u{FE0F} That report couldn't be built just now. Download it in EzBizi › Reports.");

                return;
            }

            preg_match('/filename="([^"]+)"/', (string) $file->headers->get('Content-Disposition'), $m);
            $name = $m[1] ?? 'report.'.$ask['format'];
            $id = app(WhatsAppGroups::class)->upload($number, (string) $file->getContent(), $name, (string) $file->headers->get('Content-Type'));
            $cloud->replyFreeForm($number, $this->to, ['type' => 'document', 'document' => ['id' => $id, 'filename' => $name, 'caption' => "\u{1F4C4} *".pathinfo($name, PATHINFO_FILENAME).'*']], $name);
        });
    }
}
