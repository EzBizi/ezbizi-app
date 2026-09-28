<?php

namespace App\Support\Audio;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Audio every EzBizi player can open.
 *
 * Google's Local Services call recordings are MPEG-2.5 Layer III - the
 * low-rate "telephone" flavour of MP3 (8, 11.025 or 12 kHz). Android and the
 * web play it; Windows does not. The Windows app plays through Media
 * Foundation, whose MP3 decoder stops at MPEG-2, and every one of these calls
 * failed there with "MediaEngine error, Activate failed to create mediasink"
 * (Jose, 2026-09-28, on all four calls he tried).
 *
 * So MPEG-2.5 is converted to a plain 16-bit WAV, which every player opens.
 * Measured on production: a 10.4-minute call, 5.0 MB in, 13.8 MB out, 0.74 s.
 * Anything else passes through untouched, and so does MPEG-2.5 when ffmpeg is
 * missing or fails - the recording is then exactly what it was before, which
 * plays everywhere but Windows.
 *
 * ffmpeg is a SYSTEM package on the app box (installed 2026-09-28, apt
 * `ffmpeg`), not a Composer dependency.
 */
final class PlayableAudio
{
    /**
     * @return array{0: string, 1: string} the bytes and their content type
     */
    public static function forEveryPlayer(string $bytes, string $contentType): array
    {
        if (! self::isMpeg25($bytes)) {
            return [$bytes, $contentType];
        }

        $ffmpeg = (new ExecutableFinder)->find('ffmpeg');
        if ($ffmpeg === null) {
            return [$bytes, $contentType];
        }

        try {
            $process = new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error',
                '-i', 'pipe:0', '-f', 'wav', '-acodec', 'pcm_s16le', '-ac', '1', 'pipe:1']);
            $process->setInput($bytes);
            $process->setTimeout(60);
            $process->run();

            $wav = $process->getOutput();
            if ($process->isSuccessful() && str_starts_with($wav, 'RIFF') && strlen($wav) > 44) {
                return [$wav, 'audio/wav'];
            }
        } catch (Throwable $error) {
            report($error);
        }

        return [$bytes, $contentType];
    }

    /**
     * Is the first MPEG audio frame version 2.5?
     *
     * An MPEG frame starts with 11 set bits; the next two are the version, and
     * `00` is 2.5. So byte 2 of the header, masked with 0x18, is 0 for 2.5
     * (0x18 is MPEG-1, 0x10 MPEG-2). An ID3v2 tag in front is skipped by its
     * declared size.
     */
    public static function isMpeg25(string $bytes): bool
    {
        $offset = 0;
        if (str_starts_with($bytes, 'ID3') && strlen($bytes) >= 10) {
            // Syncsafe size: four bytes of seven bits each, plus the 10-byte header.
            $size = (ord($bytes[6]) << 21) | (ord($bytes[7]) << 14) | (ord($bytes[8]) << 7) | ord($bytes[9]);
            $offset = 10 + $size;
        }

        if (strlen($bytes) < $offset + 2) {
            return false;
        }

        $b1 = ord($bytes[$offset]);
        $b2 = ord($bytes[$offset + 1]);

        return $b1 === 0xFF && ($b2 & 0xE0) === 0xE0 && ($b2 & 0x18) === 0x00;
    }
}
