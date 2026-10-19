<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

/**
 * Whether a visitor's browser is allowed to call the widget endpoints at all.
 *
 * ── What this is really testing, and why nothing caught it ──
 *
 * Jose filled in the chat on a-1chimney.com and got the widget's own "that did
 * not send" message. Every test passed, because every test posts from PHP, and
 * PHP does not ask permission first. A browser does: a POST carrying JSON, or a
 * GET carrying an Authorization header, is preflighted with OPTIONS, and the
 * answer to THAT decided whether the request we had so carefully tested was
 * ever sent. It was not.
 *
 * So these assert the half of the exchange no feature test can see: the
 * preflight, which matches no route of ours and reaches no controller.
 */
class WebWidgetOriginsTest extends TestCase
{
    /** What a browser on a customer's website actually sends first. */
    private function preflight(string $path, string $method = 'POST')
    {
        return $this->call('OPTIONS', $path, [], [], [], [
            'HTTP_ORIGIN' => 'https://www.a-1chimney.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization',
        ]);
    }

    public function test_a_stranger_may_ask_before_starting_a_chat(): void
    {
        $response = $this->preflight('/api/v1/web/chat');

        $response->assertNoContent();
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));

        // The two headers that caused the preflight. Without them named here
        // the browser refuses even though the origin was allowed.
        $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('content-type', $allowed);
        $this->assertStringContainsString('authorization', $allowed);
    }

    public function test_the_polling_and_form_endpoints_answer_too(): void
    {
        foreach ([
            ['/api/v1/web/chat/01931234-5678-7abc-8def-000000000000/messages', 'GET'],
            ['/api/v1/web/chat/01931234-5678-7abc-8def-000000000000/messages', 'POST'],
            ['/api/v1/web/form', 'POST'],
            ['/api/v1/web/review', 'POST'],
            // The review page's open/face/Google tracking: refused until
            // 2026-10-19, so Review links counted sends and nothing else.
            ['/api/v1/web/review-link', 'POST'],
            ['/api/v1/web/membership/join', 'POST'],
            ['/api/v1/web/chat/01931234-5678-7abc-8def-000000000000/typing', 'POST'],
            ['/api/v1/web/booking', 'POST'],
            ['/api/v1/web/booking/days', 'GET'],
        ] as [$path, $method]) {
            $this->assertSame(
                '*',
                $this->preflight($path, $method)->headers->get('Access-Control-Allow-Origin'),
                $method.' '.$path.' is not reachable from a customer website.',
            );
        }
    }

    /**
     * THE one that matters. `*` on these four is safe because nobody is signed
     * in; the same header on a route behind a bearer token would let any page
     * that obtained one read the answer.
     *
     * This is the control as well: if the middleware were matching everything,
     * the assertions above would pass for the wrong reason.
     */
    public function test_nothing_else_answers_to_any_origin(): void
    {
        foreach ([
            '/api/v1/web/sites',
            '/api/v1/web/plugin/values',
            '/api/v1/leads',
        ] as $path) {
            $this->assertNotSame(
                '*',
                $this->preflight($path, 'GET')->headers->get('Access-Control-Allow-Origin'),
                $path.' answers to any origin, and must not.',
            );
        }
    }
}
