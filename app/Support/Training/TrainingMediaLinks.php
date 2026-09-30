<?php

namespace App\Support\Training;

use App\Models\TrainingGuide;
use App\Models\TrainingMedia;
use Illuminate\Support\Facades\URL;

/**
 * Turns a screen's `media_id` into a link somebody can actually load.
 *
 * A screen holding an image EzBizi stores itself carries `media_id` and a null
 * `media_url`. This fills `media_url` with a signed, short-lived link to
 * TrainingMediaController, and is called by the two paths that hand screens to
 * a person — the author's guide read and the learner's — AFTER each has
 * authorised that person to read the guide. The signature is the only thing
 * the serving route checks, so issuing it is where the authorisation lives.
 *
 * `media_id` itself is returned too, because the builder has to send it back
 * on save (see GuidesController::normalizeScreens). It grants nothing on its
 * own: without a signature the route refuses, and the id is not guessable.
 *
 * ── Why the link expires, and why this long ─────────────────────
 *
 * A signed URL is a bearer capability until it expires, so it must. Two hours
 * covers somebody working through a long guide in one sitting; the guide is
 * re-read — and re-signed — every time it is opened.
 */
final class TrainingMediaLinks
{
    public const LIFETIME_MINUTES = 120;

    /**
     * @param  array<int, array<string, mixed>>  $screens
     * @return array<int, array<string, mixed>>
     */
    public static function resolve(array $screens, TrainingGuide $guide): array
    {
        $ids = array_values(array_filter(array_map(
            fn (array $screen) => $screen['media_id'] ?? null,
            $screens,
        )));

        if ($ids === []) {
            return $screens;
        }

        // Owned by THIS guide only. A reference to another guide's media — or
        // another tenant's — resolves to nothing rather than to a link.
        $owned = TrainingMedia::query()
            ->where('guide_id', $guide->getKey())
            ->whereIn('id', $ids)
            ->pluck('id')
            ->flip();

        $expires = now()->addMinutes(self::LIFETIME_MINUTES);

        return array_map(function (array $screen) use ($owned, $expires) {
            $id = $screen['media_id'] ?? null;

            if ($id === null) {
                return $screen;
            }

            $screen['media_url'] = $owned->has($id)
                ? URL::temporarySignedRoute('training.media', $expires, ['media' => $id])
                : null;

            return $screen;
        }, $screens);
    }
}
