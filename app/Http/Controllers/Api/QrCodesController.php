<?php

namespace App\Http\Controllers\Api;

use App\Models\QrCode;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Newsletter\NewsletterAccess;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Marketing > QR Codes: printed once, pointed anywhere afterwards.
 *
 * Reading is the tab (`marketing.qr_codes.view`, on the route group); creating,
 * re-pointing and deleting are `marketing.edit`, the same key that publishes
 * a post. Scope follows newsletters exactly — filed where the view stands,
 * visible downward, never to a sibling company.
 */
class QrCodesController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $rows = NewsletterAccess::visible(QrCode::query(), $request->user())
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows->map(fn (QrCode $q) => $this->present($q))->all()]);
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

    public function destroy(Request $request, string $qrCode): JsonResponse
    {
        $user = $this->mayEdit($request, 'delete a QR code');
        $qr = $this->findWritable($user, $qrCode);

        $this->audit->log('qr_code.deleted', $qr, [
            'name' => $qr->name, 'code' => $qr->code, 'destination_url' => $qr->destination_url,
        ], actor: $user);
        $qr->delete();

        return response()->json(['data' => ['id' => $qrCode]]);
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
            'scope_type' => $q->scope_type,
            'scope_id' => $q->scope_id,
            'created_at' => $q->created_at?->toIso8601String(),
        ];
    }
}
