<?php

namespace Tests\Unit;

use App\Support\Totp\EnrolmentQr;
use PHPUnit\Framework\TestCase;

/**
 * The console QR (plan §4.2).
 *
 * Nothing here can prove a phone will read it — that needs a camera. What it can
 * prove is that the encoder is being driven correctly and the output is a
 * rectangle of the expected shape, which is what breaks when the library's API
 * shifts under a version bump.
 */
class EnrolmentQrTest extends TestCase
{
    private const URI = 'otpauth://totp/EzBizi:jose@ezbizi.com?secret=EAGELWQ7RPOUYL3EVRBCLW7T3OEMX7ZI'
        .'&issuer=EzBizi&algorithm=SHA1&digits=6&period=30';

    public function test_it_renders_a_rectangle(): void
    {
        $lines = explode(PHP_EOL, EnrolmentQr::terminal(self::URI));

        $this->assertGreaterThan(10, count($lines));

        // Every row must be the same width, or the code is skewed and unreadable.
        $widths = array_unique(array_map(
            fn (string $line) => mb_strlen(preg_replace('/\e\[[\d;]*m/', '', $line)),
            $lines,
        ));

        $this->assertCount(1, $widths, 'QR rows have differing widths.');
    }

    public function test_it_sets_its_own_colours(): void
    {
        $rendered = EnrolmentQr::terminal(self::URI);

        // A QR must be dark-on-light. Inheriting the terminal's theme produces an
        // inverted code on the roughly half of terminals that are dark, and most
        // phone cameras then silently refuse to read it.
        $this->assertStringContainsString("\e[30;40m", $rendered);   // dark over dark
        $this->assertStringContainsString("\e[97;107m", $rendered);  // light over light
        $this->assertStringContainsString("\e[0m", $rendered);       // and resets
    }

    public function test_the_quiet_zone_is_present(): void
    {
        $lines = explode(PHP_EOL, EnrolmentQr::terminal(self::URI));

        // Readers need clear space around the code. The first line sits entirely
        // inside it, so every cell must be light-over-light.
        $first = $lines[0];

        $this->assertSame(
            '',
            str_replace("\e[97;107m▀", '', rtrim($first, "\e[0m")),
            'The top quiet-zone row contains dark modules.'
        );
    }
}
