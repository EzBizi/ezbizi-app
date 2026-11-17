<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Models\SocialLibraryItem;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Newsletter\NewsletterAccess;
use App\Support\Newsletter\SvgImporter;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as Status;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Newsletter imports: an SVG design, and the artwork it leaves behind.
 *
 * `svg` only reads — it answers blocks, the artwork with its text removed, and
 * warnings, and stores nothing, so a refused or abandoned import leaves the
 * draft exactly as it was. `artwork` stores the PNG the composer rasterised.
 * Only PNG is ever stored or served: an SVG served from this host would run in
 * a browser that opened its address directly.
 */
class NewsletterImportController extends Controller
{
    /** 2 MB of PNG is already a heavy email. */
    private const MAX_PNG_BYTES = 2 * 1024 * 1024;

    private const TOO_LARGE = 'That picture is larger than 2 MB, the most an email picture may be. Make it smaller and try again.';

    /** The pictures served from the artwork folder, by extension. */
    private const PICTURE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public function __construct(
        private readonly SvgImporter $importer,
        private readonly FileStore $files,
        private readonly AuditLogger $audit,
    ) {}

    public function svg(Request $request): JsonResponse
    {
        $this->mayWrite($request);
        $data = $request->validate(['svg' => ['required', 'string']], [
            'svg.required' => 'Choose or paste an SVG first.',
        ]);

        return response()->json(['data' => $this->importer->import((string) $data['svg'])]);
    }

    public function artwork(Request $request): JsonResponse
    {
        $user = $this->mayWrite($request);
        $data = $request->validate(['png' => ['required', 'string']]);

        $bytes = base64_decode((string) $data['png'], true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);

        if ($bytes === false || $info === false || ($info['mime'] ?? '') !== 'image/png') {
            throw ValidationException::withMessages(['png' => 'The artwork did not arrive as a PNG picture.']);
        }
        if (strlen($bytes) > self::MAX_PNG_BYTES) {
            throw ValidationException::withMessages(['png' => 'The artwork is larger than 2 MB as a picture. Simplify the design.']);
        }

        $organization = (string) $user->organization_id;
        $path = $this->files->putContents($bytes, $this->files->directoryFor('newsletter-artwork', $organization), 'png');

        return response()->json(['data' => [
            'url' => rtrim((string) config('app.url'), '/')
                ."/api/v1/newsletter/artwork/{$organization}/".basename($path),
        ]], Status::HTTP_CREATED);
    }

    /** Public: the inbox fetching the picture has no login. The name is 40 random characters. */
    public function show(string $org, string $name): StreamedResponse
    {
        $path = $this->files->directoryFor('newsletter-artwork', $org).'/'.$name;
        abort_unless($this->files->exists($path), Status::HTTP_NOT_FOUND);

        // Only the raster types `library` stores; never an SVG.
        $mime = self::PICTURE_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
        abort_if($mime === null, Status::HTTP_NOT_FOUND);

        return $this->files->serve($path, mime: $mime, filename: $name, disposition: 'inline');
    }

    /**
     * POST /newsletter/import/library/{item}: a picture from the media library,
     * given a public address an inbox can load (review 2026-11-09 A29). The
     * item keeps that one copy, so choosing it again answers the same address.
     */
    public function library(Request $request, string $item): JsonResponse
    {
        $user = $this->mayWrite($request);
        $row = app(SocialLibraryController::class)->find($user, $item);

        $bytes = $this->files->contents($row->path, $row->disk);
        $extension = $bytes === null ? null : $this->pictureExtension($bytes);
        if ($bytes === null || $extension === null) {
            throw ValidationException::withMessages(['item' => 'That library item is not a picture an email can show.']);
        }
        if (strlen($bytes) > self::MAX_PNG_BYTES) {
            throw ValidationException::withMessages(['item' => 'That picture is larger than 2 MB. Choose a smaller one.']);
        }

        return response()->json(['data' => [
            'id' => $row->id,
            'url' => $this->publish($row, $bytes, $extension),
        ]], Status::HTTP_CREATED);
    }

