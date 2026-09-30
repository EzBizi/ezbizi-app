<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\TrainingMedia;
use App\Support\Files\FileStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/training/media/{media} — one image a guide holds itself.
 *
 * `signed` middleware is the authentication and there is no other: the link
 * is issued by TrainingMediaLinks only after the reader was authorised to read
 * the guide, the same shape as the social media handoff. So this reads without
 * a tenant in context, which is the only way a signed request can arrive.
 *
 * Served INLINE, which FileStore::serve's own docblock calls a stored-XSS
 * vector for an arbitrary upload. It is safe here because only
 * TrainingMedia::SERVABLE types are ever served, and the importer admits a
 * file only after reading its magic bytes as a real JPEG or PNG — the mime is
 * measured, not taken from a filename.
 */
class MediaController extends Controller
{
    public function show(string $media, FileStore $files): StreamedResponse
    {
        $row = TrainingMedia::withoutGlobalScopes()->findOrFail($media);

        abort_unless(in_array($row->mime, TrainingMedia::SERVABLE, true), 404);

        return $files->serve($row->path, $row->disk, $row->mime, disposition: 'inline');
    }
}
