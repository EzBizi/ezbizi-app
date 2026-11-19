<?php

namespace Tests\Unit;

use App\Support\Marketing\QrImage;
use BaconQrCode\Encoder\Encoder;
use PHPUnit\Framework\TestCase;

/**
 * The centre picture leaves the code readable (Bar, 01a1f4ba).
 *
 * STRUCTURAL CHECK, NOT A DECODE: no QR reader is a dependency here. What this
 * proves, on the real module matrix of real-length addresses, is that the white
 * pad (a) covers no function module at all — finder, separator, timing,
 * alignment, format or version — and (b) damages, in every Reed-Solomon block,
 * no more codewords than error correction H can correct as unknown errors
 * (ec/2 per block). A decoder would correct those; whether a given camera
 * does is a field question.
 */
class QrImageTest extends TestCase
{
    /** Real codes are APP_URL/q/XXXXXXX; the longer ones push the version up. */
    public static function addresses(): array
    {
        return [
            'production' => ['https://app.ezbizi.com/q/ABCDEFG'],
            'longer host' => ['https://staging.app.ezbizi.com/q/ABCDEFG'],
            'much longer' => ['https://a-very-long-subdomain.example-ezbizi-host.com/q/ABCDEFG/xyz'],
        ];
    }

    /** @dataProvider addresses */
    public function test_the_pad_spares_every_function_module_and_stays_within_h(string $url): void
    {
        $code = Encoder::encode($url, QrImage::level(), 'UTF-8');
        $version = $code->getVersion();
        $n = $code->getMatrix()->getWidth();
        $function = $version->buildFunctionPattern();

        // Every module the pad touches at all, partly covered included.
        $from = $n / 2 - QrImage::PAD_RATIO * $n / 2;
        $to = $n / 2 + QrImage::PAD_RATIO * $n / 2;
        $first = (int) floor($from);
        $last = (int) ceil($to) - 1;
        $covered = [];
        for ($y = $first; $y <= $last; $y++) {
            for ($x = $first; $x <= $last; $x++) {
                $this->assertFalse($function->get($x, $y), "Module ($x,$y) of a {$n}-module code is a function pattern under the pad.");
                $covered["$x,$y"] = true;
            }
        }
        $this->assertLessThanOrEqual(0.05, (QrImage::PAD_RATIO * $n) ** 2 / ($n * $n), 'The pad is at most 5% of the code.');

        // Which codeword each data module carries: the standard zig-zag order.
        $codewordOf = [];
        $bit = 0;
        $up = true;
        for ($j = $n - 1; $j > 0; $j -= 2) {
            if ($j === 6) {
                $j--;
            }
            for ($c = 0; $c < $n; $c++) {
                $y = $up ? $n - 1 - $c : $c;
                for ($col = 0; $col < 2; $col++) {
                    $x = $j - $col;
                    if (! $function->get($x, $y)) {
                        $codewordOf["$x,$y"] = intdiv($bit++, 8);
                    }
                }
            }
            $up = ! $up;
        }

        // Which block each interleaved codeword belongs to.
        $ecBlocks = $version->getEcBlocksForLevel(QrImage::level());
        $dataSizes = [];
        foreach ($ecBlocks->getEcBlocks() as $group) {
            for ($i = 0; $i < $group->getCount(); $i++) {
                $dataSizes[] = $group->getDataCodewords();
            }
        }
        $blocks = count($dataSizes);
        $blockOf = [];
        for ($r = 0; $r < max($dataSizes); $r++) {
            foreach ($dataSizes as $b => $size) {
                if ($r < $size) {
                    $blockOf[] = $b;
                }
            }
        }
        $ec = $ecBlocks->getEcCodewordsPerBlock();
        for ($i = 0; $i < $ec * $blocks; $i++) {
            $blockOf[] = $i % $blocks;
        }

        $damaged = array_fill(0, $blocks, []);
        foreach (array_keys($covered) as $module) {
            $cw = $codewordOf[$module] ?? null;
            if ($cw !== null && $cw < count($blockOf)) {
                $damaged[$blockOf[$cw]][$cw] = true;
            }
        }
        foreach ($damaged as $b => $words) {
            $this->assertLessThanOrEqual(intdiv($ec, 2), count($words),
                "Block $b of version {$version->getVersionNumber()} loses ".count($words)." codewords; H corrects ".intdiv($ec, 2).'.');
        }
    }

    public function test_a_picture_is_drawn_on_a_white_pad_in_the_middle(): void
    {
        $url = 'https://app.ezbizi.com/q/ABCDEFG';
        $red = imagecreatetruecolor(300, 100);
        imagefill($red, 0, 0, imagecolorallocate($red, 255, 0, 0));
        ob_start();
        imagepng($red);
        $picture = (string) ob_get_clean();

        $img = imagecreatefromstring(QrImage::png($url, $picture));
        $this->assertSame([255, 0, 0], $this->rgb($img, 1000, 1000), 'The picture is in the centre.');
        [$x, $y] = QrImage::pad($url, 2000);
        $this->assertSame([255, 255, 255], $this->rgb($img, (int) $x + 3, (int) $y + 3), 'A white pad surrounds it.');
        $this->assertSame([0, 0, 0], $this->rgb($img, 2000 / 41 * 4 + 5, 2000 / 41 * 4 + 5), 'The finder corner is untouched.');

        $plain = imagecreatefromstring(QrImage::png($url, null));
        $this->assertNotSame([255, 0, 0], $this->rgb($plain, 1000, 1000));

        $svg = QrImage::svg($url, $picture);
        $this->assertStringContainsString('<image ', $svg);
        $this->assertStringContainsString('data:image/png;base64,', $svg);
        $this->assertStringNotContainsString('<image ', QrImage::svg($url, null));
        $this->assertStringNotContainsString('<image ', QrImage::svg($url, 'not a picture'));
    }

    /** @return array{int, int, int} */
    private function rgb(\GdImage $img, int|float $x, int|float $y): array
    {
        $c = imagecolorat($img, (int) $x, (int) $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }
}
