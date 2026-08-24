<?php

namespace Tests\Feature\Phone;

use App\Models\Company;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\ConnectionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Credential walk-up (spec §4): franchise → company → else org;
 * company → company → else org; org → org only. Never a sibling company.
 */
class ConnectionResolverTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_franchise_number_uses_the_company_connection_not_the_org(): void
    {
        $t = $this->provisionTenant();
        $this->inTenant($t['org'], function () use ($t) {
            $orgConn = PhoneConnection::factory()->create([
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'account_sid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ]);
            $coConn = PhoneConnection::factory()->create([
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'account_sid' => 'ACbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            ]);
            $number = PhoneNumber::factory()->create([
                'scope_type' => 'franchise',
                'scope_id' => $t['franchise']->id,
                'connection_id' => $coConn->id,
            ]);

            $resolved = app(ConnectionResolver::class)->forNumber($number);

            $this->assertSame($coConn->id, $resolved->id);
            $this->assertNotSame($orgConn->id, $resolved->id);
        });
    }

    public function test_a_franchise_number_falls_back_to_the_organization_when_the_company_has_no_connection(): void
    {
        $t = $this->provisionTenant();
        $this->inTenant($t['org'], function () use ($t) {
            $orgConn = PhoneConnection::factory()->create([
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'account_sid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ]);
            $number = PhoneNumber::factory()->create([
                'scope_type' => 'franchise',
                'scope_id' => $t['franchise']->id,
                'connection_id' => $orgConn->id,
            ]);

            $resolved = app(ConnectionResolver::class)->forNumber($number);

            $this->assertSame($orgConn->id, $resolved->id);
        });
    }

    public function test_georgia_does_not_receive_connecticuts_connection(): void
    {
        $t = $this->provisionTenant('East');
        $this->inTenant($t['org'], function () use ($t) {
            $connecticut = $t['company'];
            $georgia = Company::create([
                'organization_id' => $t['org']->id,
                'name' => 'Georgia',
            ]);

            $ctConn = PhoneConnection::factory()->create([
                'scope_type' => 'company',
                'scope_id' => $connecticut->id,
                'account_sid' => 'ACcccccccccccccccccccccccccccccccc',
            ]);
            $orgConn = PhoneConnection::factory()->create([
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'account_sid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ]);

            $gaNumber = PhoneNumber::factory()->create([
                'scope_type' => 'company',
                'scope_id' => $georgia->id,
                'connection_id' => $orgConn->id,
            ]);

            $resolved = app(ConnectionResolver::class)->forNumber($gaNumber);

            $this->assertNotNull($resolved);
            $this->assertSame($orgConn->id, $resolved->id);
            $this->assertNotSame($ctConn->id, $resolved->id);

            $byScope = app(ConnectionResolver::class)->forScope(
                $t['org']->id,
                PhoneNumber::SCOPE_COMPANY,
                $georgia->id,
            );
            $this->assertSame($orgConn->id, $byScope->id);
            $this->assertNotSame($ctConn->id, $byScope->id);
        });
    }

    public function test_an_organization_number_uses_only_the_organization_connection(): void
    {
        $t = $this->provisionTenant();
        $this->inTenant($t['org'], function () use ($t) {
            $orgConn = PhoneConnection::factory()->create([
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'account_sid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ]);
            $coConn = PhoneConnection::factory()->create([
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'account_sid' => 'ACbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            ]);
            $number = PhoneNumber::factory()->create([
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'connection_id' => $orgConn->id,
            ]);

            $resolved = app(ConnectionResolver::class)->forNumber($number);

            $this->assertSame($orgConn->id, $resolved->id);
            $this->assertNotSame($coConn->id, $resolved->id);
        });
    }
}
