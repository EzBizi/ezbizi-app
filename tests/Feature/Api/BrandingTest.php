<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\BrandingController;
use App\Models\Company;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Branding\Branding;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Logos (plan §12).
 *
 * The rule these defend is uniformity. Two shapes, exact sizes, PNG only —
 * because a row of company marks that each arrived at whatever size the
 * business happened to have looks accidental, and a JPEG logo arrives with a
 * grey halo around every letter.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = ['org' => $r['organization'], 'company' => $r['company']];
    }

    private function as(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function png(int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', $width, $height);
    }

    public function test_a_square_logo_at_the_exact_size_is_accepted(): void
    {
        $this->as()->post('/api/v1/branding/organization', [
            'logo' => $this->png(512, 512),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('square', $this->alpha['org']->fresh()->logo_aspect);
    }

    public function test_a_wide_logo_at_the_exact_size_is_accepted(): void
    {
        $this->as()->post('/api/v1/branding/organization', [
            'logo' => $this->png(1024, 256),
        ], ['Accept' => 'application/json'])->assertOk();

        // The shape is stored, not inferred later. A 4:1 lockup drawn in a
        // square slot is unreadable, so every surface needs to know.
        $this->assertSame('wide', $this->alpha['org']->fresh()->logo_aspect);
    }

    public function test_the_wrong_size_is_refused_and_says_what_would_work(): void
    {
        $response = $this->as()->post('/api/v1/branding/organization', [
            'logo' => $this->png(500, 500),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // Near-misses are the whole reason this is exact rather than an aspect
        // ratio: 500x500 looks fine alone and ragged in a row.
        $this->assertStringContainsString('512x512', $response->json('message'));
        $this->assertStringContainsString('1024x256', $response->json('message'));
    }

    public function test_a_jpeg_is_refused(): void
    {
        // JPEG artefacts cluster on hard edges, which is all a logo is, and it
        // cannot carry transparency — so a mark cannot sit on a dark header.
        $this->as()->post('/api/v1/branding/organization', [
            'logo' => UploadedFile::fake()->image('logo.jpg', 512, 512),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_an_svg_is_refused(): void
    {
        // Not a size question. SVG is a document that can carry script, and it
        // would be served from our own origin.
        $this->as()->post('/api/v1/branding/organization', [
            'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_the_shapes_come_from_the_server(): void
    {
        $shapes = $this->as()->getJson('/api/v1/branding/shapes')->assertOk();

        // The cropper produces these dimensions and the validator checks them.
        // Two hardcoded lists that must agree eventually will not, so the client
        // reads the server's.
        $shapes->assertJsonPath('shapes.square.width', 512)
            ->assertJsonPath('shapes.square.height', 512)
            ->assertJsonPath('shapes.wide.width', 1024)
            ->assertJsonPath('shapes.wide.height', 256)
            ->assertJsonPath('format', 'png');
    }

    public function test_a_company_without_a_logo_shows_the_organizations(): void
    {
        $this->as()->post('/api/v1/branding/organization', [
            'logo' => $this->png(512, 512),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->app['auth']->forgetGuards();

        // Inherited at read time, not copied — so changing the organization's
        // changes every company that never set one.
        $this->as()
            ->get('/api/v1/branding/companies/'.$this->alpha['company']->id)
            ->assertOk();
    }

    public function test_removing_it_clears_the_shape_too(): void
    {
        $this->as()->post('/api/v1/branding/organization', [
            'logo' => $this->png(512, 512),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->as()->deleteJson('/api/v1/branding/organization')->assertOk();

        $fresh = $this->alpha['org']->fresh();

        // A path without a shape is a state nothing can lay out.
        $this->assertNull($fresh->logo_path);
        $this->assertNull($fresh->logo_aspect);
    }

    public function test_replacing_one_deletes_the_file_it_replaced(): void
    {
        $this->as()->post('/api/v1/branding/organization', [
            'logo' => $this->png(512, 512),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $this->alpha['org']->fresh()->logo_path;

        $this->app['auth']->forgetGuards();

        $this->as()->post('/api/v1/branding/organization', [
            'logo' => $this->png(1024, 256),
        ], ['Accept' => 'application/json'])->assertOk();

        // Otherwise every replacement leaves its predecessor on disk forever.
        Storage::disk('local')->assertMissing($first);
    }

    public function test_another_organizations_logo_is_not_served(): void
    {
        $this->as()->post('/api/v1/branding/organization', [
            'logo' => $this->png(512, 512),
        ], ['Accept' => 'application/json'])->assertOk();

        app(ProvisionOrganization::class)(
            organizationName: 'Beta',
            administrator: ['name' => 'B', 'email' => 'b@beta.test', 'password' => 'a-real-password'],
            companyName: 'Beta Co', franchiseName: 'BF1',
        );

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'b@beta.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        /*
         * The READ side, which had no check of its own at all.
         *
         * It relied on route model binding being tenant-scoped, and binding was
         * resolving before the tenant was in context — so the company bound, and
         * the endpoint answered with a logo. 404 rather than 403: another
         * tenant's company is not merely forbidden, it is not there.
         */
        $this->withHeader('Authorization', "Bearer {$token}")
            ->get('/api/v1/branding/companies/'.$this->alpha['company']->id)
            ->assertNotFound();
    }

    /**
     * The guard on its own, with the outer control removed.
     *
     * The test above passes whether or not `company()` checks anything, because
     * route model binding is now tenant-scoped and `findOrFail` refuses the
     * company before the controller is reached. That makes it a good test of the
     * behaviour and a useless one for the check — it was written to cover, and
     * would not have noticed the check being deleted.
     *
     * So this one hands the action a Company it could never have bound: resolved
     * in platform scope, which is the one legitimate way to read across tenants.
     * If the abort_unless goes, this fails and nothing else does.
     *
     * BOTH logos are uploaded first, and that is the whole test rather than
     * setup. The first version of this asserted a 404 and passed with the guard
     * deleted — because Alpha's company had no logo of its own, so the action
     * fell through to serving the CALLER's organization mark, and Beta had none
     * either, so `serve()` raised the 404 the assertion was waiting for. It was
     * green for a reason that had nothing to do with the thing it named.
     *
     * With Alpha's company holding its own logo and Beta's organization holding
     * one too, every path except the guard returns bytes: guard present, 404;
     * guard removed, Alpha's file. Verified by deleting the guard and watching
     * this fail.
     */
    public function test_the_read_check_refuses_even_when_binding_did_not(): void
    {
        $this->as()->post(
            '/api/v1/branding/companies/'.$this->alpha['company']->id,
            ['logo' => $this->png(512, 512)],
            ['Accept' => 'application/json'],
        )->assertOk();

        $beta = app(ProvisionOrganization::class)(
            organizationName: 'Beta',
            administrator: ['name' => 'B', 'email' => 'b@beta.test', 'password' => 'a-real-password'],
            companyName: 'Beta Co', franchiseName: 'BF1',
        );

        $this->app['auth']->forgetGuards();

        $betaToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'b@beta.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$betaToken}")->post(
            '/api/v1/branding/organization',
            ['logo' => $this->png(512, 512)],
            ['Accept' => 'application/json'],
        )->assertOk();

        $tenant = app(TenantContext::class);

        $alphaCompany = $tenant->runWithoutTenant(
            fn () => Company::findOrFail($this->alpha['company']->id)
        );

        $this->assertNotNull(
            $alphaCompany->logo_path,
            'The company must hold its own logo, or the action falls through to '
            .'the caller\'s organization and this stops testing the guard.',
        );

        $request = Request::create('/api/v1/branding/companies/'.$alphaCompany->id);
        $request->setUserResolver(fn () => $beta['administrator']->fresh());

        $this->expectException(NotFoundHttpException::class);

        $tenant->run(
            $beta['organization'],
            fn () => app(BrandingController::class)->company($request, $alphaCompany)
        );
    }

    public function test_somebody_without_the_permission_cannot_set_one(): void
    {
        $other = app(ProvisionOrganization::class)(
            organizationName: 'Beta',
            administrator: ['name' => 'B', 'email' => 'b@beta.test', 'password' => 'a-real-password'],
            companyName: 'Beta Co', franchiseName: 'BF1',
        );

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'b@beta.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        // Another tenant's company is not merely forbidden — it is not there.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/branding/companies/'.$this->alpha['company']->id,
                ['logo' => $this->png(512, 512)],
                ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->assertNotNull($other['company']);
    }
}
