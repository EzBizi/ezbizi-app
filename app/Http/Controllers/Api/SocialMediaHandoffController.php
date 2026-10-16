<?php

namespace App\Http\Controllers\Api;

use App\Support\Social\Publishing\MediaHandoff;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves one handoff file to the network that is fetching it.
 *
 * ── Why this has no authentication ──────────────────────────────
 *
 * Because the thing on the other end cannot authenticate. Instagram builds a
 * post by fetching a URL with an anonymous crawler; there is no way to give it
 * a token. The signature IS the credential, which is why the route carries
 * `signed` and this method carries no user check — see MediaHandoff for the
 * four things that make that safe enough to have chosen.
 *
 * ── Why it does not 404 loudly ──────────────────────────────────
 *
 * A handoff that has been released is the NORMAL end state — publishing deletes
 * it seconds after the fetch. A miss here is almost always a network retrying
 * after we finished, not an attack and not a bug, so it is a plain 404 and not
 * an exception anybody gets paged for.
 */
class SocialMediaHandoffController extends Controller
{
    public function show(string $handle): Response
    {
        $disk = Storage::disk('local');
        $path = MediaHandoff::pathFor($handle);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: [
            // Meta reads the type off the response, not off the URL. Serving
            // everything as octet-stream makes Instagram reject the container
            // with a message about the format of a perfectly good JPEG.
            'Content-Type'  => MediaHandoff::mimeFor($handle),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
