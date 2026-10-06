<?php

namespace Tests\Feature\Api;

use App\Models\Mailbox;
use App\Support\Email\HtmlEmailSanitiser;
use App\Support\Email\RemotePictureFetcher;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An email's pictures are fetched together, and every one keeps its guards.
 *
 * Measured on production 2026-10-06: opening an HTML email fetched its
 * pictures one after another -- median 318 ms, worst 2.5 s, against 7 ms to
 * sanitise the HTML. fetchMany() pools them.
 *
 * The danger in pooling is losing a guard on the way. The existing
 * OutboundTransportSafetyTest proves the guards on the single path; this proves
 * them on EVERY slot of a pool, and that one bad picture fails alone.
 *
 * Literal public addresses throughout, as in the safety tests, so the host
 * guard approves without a DNS lookup.
 */
class EmailPicturesInParallelTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\nsmall";

    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $sent = [];

    /**
     * Answers like an image host: /big streams past the cap, /html is not a
     * picture, /redirect points at a private address, anything else is a PNG.
     */
    private function imageHost(): void
    {
        Http::globalMiddleware(function ($handler) {
            return function ($request, $options) {
                $url = (string) $request->getUri();
                $this->sent[] = ['url' => $url, 'options' => $options];

                if (str_contains($url, '/big')) {
                    for ($i = 0; $i < 100; $i++) {
                        $options['sink']->write(str_repeat('x', 65536));
                    }
                }

                if (str_contains($url, '/redirect')) {
                    return Create::promiseFor(new Response(302, ['Location' => 'http://169.254.169.254/latest']));
                }

                if (str_contains($url, '/html')) {
                    return Create::promiseFor(new Response(200, ['Content-Type' => 'text/html'], '<html>'));
                }

                $options['sink']->write(self::PNG);

                return Create::promiseFor(new Response(200, ['Content-Type' => 'image/png'], self::PNG));
            };
        });
    }

    public function test_every_pooled_request_carries_every_guard(): void
    {
        $this->imageHost();

        app(RemotePictureFetcher::class)->fetchMany([
            'https://93.184.216.34/a.png',
            'https://93.184.216.35/b.png',
            'https://93.184.216.36/c.png',
        ]);

        $this->assertCount(3, $this->sent);

        foreach ($this->sent as $one) {
            $host = parse_url($one['url'], PHP_URL_HOST);
            $this->assertArrayHasKey('sink', $one['options'], $one['url']);
            $this->assertIsCallable($one['options']['on_headers'] ?? null, $one['url']);
            $this->assertFalse($one['options']['allow_redirects'], $one['url']);
            $this->assertSame('', $one['options']['proxy'], $one['url']);
            $this->assertSame(["{$host}:443:{$host}"], $one['options']['curl'][CURLOPT_RESOLVE], $one['url']);
        }
    }

    /**
     * One bad picture fails ALONE, and the rest come back in the order given,
     * so each lands on its own <img>.
     */
    public function test_a_bad_picture_fails_alone_and_order_is_kept(): void
    {
        $this->imageHost();

        $got = app(RemotePictureFetcher::class)->fetchMany([
            'first'    => 'https://93.184.216.34/ok.png',
            'private'  => 'http://10.0.0.5/inside.png',      // refused before any request
            'oversize' => 'https://93.184.216.34/big',       // streams past the cap
            'notimage' => 'https://93.184.216.34/html',      // wrong type
            'redirect' => 'https://93.184.216.34/redirect',  // 302 to the metadata address
            'last'     => 'https://93.184.216.34/ok2.png',
        ]);

        $this->assertSame(['first', 'private', 'oversize', 'notimage', 'redirect', 'last'], array_keys($got));
        $this->assertSame(self::PNG, $got['first']?->bytes);
        $this->assertSame(self::PNG, $got['last']?->bytes);
        $this->assertNull($got['private']);
        $this->assertNull($got['oversize']);
        $this->assertNull($got['notimage']);
        $this->assertNull($got['redirect'], 'A redirect to a private address was followed.');

        // The private address was refused by the guard, so it never went out.
        $this->assertSame([], array_filter($this->sent, fn ($s) => str_contains($s['url'], '10.0.0.5')));
    }

    /**
     * The cap still counts pictures that LOADED, as the one-at-a-time loop
     * did: when early pictures fail, later ones get their chance.
     */
    public function test_the_cap_counts_pictures_that_loaded(): void
    {
        $this->imageHost();

        $imgs = '';
        for ($i = 0; $i < 5; $i++) {
            $imgs .= '<img src="https://93.184.216.34/html'.$i.'">';    // fail: not an image
        }
        for ($i = 0; $i < HtmlEmailSanitiser::MAX_IMAGES + 6; $i++) {
            $imgs .= '<img src="https://93.184.216.34/ok'.$i.'.png">';
        }

        $mailbox = new Mailbox(['address' => 'info@a-1chimney.com', 'provider' => 'gmail_oauth']);
        $html = app(HtmlEmailSanitiser::class)->prepare('<div>'.$imgs.'</div>', $mailbox, 'm-1');

        $this->assertSame(
            HtmlEmailSanitiser::MAX_IMAGES,
            substr_count($html, 'src="data:image/png;base64,'),
            'Failed pictures used up the cap instead of letting later ones load.',
        );
    }
}
