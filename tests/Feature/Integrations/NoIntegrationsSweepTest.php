<?php

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Router;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Every integration is a removable add-on; EzBizi is the primary (Jose,
 * 2026-10-27: "check every single possible part of the app, all the
 * integrations"). With nothing connected — no social_connections, no
 * Workspace, no phone account, every integration key in config null — an
 * administrator of a freshly provisioned tenant opens every module screen's
 * read endpoints, and none answers 500.
 *
 * The list is derived from the router, not written down, so a new screen is
 * swept the day it lands: every GET in the authenticated v1 group with no
 * route parameter. Outbound HTTP is refused outright, so a screen that calls a
 * provider with no account connected fails here rather than in production.
 */
class NoIntegrationsSweepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Not screens: they stream files, redirect to a provider's consent page,
     * or wait on a long poll. They are skipped, not ignored — listed here.
     */
    private const SKIP = [];

    public function test_no_module_read_endpoint_answers_500_with_no_integrations(): void
    {
        $this->nullIntegrationConfig();
        Http::preventStrayRequests();

        $t = $this->provisionTenant('Alpha');
        $failures = [];
        $swept = 0;

        foreach ($this->readRoutes() as $uri) {
            $swept++;
            $status = $this->signedInAs($t['admin'])->getJson('/'.$uri)->getStatusCode();

            if ($status >= 500) {
                $failures[] = "{$status} GET /{$uri}";
            }
        }

        $this->assertGreaterThan(100, $swept, 'the sweep found too few routes to mean anything');
        $this->assertSame([], $failures, "With no integrations connected these answered 5xx:\n".implode("\n", $failures));
    }

    /** @return list<string> */
    private function readRoutes(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true)
                && str_starts_with($r->uri(), 'api/v1/')
                && ! str_contains($r->uri(), '{')
                && in_array('auth:sanctum', $r->gatherMiddleware(), true)
                && in_array('tenant', $r->gatherMiddleware(), true)
                && ! in_array($r->uri(), self::SKIP, true))
            ->map(fn (Route $r) => $r->uri())
            ->unique()->sort()->values()->all();
    }

    /** Every leaf of the integration config files null: no keys, no tokens, no endpoints. */
    private function nullIntegrationConfig(): void
    {
        $null = function (array $node) use (&$null): array {
            foreach ($node as $k => $v) {
                $node[$k] = is_array($v) ? $null($v) : null;
            }

            return $node;
        };

        foreach (['services', 'experimental_reports'] as $file) {
            config([$file => $null((array) config($file))]);
        }
    }
}
