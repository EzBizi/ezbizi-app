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
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SocialLibraryItem;
use App\Support\Files\FileStore;
use App\Support\Marketing\QrImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    /** The centre picture: same 2 MB and byte-checked types as a newsletter picture. */
    private const MAX_PICTURE_BYTES = 2 * 1024 * 1024;

    private const PICTURE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public function __construct(private readonly AuditLogger $audit, private readonly FileStore $files) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $this->visible($user)
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
            'center_image' => $data['center_image'] ?? QrCode::IMAGE_LOGO,
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
     * (30%) because a code on a van gets dirty and scratched, and because the
     * centre picture (the company logo unless changed) hides ~5% of it.
     */
    public function image(Request $request, string $qrCode): Response
    {
        $qr = $this->findInView($request->user(), $qrCode);
        $png = $request->query('format') === 'png';
        $picture = $this->picture($qr);

        $bytes = $png ? QrImage::png($qr->url(), $picture) : QrImage::svg($qr->url(), $picture);
        $name = 'qr-'.$qr->code.($png ? '.png' : '.svg');

        return response($bytes, 200, [
            'Content-Type' => $png ? 'image/png' : 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /** GET /marketing/qr-codes/{id}/center-image: the picture alone, for the preview. 404 when none. */
    public function centerImage(Request $request, string $qrCode): Response
    {
        $picture = $this->picture($this->findInView($request->user(), $qrCode));
        $type = $picture === null ? null : $this->pictureType($picture);
        abort_if($type === null, Status::HTTP_NOT_FOUND);

        return response($picture, 200, [
            'Content-Type' => self::PICTURE_TYPES[$type],
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /**
     * POST /marketing/qr-codes/{id}/center-image (multipart `file`): any picture
     * for the middle of the code. Checked by its bytes (PNG, JPEG, GIF, WebP),
     * at most 2 MB, and kept in the media library of the code's place like a
     * newsletter picture, so the same bytes twice are one library item.
     */
    public function uploadCenterImage(Request $request, string $qrCode): JsonResponse
    {
        $user = $this->mayEdit($request, 'change a QR code');
        $qr = $this->findWritable($user, $qrCode);

        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Choose a picture of at most 2 MB.']);
        }
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        if (strlen($bytes) > self::MAX_PICTURE_BYTES) {
            throw ValidationException::withMessages(['file' => 'That picture is larger than 2 MB. Choose a smaller one.']);
        }
        $extension = $this->pictureType($bytes);
        if ($extension === null) {
            throw ValidationException::withMessages(['file' => 'That file is not a PNG, JPEG, GIF or WebP picture.']);
        }

        $hash = hash('sha256', $bytes);
        $item = SocialLibraryItem::query()->where('scope_type', $qr->scope_type)->where('scope_id', $qr->scope_id)
            ->where('content_hash', $hash)->first();
        if ($item === null) {
            $disk = $this->files->defaultDisk();
            $item = SocialLibraryItem::create([
                'scope_type' => $qr->scope_type,
                'scope_id' => $qr->scope_id,
                'name' => mb_substr(trim((string) $file->getClientOriginalName()) ?: 'QR picture.'.$extension, 0, 120),
                'disk' => $disk,
                'path' => $this->files->putContents($bytes, $this->files->directoryFor('social-library', (string) $user->organization_id), $extension, $disk),
                'size' => strlen($bytes),
                'content_hash' => $hash,
                'created_by' => $user->getKey(),
            ]);
            $this->audit->log('social.library_added', $item, ['name' => $item->name, 'from' => 'qr_code'], actor: $user);
        }

        $qr->forceFill(['center_image' => QrCode::IMAGE_UPLOAD, 'center_image_item_id' => $item->id]);
        $this->audit->logChange('qr_code.updated', $qr, $user);
        $qr->save();

        return response()->json(['data' => $this->present($qr)]);
    }

    /** The bytes for the middle of the code, or null for none. */
    private function picture(QrCode $qr): ?string
    {
        if ($qr->center_image === QrCode::IMAGE_NONE) {
            return null;
        }
        if ($qr->center_image === QrCode::IMAGE_UPLOAD) {
            $item = $qr->center_image_item_id === null ? null
                : SocialLibraryItem::query()->find($qr->center_image_item_id);

            return $item === null ? null : $this->files->contents($item->path, $item->disk);
        }

        // The logo the code's place shows: its company's (a branch shows its
        // company's), else the organization's — as BrandingController does.
        $companyId = match ($qr->scope_type) {
            'company' => $qr->scope_id,
            'franchise' => Franchise::query()->whereKey($qr->scope_id)->value('company_id'),
            default => null,
        };
        $path = ($companyId === null ? null : Company::query()->whereKey($companyId)->value('logo_path'))
            ?? Organization::query()->whereKey($qr->organization_id)->value('logo_path');

        return $path === null ? null : $this->files->contents($path);
    }

    private function pictureType(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $type = $info === false ? false : array_search($info['mime'] ?? '', self::PICTURE_TYPES, true);

        return is_string($type) ? $type : null;
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
        return $this->visible($user)->find($id)
            ?? abort(Status::HTTP_NOT_FOUND);
    }

    /**
     * Organization levels (Jose, 2026-11-23: "a-1 chimney stuff showing on
     * everdoors"): a company or branch view shows its own codes only. An
     * organization-level code carries the organization's logo, so it belongs
     * to the organization view alone, never to every company below it.
     */
    private function visible(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $query = NewsletterAccess::visible(QrCode::query(), $user);

        return ViewScope::forUser($user)->isOrganization()
            ? $query
            : $query->where('scope_type', '!=', SocialConnection::SCOPE_ORGANIZATION);
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
            // `upload` is set by uploading a picture, never by naming one here.
            'center_image' => ['sometimes', Rule::in([QrCode::IMAGE_LOGO, QrCode::IMAGE_NONE])],
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
            'center_image' => $q->center_image ?? QrCode::IMAGE_LOGO,
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
