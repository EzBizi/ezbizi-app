<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackWebhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The endpoint Thumbtack posts leads to, and the one header that guards it.
 *
 * Thumbtack does not sign these callbacks. The whole authentication is a
 * credential we chose, gave them at registration, and check on the way back in
 * — so the body is a claim and never a fact. Anybody on the internet can POST
 * here, which is why most of what follows is about who gets turned away.
 */
class ThumbtackWebhookTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const URL = '/api/v1/social/thumbtack/webhook';

    public function test_a_caller_with_the_right_secret_is_accepted(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, secret: 'the-secret');

        $this->withHeader(ThumbtackWebhooks::AUTH_HEADER, 'the-secret')
            ->postJson(self::URL, [
                'eventType'  => 'NegotiationCreatedV4',
                'businessID' => 'biz-1',
            ])
            ->assertOk();
    }

    public function test_a_caller_with_no_secret_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, secret: 'the-secret');

        $this->postJson(self::URL, [
            'eventType'  => 'NegotiationCreatedV4',
            'businessID' => 'biz-1',
        ])->assertUnauthorized();
    }

    public function test_a_caller_with_the_wrong_secret_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, secret: 'the-secret');

        $this->withHeader(ThumbtackWebhooks::AUTH_HEADER, 'not-the-secret')
            ->postJson(self::URL, [
                'eventType'  => 'NegotiationCreatedV4',
                'businessID' => 'biz-1',
            ])->assertUnauthorized();
    }

    /**
     * The one that matters most.
     *
     * The body names the business, so a caller could nominate whichever tenant
     * it liked. The secret is therefore checked against THAT connection's own
     * secret — one tenant's leaked credential must not open another's.
     */
    public function test_one_tenants_secret_does_not_open_another_tenants_business(): void
    {
        $alpha = $this->provisionTenant('A1-Group');
        $beta  = $this->provisionTenant('Premier');

        $this->connection($alpha, remoteId: 'biz-alpha', secret: 'alpha-secret');
        $this->connection($beta, remoteId: 'biz-beta', secret: 'beta-secret');

        $this->withHeader(ThumbtackWebhooks::AUTH_HEADER, 'alpha-secret')
            ->postJson(self::URL, [
                'eventType'  => 'NegotiationCreatedV4',
                'businessID' => 'biz-beta',
            ])->assertUnauthorized();
    }

    /**
     * A business we do not have gets 200, not 404.
     *
     * Answering an error would make Thumbtack retry something that can never
     * succeed and eventually disable the webhook — which stops delivery of
     * every event, including the ones that do work. It also declines to tell an
     * unauthenticated caller which business ids exist.
     */
    public function test_an_unknown_business_is_accepted_and_ignored_rather_than_refused(): void
    {
        $this->provisionTenant('A1-Group');

        $this->withHeader(ThumbtackWebhooks::AUTH_HEADER, 'anything')
            ->postJson(self::URL, [
                'eventType'  => 'NegotiationCreatedV4',
                'businessID' => 'nobody-here',
            ])
            ->assertOk()
            ->assertJsonPath('data.handled', false);
    }

    public function test_the_business_id_is_found_wherever_thumbtack_nests_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, secret: 'the-secret');

        // Their payloads differ per event type; an id we cannot find is an
        // event we cannot authenticate, so all the documented shapes are read.
        foreach ([
            ['businessID' => 'biz-1'],
            ['business' => ['businessID' => 'biz-1']],
            ['data' => ['businessID' => 'biz-1']],
            ['negotiation' => ['business' => ['businessID' => 'biz-1']]],
        ] as $shape) {
            $this->withHeader(ThumbtackWebhooks::AUTH_HEADER, 'the-secret')
                ->postJson(self::URL, ['eventType' => 'MessageCreatedV4'] + $shape)
                ->assertOk();
        }
    }

    // ── registration ─────────────────────────────────────────────

    public function test_registering_asks_for_the_events_we_handle_and_no_others(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t);

        Http::fake([
            '*/webhooks' => Http::sequence()
                ->push(['data' => []])
                ->push(['webhookID' => 'wh-1']),
        ]);

        // Inside the tenant, because registering stores the secret on the
        // connection and writing tenant data outside a context is refused.
        $this->inTenant($t['org'], fn () => ThumbtackWebhooks::for($connection)
            ->ensure('https://app.ezbizi.com/api/v1/social/thumbtack/webhook'));

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            // The preset-job events belong to a Thumbtack product EzBizi does
            // not implement. Subscribing would mean a callback doing real work
            // to reach a branch that drops it.
            return $body['eventTypes'] === ['NegotiationCreatedV4', 'MessageCreatedV4', 'ReviewCreatedV4']
                && $body['enabled'] === true
                && $body['auth']['header'] === ThumbtackWebhooks::AUTH_HEADER
                && strlen((string) $body['auth']['value']) === 64;
        });
    }

    public function test_registering_twice_repairs_the_existing_webhook_instead_of_making_a_second(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t);
        $url        = 'https://app.ezbizi.com/api/v1/social/thumbtack/webhook';

        Http::fake([
            '*/webhooks/wh-1/auth' => Http::response(['authType' => 'CustomHeader']),
            '*/webhooks/wh-1'      => Http::response(['webhookID' => 'wh-1']),
            '*/webhooks'           => Http::response(['data' => [
                ['webhookID' => 'wh-1', 'webhookURL' => $url, 'enabled' => false],
            ]]),
        ]);

        $result = $this->inTenant(
            $t['org'],
            fn () => ThumbtackWebhooks::for($connection)->ensure($url),
        );

        $this->assertTrue($result['reused']);

        // A duplicate webhook delivers every lead twice and nothing upstream
        // dedupes them, so the second registration must repair rather than add.
        Http::assertNotSent(
            fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/webhooks')
        );
        Http::assertSent(
            fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/webhooks/wh-1')
        );
    }

    public function test_the_stored_secret_is_the_one_thumbtack_was_given(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t);

        Http::fake([
            '*/webhooks' => Http::sequence()
                ->push(['data' => []])
                ->push(['webhookID' => 'wh-1']),
        ]);

        $webhooks = ThumbtackWebhooks::for($connection);
        $this->inTenant(
            $t['org'],
            fn () => $webhooks->ensure('https://app.ezbizi.com/api/v1/social/thumbtack/webhook'),
        );

        $sent = null;

        Http::assertSent(function ($request) use (&$sent) {
            if ($request->method() === 'POST' && isset($request->data()['auth'])) {
                $sent = $request->data()['auth']['value'];
            }

            return true;
        });

        // If these ever diverge every callback is refused and no lead arrives,
        // with nothing in either system saying why.
        $this->assertNotNull($sent);
        $this->assertTrue($webhooks->accepts($sent));
        $this->assertFalse($webhooks->accepts('something-else'));
    }

    private function connection(
        array $t,
        string $remoteId = 'biz-1',
        ?string $secret = null,
    ): SocialConnection {
        return $this->inTenant($t['org'], function () use ($t, $remoteId, $secret) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->getKey(),
                'provider'        => SocialProvider::Thumbtack->value,
                'remote_id'       => $remoteId,
                'display_name'    => 'A-1 Chimney on Thumbtack',
            ]);
            $row->connect(array_filter([
                'access_token'  => 'a-token',
                'refresh_token' => 'r-token',
                ThumbtackWebhooks::SECRET_KEY => $secret,
            ]));
            $row->save();

            return $row;
        });
    }
}
