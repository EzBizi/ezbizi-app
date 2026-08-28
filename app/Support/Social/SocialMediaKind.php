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

    /** Extension => kind. The one list; both methods below read it. */
    private const BY_EXTENSION = [
        'jpg'  => self::Image, 'jpeg' => self::Image, 'png'  => self::Image,
        'webp' => self::Image, 'heic' => self::Image, 'heif' => self::Image,
        'gif'  => self::Image,
        'mp4'  => self::Video, 'mov'  => self::Video, 'm4v'  => self::Video,
        'webm' => self::Video,
    ];

    /** The kind a file is, by extension, or null when it is neither. */
    public static function forFile(string $path): ?self
    {
        return self::BY_EXTENSION[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * What the composer's upload will accept, for the `mimes` rule.
     *
     * The same list, rather than `ezbizi.attachments.extensions` — that one is
     * shorter and different: it carries `pdf` and `docx`, which no network
     * takes, and no video at all, which would refuse the only thing TikTok
     * posts. Widening the general attachment list to fix that would change what
     * every OTHER upload on the platform accepts, to answer a question that
     * belongs to this module.
     *
     * @return string[]
     */
    public static function extensions(): array
    {
        return array_keys(self::BY_EXTENSION);
    }
}
