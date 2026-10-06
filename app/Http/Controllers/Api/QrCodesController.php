<?php

namespace App\Http\Controllers\Api;

use App\Models\QrCode;
use App\Models\QrCodeScan;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Newsletter\NewsletterAccess;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use App\Support\Time\ViewTimezone;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Marketing > QR Codes: printed once, pointed anywhere afterwards.
 *
 * Reading is the tab (`marketing.qr_codes.view`, on the route group); creating,
 * re-pointing and (de)activating are `marketing.edit`, the same key that publishes
 * a post. Scope follows newsletters exactly — filed where the view stands,
 * visible downward, never to a sibling company.
 */
class QrCodesController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = NewsletterAccess::visible(QrCode::query(), $user)
            ->orderBy('name')
            ->get();

        // The card's mini chart: the last 30 days, in the viewer's timezone.
        $tz = ViewTimezone::forUser($user);
        $from = $this->firstDay($tz, 30);
        $scans = $this->scansSince($rows->modelKeys(), $from)->groupBy('qr_code_id');

        return response()->json(['data' => $rows->map(function (QrCode $q) use ($scans, $tz, $from) {
            $days = $this->days($scans->get($q->id, collect()), $tz, $from, 30);

            return $this->present($q) + [
                'scans_30d' => array_sum(array_column($days, 'scans')),
                'people_30d' => array_sum(array_column($days, 'people')),
                'daily_30d' => array_column($days, 'scans'),
            ];
        })->all()]);
    }

    /**
     * GET /marketing/qr-codes/{id}/stats?days=30|90 — every day, zeros included.
     *
     * `people` is distinct scanners per DAY, and the period figure is those
     * days added up: the same phone on two days counts twice. That is the
     * privacy design, not a shortcut — the daily salt is thrown away, so the
     * same phone cannot be recognised across days. Clients say "daily
     * scanners", never "people".
     */
    public function stats(Request $request, string $qrCode): JsonResponse
    {
        $user = $request->user();
        $qr = $this->findInView($user, $qrCode);
        $count = (int) $request->query('days', '30') === 90 ? 90 : 30;

        $tz = ViewTimezone::forUser($user);
        $from = $this->firstDay($tz, $count);
        $days = $this->days($this->scansSince([$qr->id], $from), $tz, $from, $count);

        return response()->json(['data' => [
            'from' => $days[0]['date'],
            'to' => $days[$count - 1]['date'],
            'timezone' => $tz,
            'days' => $days,
            'scans' => array_sum(array_column($days, 'scans')),
            'people' => array_sum(array_column($days, 'people')),
        ]]);
    }

    /** Local midnight $count - 1 days before today. */
    private function firstDay(string $tz, int $count): Carbon
    {
        return now($tz)->startOfDay()->subDays($count - 1);
    }

    /**
     * ponytail: bucketed in PHP. Fine for vehicle codes; aggregate in SQL if a
     * code ever sees tens of thousands of scans a quarter.
     *
     * @param  string[]  $ids
     */
    private function scansSince(array $ids, Carbon $from): Collection
    {
        return $ids === [] ? collect() : QrCodeScan::query()
            ->whereIn('qr_code_id', $ids)
            ->where('scanned_at', '>=', $from->copy()->utc())
            ->get(['qr_code_id', 'scanned_at', 'visitor']);
    }

    /** @return list<array{date: string, scans: int, people: int}> */
    private function days(Collection $scans, string $tz, Carbon $from, int $count): array
    {
        $byDay = $scans->groupBy(fn (QrCodeScan $s) => $s->scanned_at->copy()->setTimezone($tz)->toDateString());

        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $those = $byDay->get($date, collect());
            $days[] = [
                'date' => $date,
                'scans' => $those->count(),
                'people' => $those->pluck('visitor')->filter()->unique()->count(),
            ];
        }

        return $days;
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->mayEdit($request, 'create a QR code');
        $data = $this->validated($request, creating: true);

        $view = ViewScope::forUser($user);
        [$scopeType, $scopeId] = $view->isOrganization()
            ? [SocialConnection::SCOPE_ORGANIZATION, (string) $user->organization_id]
            : [$view->type, (string) $view->id];

        abort_unless(OwnedScope::mayWrite($user, $user->organization_id, $scopeType, $scopeId),
            Status::HTTP_FORBIDDEN, 'Choose a view where your assignments allow creating QR codes.');

        $qr = QrCode::create([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'code' => QrCode::freshCode(),
            'name' => $data['name'],
            'destination_url' => $data['destination_url'],
            'created_by' => $user->getKey(),
        ]);

        $this->audit->log('qr_code.created', $qr, [
            'name' => $qr->name, 'destination_url' => $qr->destination_url,
        ], actor: $user);

        return response()->json(['data' => $this->present($qr->fresh())], Status::HTTP_CREATED);
    }

    public function update(Request $request, string $qrCode): JsonResponse
    {
        $user = $this->mayEdit($request, 'change a QR code');
        $qr = $this->findWritable($user, $qrCode);
        $data = $this->validated($request, creating: false);

        $qr->fill($data);
        $this->audit->logChange('qr_code.updated', $qr, $user);
        $qr->save();

        return response()->json(['data' => $this->present($qr)]);
    }

    /*
     * There is no delete (Jose, 2026-10-06): a printed code deleted by accident
     * cannot be printed back. Deactivating stops the redirect and keeps the
     * code and its destination, so reactivating restores exactly what it did.
     */
    public function deactivate(Request $request, string $qrCode): JsonResponse
    {
        return $this->setActive($request, $qrCode, active: false);
    }

    public function reactivate(Request $request, string $qrCode): JsonResponse
    {
        return $this->setActive($request, $qrCode, active: true);
    }

    private function setActive(Request $request, string $qrCode, bool $active): JsonResponse
    {
        $user = $this->mayEdit($request, $active ? 'reactivate a QR code' : 'deactivate a QR code');
        $qr = $this->findWritable($user, $qrCode);

        // Asking twice is not a second event.
        if (($qr->deactivated_at === null) !== $active) {
            $qr->forceFill(['deactivated_at' => $active ? null : now()])->save();

            $this->audit->log($active ? 'qr_code.reactivated' : 'qr_code.deactivated', $qr, [
                'name' => $qr->name, 'code' => $qr->code, 'destination_url' => $qr->destination_url,
            ], actor: $user);
        }

        return response()->json(['data' => $this->present($qr)]);
    }

    /**
     * The printable image. SVG is vector, which is what a vehicle wrap needs;
     * PNG is 2000px for anything that only takes pictures. Error correction H
     * (30%) because a code on a van gets dirty and scratched.
     */
    public function image(Request $request, string $qrCode): Response
    {
        $qr = $this->findInView($request->user(), $qrCode);
        $png = $request->query('format') === 'png';

        $renderer = $png
            ? new GDLibRenderer(2000)
            : new ImageRenderer(new RendererStyle(1000, 4), new SvgImageBackEnd);
        $bytes = (new Writer($renderer))->writeString($qr->url(), 'UTF-8', ErrorCorrectionLevel::H());

        $name = 'qr-'.$qr->code.($png ? '.png' : '.svg');

        return response($bytes, 200, [
            'Content-Type' => $png ? 'image/png' : 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    private function mayEdit(Request $request, string $doing): User
    {
        $user = $request->user();
        abort_unless($user->hasPermission('marketing.edit'), Status::HTTP_FORBIDDEN,
            Refusal::permission($doing)->message());

        return $user;
    }

    private function findInView(User $user, string $id): QrCode
    {
        // Out of view is a 404: a sibling learning a code exists is the leak.
        return NewsletterAccess::visible(QrCode::query(), $user)->find($id)
            ?? abort(Status::HTTP_NOT_FOUND);
    }

    private function findWritable(User $user, string $id): QrCode
    {
        $qr = $this->findInView($user, $id);
        abort_unless(NewsletterAccess::mayWrite($user, $qr), Status::HTTP_FORBIDDEN,
            'Switch to a view that can manage this QR code.');

        return $qr;
    }

    /** @return array<string, string> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        // http(s) only: the redirect hands this straight to a stranger's phone.
        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'destination_url' => [$required, 'string', 'max:2048', 'url:http,https'],
        ], [
            'destination_url.url' => 'Enter a full web address starting with https://.',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(QrCode $q): array
    {
        return [
            'id' => $q->id,
            'name' => $q->name,
            'code' => $q->code,
            'url' => $q->url(),
            'destination_url' => $q->destination_url,
            'scans' => $q->scans,
            'last_scanned_at' => $q->last_scanned_at?->toIso8601String(),
            'active' => $q->deactivated_at === null,
            'deactivated_at' => $q->deactivated_at?->toIso8601String(),
            'scope_type' => $q->scope_type,
            'scope_id' => $q->scope_id,
            'created_at' => $q->created_at?->toIso8601String(),
        ];
    }
}
