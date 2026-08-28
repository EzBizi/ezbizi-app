<?php

namespace App\Support\Social;

/**
 * What a post carries: nothing but words, a picture, or a video.
 *
 * ── Why this is an axis of its own ──────────────────────────────
 *
 * "Can this network take this post" is not one question, it is three, and the
 * networks disagree on all three. YouTube will not take a picture. Instagram
 * will not take words alone. Bluesky has no video. TikTok is a video product
 * that also learned photos. Treating a post as one undifferentiated thing means
 * discovering the disagreement at send time, per network, as a failure — which
 * is exactly the moment it is most expensive and least explainable.
 *
 * Declared up front, the composer can grey a target out with a sentence before
 * anybody presses anything, which is the whole difference between "TikTok is
 * unchecked because your post has no video" and "TikTok failed".
 *
 * ── Why not mime types ──────────────────────────────────────────
 *
 * A mime type says image/png. It does not say whether Pinterest will take it,
 * and every network has its own list of the containers and codecs it accepts.
 * Three kinds is the coarse question the composer actually asks; the fine one
 * belongs to each publisher, which refuses with the network's own reason.
 */
enum SocialMediaKind: string
{
    case Text  = 'text';
    case Image = 'image';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Text  => 'Text only',
            self::Image => 'Image',
            self::Video => 'Video',
        };
    }

    /** The kind a file is, by extension, or null when it is neither. */
    public static function forFile(string $path): ?self
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif' => self::Image,
            'mp4', 'mov', 'm4v', 'webm'                         => self::Video,
            default                                             => null,
        };
    }
}
