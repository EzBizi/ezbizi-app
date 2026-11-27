<?php

namespace App\Support\Marketing;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A printable QR code, optionally with a picture in the middle (Bar, 01a1f4ba).
 *
 * Always error correction H: it recovers about 30% of the code, and the
 * picture's white pad hides at most {@see PAD_RATIO}² ≈ 4.8% of it, leaving
 * the rest for the dirt and scratches a code on a van collects.
 *
 * The pad is a square {@see PAD_RATIO} of the symbol's width, centred; the
 * picture fits inside it with a margin. Centred and that small, it never
 * reaches a finder pattern (the corners) or a timing line (row/column 6).
 * QrImageTest proves both on the real module matrix.
 */
final class QrImage
{
    /** Width of the white pad, as a share of the symbol (quiet zone excluded). */
    public const PAD_RATIO = 0.22;

    /** The picture's share of the pad; the rest is white margin around it. */
    private const PICTURE_IN_PAD = 0.82;

    /** Quiet zone, in modules, around the symbol (both renderers). */
    private const MARGIN = 4;

    public static function level(): ErrorCorrectionLevel
    {
        return ErrorCorrectionLevel::H();
    }

    /** Modules on a side of the symbol $text encodes to. */
    public static function modules(string $text): int
    {
        return Encoder::encode($text, self::level(), 'UTF-8')->getMatrix()->getWidth();
    }

    /**
     * The modules the pad touches, partly covered included: [first, last] on
     * both axes. A long address (version 7+) puts an alignment pattern in the
     * middle; then {@see fits()} is false and no picture is drawn.
     *
     * @return array{int, int}
     */
    public static function covered(int $n): array
    {
        return [(int) floor($n / 2 - self::PAD_RATIO * $n / 2), (int) ceil($n / 2 + self::PAD_RATIO * $n / 2) - 1];
    }

    /** Whether a picture can sit in the middle without hiding a function pattern. */
    public static function fits(string $text): bool
    {
        $code = Encoder::encode($text, self::level(), 'UTF-8');
        $function = $code->getVersion()->buildFunctionPattern();
        [$first, $last] = self::covered($code->getMatrix()->getWidth());
        for ($y = $first; $y <= $last; $y++) {
            for ($x = $first; $x <= $last; $x++) {
                if ($function->get($x, $y)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** The pad in pixels for an image $size wide: [x, y, side]. */
    public static function pad(string $text, int $size): array
    {
        $n = self::modules($text);
        $module = $size / ($n + 2 * self::MARGIN);
        $side = self::PAD_RATIO * $n * $module;
        $at = ($size - $side) / 2;

        return [$at, $at, $side];
    }

    /** 2000px PNG. $picture is raw image bytes (PNG/JPEG/GIF/WebP) or null. */
    public static function png(string $text, ?string $picture): string
    {
        $size = 2000;
        $bytes = (new Writer(new GDLibRenderer($size, self::MARGIN)))->writeString($text, 'UTF-8', self::level());
        $logo = $picture === null || ! self::fits($text) ? false : @imagecreatefromstring($picture);
        if ($logo === false) {
            return $bytes;
        }

        $img = imagecreatefromstring($bytes);
        imagealphablending($img, true);
        [$x, $y, $side] = self::pad($text, $size);
        imagefilledrectangle($img, (int) floor($x), (int) floor($y), (int) ceil($x + $side), (int) ceil($y + $side),
            imagecolorallocate($img, 255, 255, 255));

        [$w, $h] = self::fit(imagesx($logo), imagesy($logo), $side * self::PICTURE_IN_PAD);
        imagecopyresampled($img, $logo,
            (int) round($x + ($side - $w) / 2), (int) round($y + ($side - $h) / 2), 0, 0,
            (int) round($w), (int) round($h), imagesx($logo), imagesy($logo));

        ob_start();
        imagepng($img, null, 9);

        return (string) ob_get_clean();
    }

    /** 1000px vector SVG; the picture is embedded, so the file stands alone. */
    public static function svg(string $text, ?string $picture): string
    {
        $size = 1000;
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, self::MARGIN), new SvgImageBackEnd)))
            ->writeString($text, 'UTF-8', self::level());
        $info = $picture === null || ! self::fits($text) ? false : @getimagesizefromstring($picture);
        if ($info === false || ! in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return $svg;
        }

        [$x, $y, $side] = self::pad($text, $size);
        [$w, $h] = self::fit($info[0], $info[1], $side * self::PICTURE_IN_PAD);
        $overlay = sprintf('<rect x="%.2F" y="%.2F" width="%.2F" height="%.2F" fill="#ffffff"/>', $x, $y, $side, $side)
            .sprintf('<image x="%.2F" y="%.2F" width="%.2F" height="%.2F" preserveAspectRatio="xMidYMid meet" href="data:%s;base64,%s"/>',
                $x + ($side - $w) / 2, $y + ($side - $h) / 2, $w, $h, $info['mime'], base64_encode($picture));

        return (string) preg_replace('~</svg>\s*$~', $overlay.'</svg>', $svg);
    }

    /** @return array{0: float, 1: float} $w x $h scaled to fit a $box square. */
    private static function fit(int $w, int $h, float $box): array
    {
        $scale = $box / max($w, $h, 1);

        return [$w * $scale, $h * $scale];
    }
}
