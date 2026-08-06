<?php

namespace App\Support\Totp;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * The enrolment QR, drawn in a terminal.
 *
 * The console is the only way to enrol the FIRST staff account — nobody is
 * signed in yet, so there is no UI to do it from. Typing a 32-character secret
 * by hand is where enrolment goes wrong, and a mistyped secret locks the account
 * out in a way that looks identical to a broken server.
 *
 * The client renders its own QR from the same otpauth:// URI (qr_flutter), so
 * there is one source of truth — the URI — and neither side re-derives it.
 */
class EnrolmentQr
{
    /** Four modules of quiet zone, per the QR spec. Readers need it. */
    private const QUIET = 4;

    /**
     * Two vertical modules per character cell, via the upper half block.
     *
     * Colours are set EXPLICITLY rather than relying on the terminal's own
     * foreground and background: a QR must be dark-on-light to scan, and half
     * the world's terminals are light-on-dark. Inheriting the theme produces an
     * inverted code that most phone cameras silently refuse.
     */
    public static function terminal(string $uri): string
    {
        $matrix = Encoder::encode($uri, ErrorCorrectionLevel::L(), Encoder::DEFAULT_BYTE_MODE_ECODING)
            ->getMatrix();

        $size  = $matrix->getWidth() + self::QUIET * 2;
        $lines = [];

        // Two rows of modules per line, hence the step of 2.
        for ($y = 0; $y < $size; $y += 2) {
            $line = '';

            for ($x = 0; $x < $size; $x++) {
                $upper = self::isDark($matrix, $x - self::QUIET, $y - self::QUIET);
                $lower = self::isDark($matrix, $x - self::QUIET, $y + 1 - self::QUIET);

                // '▀' paints its top half in the foreground colour and its
                // bottom half in the background colour.
                $line .= sprintf(
                    "\e[%d;%dm▀",
                    $upper ? 30 : 97,     // fg: black / bright white
                    $lower ? 40 : 107,    // bg: black / bright white
                );
            }

            $lines[] = $line."\e[0m";
        }

        return implode(PHP_EOL, $lines);
    }

    /** Anything outside the matrix is quiet zone, which is light. */
    private static function isDark(object $matrix, int $x, int $y): bool
    {
        if ($x < 0 || $y < 0 || $x >= $matrix->getWidth() || $y >= $matrix->getHeight()) {
            return false;
        }

        return $matrix->get($x, $y) === 1;
    }
}
