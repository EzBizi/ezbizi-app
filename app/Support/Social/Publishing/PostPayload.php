<?php

namespace App\Support\Social\Publishing;

use App\Support\Social\SocialMediaKind;

/**
 * One thing to send: some words, and at most one file.
 *
 * Deliberately not a list of files. Every network in the first wave takes a
 * single asset per post, and the ones that take several — a Facebook album, an
 * Instagram carousel — each do it differently enough that pretending otherwise
 * now would mean unpicking it later. One asset, honestly.
 */
final readonly class PostPayload
{
    public function __construct(
        public string $body = '',
        public ?string $filePath = null,
        public ?string $title = null,
    ) {}

    public function kind(): SocialMediaKind
    {
        if ($this->filePath === null) {
            return SocialMediaKind::Text;
        }

        return SocialMediaKind::forFile($this->filePath) ?? SocialMediaKind::Text;
    }

    public function bytes(): string
    {
        if ($this->filePath === null || ! is_file($this->filePath)) {
            throw new SocialPublishFailed('There is no file at '.($this->filePath ?? '(none)').'.');
        }

        return (string) file_get_contents($this->filePath);
    }

    public function mime(): string
    {
        return self::mimeForExtension(pathinfo((string) $this->filePath, PATHINFO_EXTENSION));
    }

    /**
     * The MIME type for a file extension.
     *
     * Static as well as instance, because MediaHandoff serves these bytes back
     * over HTTP later and has a handle rather than a payload. Meta reads the
     * type off the response headers, so the two answers have to be the same
     * answer - a second copy of this map is a second copy that drifts.
     */
    public static function mimeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            'mov'         => 'video/quicktime',
            'webm'        => 'video/webm',
            default       => 'video/mp4',
        };
    }
}