    /**
     * POST /newsletter/images: a picture uploaded from the newsletter composer
     * (newsletter P0, 2026-11-17). Checked by its bytes, not its name: PNG,
     * JPEG, GIF or WebP, at most 2 MB. Kept in the media library of the view
     * it was uploaded in; the same bytes again in the same scope answer the
     * existing item (same id, same address) rather than a second copy.
     */
    public function upload(Request $request): JsonResponse
    {
        $user = $this->mayWrite($request);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Choose a picture to upload.']);
        }
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? self::TOO_LARGE : 'The picture did not arrive whole. Try again.']);
        }

        $bytes = (string) file_get_contents((string) $file->getRealPath());
        if (strlen($bytes) > self::MAX_PNG_BYTES) {
            throw ValidationException::withMessages(['file' => self::TOO_LARGE]);
        }
        $extension = $this->pictureExtension($bytes);
        if ($extension === null) {
            throw ValidationException::withMessages(['file' => 'That file is not a PNG, JPEG, GIF or WebP picture.']);
        }

        $view = ViewScope::forUser($user);
        [$scopeType, $scopeId] = $view->isOrganization()
            ? [SocialConnection::SCOPE_ORGANIZATION, (string) $user->organization_id]
            : [$view->type, (string) $view->id];
        abort_unless(OwnedScope::mayWrite($user, $user->organization_id, $scopeType, $scopeId),
            Status::HTTP_FORBIDDEN, 'Choose a view where your assignments allow adding pictures.');

        $hash = hash('sha256', $bytes);
        $row = SocialLibraryItem::query()->where('scope_type', $scopeType)->where('scope_id', $scopeId)
            ->where('content_hash', $hash)->first();
        $duplicate = $row !== null;

        if ($row === null) {
            $disk = $this->files->defaultDisk();
            $name = trim((string) $request->input('name', '')) ?: trim((string) $file->getClientOriginalName());
            $row = SocialLibraryItem::create([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'name' => mb_substr($name !== '' ? $name : 'Picture.'.$extension, 0, 120),
                'disk' => $disk,
                'path' => $this->files->putContents($bytes, $this->files->directoryFor('social-library', (string) $user->organization_id), $extension, $disk),
                'size' => strlen($bytes),
                'content_hash' => $hash,
                'created_by' => $user->getKey(),
            ]);
            $this->audit->log('social.library_added', $row, ['name' => $row->name, 'from' => 'newsletter'], actor: $user);
        }

        return response()->json(['data' => [
            'id' => $row->id,
            'name' => $row->name,
            'kind' => 'image',
            'size' => (int) $row->size,
            'scope_type' => $row->scope_type,
            'created_at' => $row->created_at?->toIso8601String(),
            'url' => $this->publish($row, $bytes, $extension),
            'duplicate' => $duplicate,
        ]], $duplicate ? Status::HTTP_OK : Status::HTTP_CREATED);
    }

    /**
     * GET /newsletter/images: the pictures in the media library of this view,
     * for the newsletter picker (P1a). Same visibility as the library itself
     * (an organization's pictures are seen down, a sibling company's never),
     * but gated on newsletter permissions, so a newsletter-only role can pick
     * a picture without being given Social Media.
     */
    public function images(Request $request): JsonResponse
    {
        $user = $this->mayWrite($request);
        $items = NewsletterAccess::visible(SocialLibraryItem::query(), $user)->latest()->get()
            ->filter(fn (SocialLibraryItem $i) => $i->kind() === 'image');

        return response()->json(['data' => $items->map(fn (SocialLibraryItem $i) => [
            'id' => $i->id,
            'name' => $i->name,
            'kind' => 'image',
            'size' => $i->size,
            'scope_type' => $i->scope_type,
            'created_at' => $i->created_at?->toIso8601String(),
        ])->values()->all()]);
    }

    /** GET /newsletter/images/{item}/file: a picker thumbnail. */
    public function imageFile(Request $request, string $item): StreamedResponse
    {
        $user = $this->mayWrite($request);
        $row = app(SocialLibraryController::class)->find($user, $item);
        abort_unless($row->kind() === 'image', Status::HTTP_NOT_FOUND);

        return $this->files->serve($row->path, $row->disk, $row->mime(), $row->name, 'inline');
    }

    /**
     * POST /newsletter/images/crop: a cropped copy of a library picture for
     * one image block (P1a). Non-destructive: the library original is never
     * changed; the block keeps the original's address and the crop (aspect
     * and focal point), and re-cropping always starts from the original.
     *
     * `src` must be the public address of a library picture visible in this
     * view; `aspect` is original, 1:1, 4:3, 3:2 or 16:9; `focal_x`/`focal_y`
     * (0-1) is the point the crop keeps centred.
     */
    public function crop(Request $request): JsonResponse
    {
        $user = $this->mayWrite($request);
        $data = $request->validate([
            'src' => ['required', 'string', 'max:2000'],
            'aspect' => ['required', 'string', 'in:original,1:1,4:3,3:2,16:9'],
            'focal_x' => ['nullable', 'numeric', 'between:0,1'],
            'focal_y' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $name = basename((string) parse_url((string) $data['src'], PHP_URL_PATH));
        $row = $name === '' ? null : NewsletterAccess::visible(SocialLibraryItem::query(), $user)
            ->where('public_path', 'like', '%/'.addcslashes($name, '%_\\'))->first();
        abort_if($row === null, Status::HTTP_NOT_FOUND, 'That picture is not in this view’s library.');

        if ($data['aspect'] === 'original') {
            return response()->json(['data' => ['url' => $data['src'], 'original_src' => $data['src']]]);
        }

        $bytes = $this->files->contents($row->path, $row->disk);
        $image = $bytes === null ? false : @imagecreatefromstring($bytes);
        $extension = $bytes === null ? null : $this->pictureExtension($bytes);
        if ($image === false || $extension === null) {
            throw ValidationException::withMessages(['src' => 'That picture could not be read to crop it.']);
        }

        [$w, $h] = [imagesx($image), imagesy($image)];
        [$aw, $ah] = array_map('intval', explode(':', (string) $data['aspect']));
        $cw = $w;
        $ch = (int) round($w * $ah / $aw);
        if ($ch > $h) {
            $ch = $h;
            $cw = (int) round($h * $aw / $ah);
        }
        $fx = (float) ($data['focal_x'] ?? 0.5);
        $fy = (float) ($data['focal_y'] ?? 0.5);
        $x = (int) max(0, min($w - $cw, round($fx * $w - $cw / 2)));
        $y = (int) max(0, min($h - $ch, round($fy * $h - $ch / 2)));

        $cropped = imagecrop($image, ['x' => $x, 'y' => $y, 'width' => max(1, $cw), 'height' => max(1, $ch)]);
        if ($cropped === false) {
            throw ValidationException::withMessages(['src' => 'That picture could not be cropped.']);
        }
        if (in_array($extension, ['png', 'webp', 'gif'], true)) {
            imagesavealpha($cropped, true);
        }
        ob_start();
        match ($extension) {
            'jpg' => imagejpeg($cropped, null, 88),
            'webp' => imagewebp($cropped),
            'gif' => imagegif($cropped),
            default => imagepng($cropped),
        };
        $out = (string) ob_get_clean();

        $path = $this->files->putContents($out, $this->files->directoryFor('newsletter-artwork', (string) $row->organization_id), $extension);

        return response()->json(['data' => [
            'url' => rtrim((string) config('app.url'), '/')."/api/v1/newsletter/artwork/{$row->organization_id}/".basename($path),
            'original_src' => $data['src'],
            'width' => imagesx($cropped),
            'height' => imagesy($cropped),
        ]], Status::HTTP_CREATED);
    }

    /** The one public copy of a library picture, written the first time it is needed. */
    private function publish(SocialLibraryItem $row, string $bytes, string $extension): string
    {
        $organization = (string) $row->organization_id;
        $path = $row->public_path;
        if (! is_string($path) || $path === '' || ! $this->files->exists($path)) {
            $path = $this->files->putContents($bytes, $this->files->directoryFor('newsletter-artwork', $organization), $extension);
            $row->forceFill(['public_path' => $path, 'content_hash' => $row->content_hash ?? hash('sha256', $bytes)])->save();
        }

        return rtrim((string) config('app.url'), '/')
            ."/api/v1/newsletter/artwork/{$organization}/".basename($path);
    }

    /** The extension for bytes that really are a picture an email can show, else null. */
    private function pictureExtension(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $extension = $info === false ? false : array_search($info['mime'] ?? '', self::PICTURE_TYPES, true);

        return is_string($extension) ? $extension : null;
    }

    private function mayWrite(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('newsletter.create') || $user->hasPermission('newsletter.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('import a newsletter design')->message(),
        );

        return $user;
    }
}
