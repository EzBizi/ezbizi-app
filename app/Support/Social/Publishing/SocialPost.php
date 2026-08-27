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
final readonly class SocialPost
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
        return match (strtolower(pathinfo((string) $this->filePath, PATHINFO_EXTENSION))) {
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
