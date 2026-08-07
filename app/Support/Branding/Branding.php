<?php

namespace App\Support\Branding;

/**
 * The two shapes a logo may be, and their exact sizes (plan §12).
 *
 * ── One list, because two would drift ────────────────────────────
 *
 * The cropper produces these dimensions and the validator refuses anything
 * else. Those are the same numbers doing two jobs, so they are written once —
 * the client reads them from /branding/shapes rather than carrying its own copy.
 *
 * ── Why fixed sizes at all ───────────────────────────────────────
 *
 * Uniformity is the whole point. Left to whatever a business happens to have on
 * disk, one company's mark arrives 3000px wide and another's 64px, and every
 * screen showing them side by side looks accidental. Fixing the output means a
 * row of company logos lines up without anybody thinking about it.
 *
 * 512 for the square, so it still looks sharp on a retina screen at the largest
 * place it appears. 1024x256 for the lockup — 4:1 is the ratio most horizontal
 * marks already sit near, so cropping to it usually costs nothing.
 */
final class Branding
{
    public const SQUARE = 'square';
    public const WIDE   = 'wide';

    /** @var array<string, array{width: int, height: int, label: string, help: string}> */
    public const SHAPES = [
        self::SQUARE => [
            'width'  => 512,
            'height' => 512,
            'label'  => 'Square',
            'help'   => 'An icon, monogram or badge. Used wherever space is tight — '
                .'the top bar, lists, anywhere a mark stands alone.',
        ],
        self::WIDE => [
            'width'  => 1024,
            'height' => 256,
            'label'  => 'Wide',
            'help'   => 'A horizontal lockup with the name beside the mark. Used on '
                .'documents and headers, where there is room to read it.',
        ],
    ];

    /**
     * PNG only.
     *
     * A logo is line art and flat colour, which is exactly what PNG is for and
     * exactly what JPEG is worst at — its artefacts cluster on hard edges, so a
     * JPEG logo arrives with a grey halo around every letter. PNG also keeps
     * transparency, without which a mark cannot sit on a dark header.
     *
     * SVG is absent for a different reason: it is a document that can carry
     * script, and it would be served from our own origin.
     */
    public const MIME = 'png';

    /** The shape whose dimensions these are, or null if they match neither. */
    public static function shapeOf(int $width, int $height): ?string
    {
        foreach (self::SHAPES as $name => $shape) {
            if ($width === $shape['width'] && $height === $shape['height']) {
                return $name;
            }
        }

        return null;
    }

    /** "512x512 or 1024x256" — for a refusal that says what would work. */
    public static function expected(): string
    {
        return implode(' or ', array_map(
            fn (array $s) => "{$s['width']}x{$s['height']}",
            self::SHAPES,
        ));
    }
}
