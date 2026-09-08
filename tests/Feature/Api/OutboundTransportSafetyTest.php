<?php

namespace Tests\Feature\Api;

use App\Support\Automations\OutboundWebhook;
use App\Support\Email\PictureUnavailable;
use App\Support\Email\RemotePictureFetcher;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundTransportSafetyTest extends TestCase
{
    public function test_webhook_pins_approved_dns_without_changing_tls_hostname(): void
    {
        $captured = null;
        Http::globalMiddleware(function ($handler) use (&$captured) {
            return function ($request, $options) use (&$captured) {
                $captured = [$request, $options];

                return Create::promiseFor(new Response(200, [], 'ok'));
            };
        });
        $webhook = new class extends OutboundWebhook
        {
            public int $lookups = 0;

            protected function resolve(string $host): array
            {
                return ++$this->lookups === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
            }
        };
        self::assertTrue($webhook->send('https://webhook.example.test/hook', [])['ok']);
        self::assertSame(1, $webhook->lookups);
        self::assertSame('webhook.example.test', $captured[0]->getUri()->getHost());
        self::assertSame(['webhook.example.test:443:93.184.216.34'], $captured[1]['curl'][CURLOPT_RESOLVE]);
        self::assertTrue($captured[1]['verify']);
        self::assertSame('', $captured[1]['proxy']);
    }

    public function test_picture_aborts_while_chunked_bytes_are_arriving(): void
    {
        $written = 0;
        Http::globalMiddleware(function ($handler) use (&$written) {
            return function ($request, $options) use (&$written) {
                self::assertArrayHasKey('sink', $options, 'The transfer must have a bounded sink.');
                // This is the decoded byte stream delivered by the transport, with no length header.
                for ($i = 0; $i < 100; $i++) {
                    $options['sink']->write(str_repeat('x', 65536));
                    $written += 65536;
                }

                return Create::promiseFor(new Response(200, ['Content-Type' => 'image/png']));
            };
        });
        try {
            app(RemotePictureFetcher::class)->fetch('https://93.184.216.34/image');
            self::fail('Oversized response was accepted.');
        } catch (PictureUnavailable) {
            self::assertSame(RemotePictureFetcher::MAX_BYTES, $written);
        }
    }

    public function test_picture_accepts_small_streamed_response(): void
    {
        Http::globalMiddleware(fn ($handler) => function ($request, $options) {
            if (isset($options['sink'])) {
                $options['sink']->write('small image');
            }

            return Create::promiseFor(new Response(200, ['Content-Type' => 'image/png'], 'small image'));
        });
        self::assertSame('small image', app(RemotePictureFetcher::class)->fetch('https://93.184.216.34/image')->bytes);
    }

    public function test_picture_rejects_oversized_declared_length_before_body(): void
    {
        Http::globalMiddleware(fn ($handler) => function ($request, $options) {
            $response = new Response(200, ['Content-Length' => RemotePictureFetcher::MAX_BYTES + 1]);
            $options['on_headers']($response);
            self::fail('Oversized Content-Length was accepted.');
        });
        $this->expectException(PictureUnavailable::class);
        app(RemotePictureFetcher::class)->fetch('https://93.184.216.34/image');
    }
}
