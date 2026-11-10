<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Files\FileStore;
use App\Support\Newsletter\SvgImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function __construct(
        private readonly SvgImporter $importer,
        private readonly FileStore $files,
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

        return $this->files->serve($path, mime: 'image/png', filename: $name, disposition: 'inline');
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
