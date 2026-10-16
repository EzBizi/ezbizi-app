<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Data\ResourceMap;
use App\Support\Modules\ModuleRegistry;
use App\Support\Subscriptions\SubscriptionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Import and export (plan §12, §9, §11.3).
 *
 * §11.3 puts "import validation & partial failure" on the list of things that
 * must be tested because it is "the first thing a new client touches", and
 * export on it because "legal exposure if it doesn't do what it claims".
 */
class DataTest extends TestCase
{
    // Added when the "Import & export" tab was removed: the tests at the bottom
    // of this file need somebody who is NOT an administrator, because an
    // administrator holds the whole catalogue and so cannot demonstrate a
    // refusal — or that a permission is genuinely gone.
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;
    private array $beta;

    protected function setUp(): void
    {
        parent::setUp();

        SubscriptionGuard::flush();

        $this->alpha = $this->provision('Alpha', 'a@alpha.test');
        $this->beta  = $this->provision('Beta', 'b@beta.test');
    }

    private function provision(string $name, string $email): array
    {
        $r = app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: ['name' => 'Admin', 'email' => $email, 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        return [
            'organization' => $r['organization'],
            'admin' => $r['administrator'],
            'franchise' => $r['franchise'],
            'email' => $email,
        ];
    }

    private function as(array $org): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $org['email'], 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function makeCustomer(array $org, array $attributes = []): Customer
    {
        return app(TenantContext::class)->run(
            $org['organization'],
            fn () => Customer::create($attributes + [
                'owning_franchise_id' => $org['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => 'Existing Person',
            ]),
        );
    }

    private function csv(array $rows, array $header = ['name', 'email']): string
    {
        return implode("\n", array_merge(
            [implode(',', $header)],
            array_map(fn ($r) => implode(',', $r), $rows),
        ));
    }

    private function import(array $org, string $csv, array $extra = [])
    {
        return $this->as($org)->postJson('/api/v1/imports/customers', $extra + [
            'csv' => $csv, 'franchise_id' => $org['franchise']->id,
        ]);
    }

    // ── export ───────────────────────────────────────────────────

    public function test_exporting_returns_only_this_organizations_rows(): void
    {
        $this->makeCustomer($this->alpha, ['name' => 'Alpha Person']);
        $this->makeCustomer($this->beta, ['name' => 'Beta Person']);

        $body = $this->as($this->alpha)->get('/api/v1/exports/customers')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Alpha Person', $body);

        // The whole point of a tenant boundary, tested where it would be most
        // damaging to lose it: one file, handed to someone, containing another
        // company's customer list.
        $this->assertStringNotContainsString('Beta Person', $body);
    }

    public function test_a_formula_in_a_name_is_defused(): void
    {
        $this->makeCustomer($this->alpha, ['name' => '=HYPERLINK("http://evil","Click")']);

        $body = $this->as($this->alpha)->get('/api/v1/exports/customers')
            ->assertOk()->streamedContent();

        // Excel and Sheets execute a cell beginning with =, so an export is a
        // way to attack whoever opens it — with data that arrived through an
        // ordinary form. The apostrophe is what stops it running.
        $this->assertStringContainsString("'=HYPERLINK", $body);
    }

    public function test_a_negative_number_is_not_mangled(): void
    {
        $this->makeCustomer($this->alpha, ['name' => 'Person', 'postal_code' => '-1234']);

        $body = $this->as($this->alpha)->get('/api/v1/exports/customers')
            ->assertOk()->streamedContent();

        // The lazy version of the defence quotes anything starting with a dash,
        // which turns every negative amount in the system into text.
        $this->assertStringNotContainsString("'-1234", $body);
        $this->assertStringContainsString('-1234', $body);
    }

    public function test_export_still_works_after_the_subscription_lapses(): void
    {
        $this->makeCustomer($this->alpha, ['name' => 'Alpha Person']);

        $this->alpha['organization']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        SubscriptionGuard::flush();

        // §13: "Exporting their data — legally expected, and refusing looks like
        // hostage-taking."
        $this->as($this->alpha)->get('/api/v1/exports/customers')->assertOk();
    }

    public function test_a_person_can_be_exported_on_their_own(): void
    {
        $customer = $this->makeCustomer($this->alpha, ['name' => 'Alpha Person']);

        $this->as($this->alpha)->getJson("/api/v1/customers/{$customer->id}/export")
            ->assertOk()
            ->assertJsonPath('customer.name', 'Alpha Person')
            ->assertJsonStructure(['customer', 'jobs']);
    }

    public function test_you_cannot_export_another_organizations_person(): void
    {
        $theirs = $this->makeCustomer($this->beta, ['name' => 'Beta Person']);

        $this->as($this->alpha)->getJson("/api/v1/customers/{$theirs->id}/export")
            ->assertNotFound();
    }

    public function test_an_export_is_recorded(): void
    {
        $this->as($this->alpha)->get('/api/v1/exports/customers')->assertOk();

        // A bulk export is the most useful thing to steal from this system. If
        // it isn't in the log, nobody can answer "what left, and when" (§10).
        $this->assertDatabaseHas('audit_events', ['action' => 'data.exported']);
    }

    // ── import ───────────────────────────────────────────────────

    public function test_a_good_file_imports(): void
    {
        $this->import($this->alpha, $this->csv([
            ['Ada Lovelace', 'ada@example.test'],
            ['Alan Turing', 'alan@example.test'],
        ]))->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('failed', 0);

        $this->assertSame(2, app(TenantContext::class)->run(
            $this->alpha['organization'], fn () => Customer::count(),
        ));
    }

    public function test_the_bad_rows_are_reported_and_the_good_ones_still_land(): void
    {
        $response = $this->import($this->alpha, $this->csv([
            ['Ada Lovelace', 'ada@example.test'],
            ['', 'no-name@example.test'],
            ['Alan Turing', 'not-an-email'],
            ['Grace Hopper', 'grace@example.test'],
        ]))->assertOk();

        // §11.3: partial failure. Rolling back 998 good rows because of two bad
        // ones is what makes people paste records in by hand instead.
        $response->assertJsonPath('imported', 2)->assertJsonPath('failed', 2);

        // Row NUMBERS, matching what the spreadsheet shows — the header is row 1,
        // so the first bad row is 3. Without this you are asking somebody to
        // find one wrong cell in a thousand.
        $this->assertSame([3, 4], array_column($response->json('errors'), 'row'));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->import($this->alpha, $this->csv([
            ['Ada Lovelace', 'ada@example.test'],
            ['', 'broken@example.test'],
        ]), ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('dry_run', true);

        $this->assertSame(0, app(TenantContext::class)->run(
            $this->alpha['organization'], fn () => Customer::count(),
        ));
    }

    public function test_an_unrecognised_column_refuses_the_whole_file(): void
    {
        // Ignoring it would import a column of blanks and report success — the
        // user finds out weeks later that no phone numbers came across.
        $this->import($this->alpha, $this->csv(
            [['Ada', '555']], ['name', 'telephone'],
        ))->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'telephone'));
    }

    public function test_a_file_saved_by_excel_still_works(): void
    {
        // Excel writes a BOM on every CSV. Without stripping it the first column
        // is "\xEF\xBB\xBFname", which is "unknown" — on the file we exported.
        $this->import($this->alpha, "\xEF\xBB\xBF".$this->csv([['Ada', 'ada@example.test']]))
            ->assertOk()->assertJsonPath('imported', 1);
    }

    public function test_re_importing_an_export_does_not_duplicate(): void
    {
        $existing = $this->makeCustomer($this->alpha, ['number' => 'C-100', 'name' => 'Ada']);

        $this->import($this->alpha, $this->csv(
            [['C-100', 'Ada'], ['C-101', 'Grace']], ['number', 'name'],
        ))->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('failed', 1);

        $this->assertSame(2, app(TenantContext::class)->run(
            $this->alpha['organization'], fn () => Customer::count(),
        ));

        $this->assertSame('Ada', $existing->fresh()->name);
    }

    public function test_an_import_cannot_target_another_organizations_franchise(): void
    {
        $this->as($this->alpha)->postJson('/api/v1/imports/customers', [
            'csv' => $this->csv([['Ada', 'ada@example.test']]),
            'franchise_id' => $this->beta['franchise']->id,
        ])->assertNotFound();

        $this->assertSame(0, app(TenantContext::class)->run(
            $this->beta['organization'], fn () => Customer::count(),
        ));
    }

    public function test_an_import_is_refused_once_the_subscription_lapses(): void
    {
        $this->alpha['organization']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        SubscriptionGuard::flush();

        // §13: "an import that still runs means the product works fine without
        // paying."
        $this->import($this->alpha, $this->csv([['Ada', 'ada@example.test']]))
            ->assertStatus(402);
    }

    public function test_an_uploaded_file_works_the_same_as_pasted_text(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'customers.csv', $this->csv([['Ada', 'ada@example.test']]),
        );

        $this->as($this->alpha)->post('/api/v1/imports/customers', [
            'file' => $file, 'franchise_id' => $this->alpha['franchise']->id,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('imported', 1);
    }

    // ── reach (§4) ───────────────────────────────────────────────

    /** A second franchise in Alpha, plus a user who reaches only that one. */
    private function branch(): array
    {
        return app(TenantContext::class)->run($this->alpha['organization'], function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['organization']->id,
                'company_id' => Company::query()->value('id'),
                'name' => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['organization']->id,
                'role_id' => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
                'name' => 'Branch', 'email' => 'branch@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['organization']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    private function job(Franchise $franchise, string $number, Customer $customer): Job
    {
        return app(TenantContext::class)->run($this->alpha['organization'], fn () => Job::create([
            'franchise_id' => $franchise->id, 'customer_id' => $customer->id,
            'number' => $number, 'title' => 'Work', 'status' => 'scheduled', 'currency' => 'USD',
        ]));
    }

    private function asUser(User $user): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_the_job_export_stops_at_the_callers_reach(): void
    {
        [$second, $branchUser] = $this->branch();

        $customer = $this->makeCustomer($this->alpha, ['name' => 'Shared Person']);
        $this->job($this->alpha['franchise'], 'JOB-HQ', $customer);
        $this->job($second, 'JOB-BRANCH', $customer);

        $body = $this->asUser($branchUser)->get('/api/v1/exports/jobs')
            ->assertOk()->streamedContent();

        // Permission is WHAT, reach is WHERE. This endpoint checked only the
        // first, so anybody with jobs.view could download every franchise's
        // work — a leak that looks like a working feature, because nobody
        // reports a number that is too big.
        $this->assertStringContainsString('JOB-BRANCH', $body);
        $this->assertStringNotContainsString('JOB-HQ', $body);
    }

    public function test_the_json_bundle_stops_at_the_callers_reach(): void
    {
        [$second, $branchUser] = $this->branch();

        $customer = $this->makeCustomer($this->alpha, ['name' => 'Shared Person']);
        $this->job($this->alpha['franchise'], 'JOB-HQ', $customer);
        $this->job($second, 'JOB-BRANCH', $customer);

        $numbers = array_column(
            $this->asUser($branchUser)->getJson('/api/v1/exports/bundle')->assertOk()->json('jobs'),
            'number',
        );

        $this->assertSame(['JOB-BRANCH'], $numbers);
    }

    public function test_a_person_export_only_includes_jobs_the_caller_may_see(): void
    {
        [$second, $branchUser] = $this->branch();

        $customer = $this->makeCustomer($this->alpha, ['name' => 'Shared Person']);
        $this->job($this->alpha['franchise'], 'JOB-HQ', $customer);
        $this->job($second, 'JOB-BRANCH', $customer);

        $response = $this->asUser($branchUser)
            ->getJson("/api/v1/customers/{$customer->id}/export")->assertOk();

        // A customer is shared org-wide, so customers.view is rightly enough to
        // read one. Their jobs are NOT shared, and this was handing over every
        // franchise's work for that customer alongside the record.
        $this->assertSame(['JOB-BRANCH'], array_column($response->json('jobs'), 'number'));
    }

    // ── import correctness ───────────────────────────────────────

    public function test_a_blank_number_cell_still_gets_a_generated_number(): void
    {
        $this->import($this->alpha, $this->csv(
            [['', 'Ada'], ['C-9', 'Grace']], ['number', 'name'],
        ))->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('failed', 0);

        // A blank cell arrives as a present-but-null key, and PHP's `+` gives
        // the LEFT operand precedence — so the null beat the generated number
        // and the row went in with none at all. Every export carries a `number`
        // column, so re-importing an edited export hit this on the first blank.
        $numbers = app(TenantContext::class)->run(
            $this->alpha['organization'],
            fn () => Customer::orderBy('name')->pluck('number')->all(),
        );

        $this->assertNotContains(null, $numbers);
        $this->assertContains('C-9', $numbers);
    }

    public function test_a_row_with_a_stray_comma_is_reported_not_truncated(): void
    {
        $response = $this->import($this->alpha, implode("\n", [
            'name,notes',
            'Ada,Fine',
            'Bob,Call before 9, use the side gate',
        ]))->assertOk();

        // Truncating dropped the tail and shifted every later column by one, and
        // the mangled row then validated clean. Losing data quietly is worse
        // than refusing a row and saying which one.
        $this->assertSame(1, $response->json('imported'));
        $this->assertSame([3], array_column($response->json('errors'), 'row'));
    }

    public function test_an_exported_phone_number_survives_a_round_trip(): void
    {
        $this->makeCustomer($this->alpha, ['name' => 'Ada', 'phone' => '+1 415 555 0100']);

        $body = $this->as($this->alpha)->get('/api/v1/exports/customers')
            ->assertOk()->streamedContent();

        // Escaped on the way out, because `+` starts a formula in Excel.
        $this->assertStringContainsString("'+1 415 555 0100", $body);

        $this->import($this->alpha, $this->csv(
            [["'+1 415 555 0100", 'Bob']], ['phone', 'name'],
        ))->assertOk()->assertJsonPath('imported', 1);

        // Un-escaped on the way back in, or every round trip adds an apostrophe.
        $this->assertSame('+1 415 555 0100', app(TenantContext::class)->run(
            $this->alpha['organization'],
            fn () => Customer::where('name', 'Bob')->value('phone'),
        ));
    }

    public function test_jobs_are_export_only(): void
    {
        $this->as($this->alpha)->getJson('/api/v1/exports')
            ->assertOk()
            ->assertJsonPath('resources.1.key', 'jobs')
            ->assertJsonPath('resources.1.importable', false);

        $this->as($this->alpha)->postJson('/api/v1/imports/jobs', [
            'csv' => "title\nA job", 'franchise_id' => $this->alpha['franchise']->id,
        ])->assertStatus(422);
    }

    // ── after the "Import & export" tab was removed (§12, §9) ────
    //
    // The tab is gone. Per-resource export and import moved into the Customers
    // and Jobs tabs; `bundle` and `inventory` moved into Settings, because
    // neither is about a resource and neither had a tab to move into.
    //
    // Everything below fails if any part of that is undone — the catalogue
    // losing the two fields the per-tab controls read, the §9 answers losing
    // the sentences the Settings section shows, or somebody deciding the
    // removed module's permission should gate an endpoint after all.

    /**
     * "Nobody can import this" and "YOU may not import this" are two facts.
     *
     * The catalogue answered `importable: false` to both, and the one screen
     * reading it drew nothing either way. Once the controls moved into the tabs
     * that difference became visible: jobs genuinely have no import, so the tab
     * shows no control; a person without `customers.create` is missing a grant
     * somebody can hand them in ten seconds, and is owed the control and the
     * sentence (R8).
     *
     * So `importable` is now the RESOURCE's answer and `import_permission`
     * names the grant. Collapse them back into one and this goes red.
     */
    public function test_the_catalogue_separates_what_cannot_be_imported_from_who_may_not(): void
    {
        $alpha  = $this->provisionTenant('Gamma');
        $reader = $this->userWithRole($alpha, 'Reader', ['customers.view', 'jobs.view']);

        $body = $this->signedInAs($reader)->getJson('/api/v1/exports')
            ->assertOk()->json();

        $customers = collect($body['resources'])->firstWhere('key', 'customers');
        $jobs      = collect($body['resources'])->firstWhere('key', 'jobs');

        // Customers CAN be imported. This reader may not, and the tab has to be
        // able to say so rather than quietly omit the control.
        $this->assertTrue($customers['importable']);
        $this->assertSame('customers.create', $customers['import_permission']);

        // Jobs cannot be imported by anybody, so there is no grant to name and
        // nothing for a tab to offer.
        $this->assertFalse($jobs['importable']);
        $this->assertNull($jobs['import_permission']);

        // And the columns are published whether or not this caller may use
        // them: they are the names of fields, not anybody's data, and the panel
        // that shows them is only ever opened by somebody who may import.
        $this->assertContains('name', $customers['import_columns']);
    }

    /**
     * The two §9 answers say, up front, whether they will work.
     *
     * They live in Settings now, and that screen has no way to work the gate
     * out for itself. Nor are the two gates the same, which is why there are
     * two sentences and not one: `bundle` needs every resource's EXPORT
     * permission, because it returns every row of every resource and would
     * otherwise be a second door beside the one export just locked; the
     * inventory needs every resource's VIEW permission, because it emits table
     * names, columns, purposes and counts and never reads a row.
     *
     * So the catalogue carries the answer as one nullable sentence each: null
     * means the control works, a string is what to show instead. The same
     * person can now be refused one and permitted the other.
     */
    public function test_the_catalogue_says_whether_the_whole_organization_answers_are_permitted(): void
    {
        $alpha = $this->provisionTenant('Delta');

        $everything = $this->signedInAs($alpha['admin'])->getJson('/api/v1/exports')
            ->assertOk()->json('organization');

        $this->assertNull($everything['export_refusal']);
        $this->assertNull($everything['inventory_refusal']);

        // Half the resources is not enough for either, and the two acts are
        // refused separately because they are two different acts.
        $partial = $this->userWithRole($alpha, 'Partial', ['customers.view']);

        $refused = $this->signedInAs($partial)->getJson('/api/v1/exports')
            ->assertOk()->json('organization');

        $this->assertStringContainsString(
            'export everything this organization holds',
            $refused['export_refusal'],
        );
        $this->assertStringContainsString(
            'what this organization holds about people',
            $refused['inventory_refusal'],
        );
    }

    /**
     * The sentence the disabled control shows is the sentence the 403 carries.
     *
     * The whole point of sending it from the server. A screen that composed its
     * own would describe one refusal two ways, and the person reading them would
     * reasonably conclude they were two problems.
     */
    public function test_the_refusal_the_catalogue_publishes_is_the_one_the_endpoint_gives(): void
    {
        $alpha   = $this->provisionTenant('Epsilon');
        $partial = $this->userWithRole($alpha, 'Partial', ['customers.view']);

        $published = $this->signedInAs($partial)->getJson('/api/v1/exports')
            ->assertOk()->json('organization');

        $this->assertSame(
            $published['export_refusal'],
            $this->signedInAs($partial)->getJson('/api/v1/exports/bundle')
                ->assertForbidden()->json('message'),
        );

        $this->assertSame(
            $published['inventory_refusal'],
            $this->signedInAs($partial)->getJson('/api/v1/exports/inventory')
                ->assertForbidden()->json('message'),
        );
    }

    /**
     * The module is gone and the two §9 obligations are not.
     *
     * This is the one that would have caught the whole job going wrong. "Erase
     * the tab entirely" is easy to read as erasing what it did, and two of the
     * three things it did are legal obligations rather than conveniences:
     * portability, and the record of what is held about people. Both still
     * answer, for somebody who never held `data.view` because it no longer
     * exists to hold.
     */
    public function test_both_privacy_answers_survive_the_module_being_removed(): void
    {
        $this->assertNull(
            app(ModuleRegistry::class)->get('data'),
            'The Import & export module is supposed to be gone.',
        );

        $alpha = $this->provisionTenant('Zeta');

        // Every exportable resource, and not one permission more — in
        // particular not the module's, which cannot be granted any more.
        //
        // The two `.export` keys joined this list when export became a
        // permission of its own: `bundle` takes the export permission of every
        // resource now (see DataController::bundle for why leaving it on view
        // would be a second door to the same customer list). `inventory`
        // deliberately did not move, and the test below is the one that pins
        // that — this one is about the two answers surviving at all.
        $reader = $this->userWithRole($alpha, 'Reader', [
            'customers.view', 'jobs.view', 'customers.export', 'jobs.export',
        ]);

        $bundle = $this->signedInAs($reader)->getJson('/api/v1/exports/bundle')->assertOk();
        $this->assertArrayHasKey('customers', $bundle->json());
        $this->assertArrayHasKey('jobs', $bundle->json());

        $this->signedInAs($reader)->getJson('/api/v1/exports/inventory')
            ->assertOk()
            ->assertJsonStructure(['holdings', 'caveats', 'generated_at']);

        // And the per-resource half, which is what moved into the tabs.
        $this->signedInAs($reader)->get('/api/v1/exports/customers')->assertOk();
    }

    /**
     * A permission for a module that does not exist is not grantable.
     *
     * `data.view` was the module's only one. Nothing gates on it — the export
     * routes have always required the view permission of the resource being
     * exported — so its survival would be the quiet kind of wrong: a role
     * listing "View import & export" on the roles screen, granting nothing, on
     * the one screen whose job is to say what a role does.
     */
    public function test_the_removed_modules_permission_can_no_longer_be_granted(): void
    {
        $alpha = $this->provisionTenant('Eta');

        $this->assertNotContains(
            'data.view',
            app(ModuleRegistry::class)->allPermissionKeys(),
        );

        // Offered nowhere in the roles editor, which builds its tree from the
        // registry rather than from the table.
        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $this->assertNotContains('data', collect($modules)->pluck('key')->all());

        // And gone from the table, so no role can still be carrying it.
        $this->assertDatabaseMissing('permissions', ['key' => 'data.view']);
    }

    /**
     * The migration, tested against the state it actually exists for.
     *
     * The assertion above is TRUE and proves nothing about the migration:
     * under RefreshDatabase the schema is built from scratch and the registry
     * no longer declares `data.view`, so the row was never created and
     * `assertDatabaseMissing` would pass with the migration deleted. It was —
     * moving the file out of the way left the whole file green.
     *
     * The migration only matters on an UPGRADE: a running installation that
     * has the permission and roles already granted it. So this builds that
     * state by hand and runs the migration against it, which is the only
     * arrangement in which it can fail.
     *
     * The grant matters as much as the permission. A permissions row deleted
     * out from under its pivot rows leaves them pointing at nothing, and
     * whether that errors or is silently tolerated depends on the driver —
     * which is why the migration deletes the grants first and why this checks
     * they went.
     */
    public function test_the_migration_removes_the_permission_an_upgrade_still_has(): void
    {
        $alpha = $this->provisionTenant('Theta');

        $id = (string) Str::uuid();

        // The pre-migration world: the permission exists and a role holds it.
        DB::table('permissions')->insert([
            'id'         => $id,
            'key'        => 'data.view',
            'module'     => 'data',
            'action'     => 'view',
            'label'      => 'View import & export',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permission_role')->insert([
            'permission_id' => $id,
            'role_id'       => \Tests\Concerns\BranchManagerRole::idFor($alpha['admin']),
        ]);

        $migration = require database_path(
            'migrations/2026_08_12_090000_retire_the_data_module_permission.php',
        );

        $migration->up();

        $this->assertDatabaseMissing('permissions', ['key' => 'data.view']);

        $this->assertSame(
            0,
            DB::table('permission_role')->where('permission_id', $id)->count(),
            'The permission went and the grants pointing at it did not, so every role that '
            .'held it now carries a row referring to nothing.',
        );
    }

    // ── export is its own permission (§12, §4) ───────────────────
    //
    // Removing the "Import & export" tab took a control away from
    // organizations. The tab was gated on `data.view`, so a role could be kept
    // out of bulk transfer; the per-resource endpoints were only ever gated on
    // the resource's VIEW permission, so once the Export control moved into the
    // Customers header there was no grant left to withhold —
    // anybody who could read a customer could download every customer's name,
    // email, phone and address as a spreadsheet, and no setting could stop them.
    //
    // `customers.export` and `jobs.export` are that setting. Everything below
    // fails if the gate goes back to being the view permission, if the refusal
    // starts naming a permission key, or if `bundle` is left as a second way to
    // fetch the same rows.

    /**
     * The map and the registry have to agree, or the permission is unusable.
     *
     * A typo in `export.permission` — or a resource added to ResourceMap with no
     * export key at all — is not a crash. It is export refused for everybody
     * forever, with the fix being a grant the roles editor cannot offer because
     * the registry never declared it. ModuleDefinition fails at boot for its own
     * mistakes; ResourceMap has nothing that does, so this is it.
     */
    public function test_every_resource_declares_an_export_permission_the_registry_offers(): void
    {
        $resources = ResourceMap::all();

        // The control. Every assertion below is inside a loop, so an empty map
        // would pass this test while proving nothing at all.
        $this->assertNotEmpty($resources, 'ResourceMap lists nothing to export.');

        $declared = app(ModuleRegistry::class)->allPermissionKeys();

        foreach ($resources as $key => $definition) {
            $export = $definition['export']['permission'] ?? null;

            $this->assertNotNull(
                $export,
                "'{$key}' declares no export permission, so DataController cannot gate it.",
            );

            $this->assertContains($export, $declared,
                "'{$export}' is not a permission any module declares, so no role can ever be "
                .'given it and export is off permanently.',
            );

            // The other two, checked in the same breath: they have the same
            // failure mode and there was no test holding them either.
            $this->assertContains($definition['permission'], $declared);

            if (isset($definition['import'])) {
                $this->assertContains($definition['import']['permission'], $declared);
            }
        }
    }

    /**
     * Reading one customer and downloading all of them are different acts.
     *
     * The refusal is a SENTENCE about asking somebody for permission (R8), and
     * it must not name the key — a person reading "customers.export" learns a
     * string they cannot act on, and the roles screen is not where they are.
     */
    public function test_the_resource_export_takes_the_export_permission_and_not_the_view(): void
    {
        $alpha = $this->provisionTenant('Iota');
        $this->makeCustomerIn($alpha, 'Alpha Person');

        $viewer   = $this->userWithRole($alpha, 'Viewer', ['customers.view']);
        $exporter = $this->userWithRole($alpha, 'Exporter', ['customers.view', 'customers.export']);

        $message = $this->signedInAs($viewer)->get('/api/v1/exports/customers')
            ->assertForbidden()->json('message');

        $this->assertStringContainsString('export customers', $message);
        $this->assertStringContainsString('somebody who can edit roles', $message);
        $this->assertStringNotContainsString('customers.export', $message,
            'R8: a refusal is a sentence saying what to do, never a permission key.',
        );

        // The control, and the point of the whole change. One grant apart, the
        // same person gets the file — so the 403 above is the export permission
        // being missing rather than this role being locked out of customers,
        // and the export being tested is one that really produces the rows.
        $body = $this->signedInAs($exporter)->get('/api/v1/exports/customers')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Alpha Person', $body);
    }

    /**
     * The sentence the greyed control shows is the sentence the 403 carries.
     *
     * Per resource, exactly as the two organization-wide answers already do it.
     * The catalogue still LISTS a resource this person may not export, because
     * being listed means "you may read this" — filtering it out would take the
     * Import control and the import columns away at the same time, over a
     * different permission entirely.
     */
    public function test_the_catalogue_publishes_the_export_refusal_the_endpoint_gives(): void
    {
        $alpha  = $this->provisionTenant('Kappa');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.view', 'customers.create']);

        $customers = collect(
            $this->signedInAs($viewer)->getJson('/api/v1/exports')->assertOk()->json('resources'),
        )->firstWhere('key', 'customers');

        $this->assertNotNull($customers, 'A resource somebody may READ has to stay listed.');

        $this->assertSame(
            $customers['export_refusal'],
            $this->signedInAs($viewer)->get('/api/v1/exports/customers')
                ->assertForbidden()->json('message'),
        );

        // Still offered the import they DO hold, on the same entry — which is
        // why the resource is listed rather than filtered out.
        $this->assertTrue($customers['importable']);
        $this->assertSame('customers.create', $customers['import_permission']);

        // The control: the field is not simply always a sentence. Somebody who
        // holds the grant gets null, which is what draws a live button.
        $exporter = $this->userWithRole($alpha, 'Exporter', ['customers.view', 'customers.export']);

        $permitted = collect(
            $this->signedInAs($exporter)->getJson('/api/v1/exports')->assertOk()->json('resources'),
        )->firstWhere('key', 'customers');

        $this->assertNull($permitted['export_refusal']);
    }

    /**
     * The bundle is not a second door to the same rows.
     *
     * `/exports/bundle` returns every row of every resource in the map, so a
     * role refused `customers.export` could have fetched the identical customer
     * list from it in JSON. It now takes the export permission of EVERY
     * resource, which is the same shape it always had — it used to be the view
     * permission of every resource — for the same reason: "export everything" is
     * a bigger act than any single export.
     */
    public function test_the_bundle_takes_every_resources_export_permission(): void
    {
        $alpha = $this->provisionTenant('Lambda');
        $this->makeCustomerIn($alpha, 'Alpha Person');

        $partial = $this->userWithRole($alpha, 'Partial', [
            'customers.view', 'jobs.view', 'jobs.export',
        ]);

        $refused = $this->signedInAs($partial)->getJson('/api/v1/exports/bundle')->assertForbidden();

        // Not merely refused — refused without the rows in the body, which is
        // the failure this endpoint would have had if the loop were left on the
        // view permission.
        $this->assertStringNotContainsString('Alpha Person', $refused->getContent());

        // And the greyed control in Settings says the same thing this did.
        $this->assertSame(
            $this->signedInAs($partial)->getJson('/api/v1/exports')
                ->assertOk()->json('organization.export_refusal'),
            $refused->json('message'),
        );

        // The control: one more grant and the same request works, and answers
        // with the customer — so the refusal above is the missing permission
        // rather than an empty organization.
        $whole = $this->userWithRole($alpha, 'Whole', [
            'customers.view', 'jobs.view', 'customers.export', 'jobs.export',
        ]);

        $bundle = $this->signedInAs($whole)->getJson('/api/v1/exports/bundle')->assertOk();

        $this->assertSame('Alpha Person', $bundle->json('customers.0.name'));
        $this->assertNull($this->signedInAs($whole)->getJson('/api/v1/exports')
            ->assertOk()->json('organization.export_refusal'));
    }

    /**
     * The inventory did NOT move to the export permissions, and that is a
     * decision rather than a line somebody forgot.
     *
     * It is the MAP, not the territory: table names, field names, purposes,
     * retention and counts, and no personal data at any point — see
     * DataInventory, which builds it from each model's declarations and never
     * reads a row. `customers.export` exists to govern personal data leaving in
     * bulk, in a file; refusing this would withhold no name, address or phone
     * number from anybody, and would take a §9 obligation away from somebody who
     * may still read every one of those people one at a time.
     *
     * So the two whole-organization answers now genuinely differ for the same
     * person, and this is the test that says which way round.
     */
    public function test_the_inventory_still_takes_the_view_permissions_and_not_the_export_ones(): void
    {
        $alpha  = $this->provisionTenant('Mu');
        $reader = $this->userWithRole($alpha, 'Reader', ['customers.view', 'jobs.view']);

        $inventory = $this->signedInAs($reader)->getJson('/api/v1/exports/inventory')
            ->assertOk()
            ->assertJsonStructure(['holdings', 'caveats', 'generated_at']);

        // The control. "It answered 200" proves nothing if the answer is empty —
        // and an empty inventory is the shape a broken one would have.
        $this->assertNotEmpty($inventory->json('holdings'));

        // The same person, the same request minus the map: refused, because
        // that one is the data itself.
        $this->signedInAs($reader)->getJson('/api/v1/exports/bundle')->assertForbidden();

        // And the catalogue says both of those in advance, differently.
        $organization = $this->signedInAs($reader)->getJson('/api/v1/exports')
            ->assertOk()->json('organization');

        $this->assertNull($organization['inventory_refusal']);
        $this->assertNotNull($organization['export_refusal']);
    }

    /**
     * A permission the roles editor cannot offer is a permission nobody can be
     * given — the mirror of `test_the_removed_modules_permission_can_no_longer_be_granted`.
     */
    public function test_the_roles_editor_offers_export_and_a_role_can_be_given_it(): void
    {
        $alpha = $this->provisionTenant('Nu');

        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $customers = collect($modules)->firstWhere('key', 'customers');

        $this->assertContains('customers.export', array_column($customers['actions'], 'key'));
        $this->assertContains('jobs.export', array_column(
            collect($modules)->firstWhere('key', 'jobs')['actions'], 'key',
        ));

        // Grantable, not merely listed — the editor greys what the actor cannot
        // hand out, and an administrator holds the whole catalogue.
        $export = collect($customers['actions'])->firstWhere('key', 'customers.export');
        $this->assertTrue($export['grantable']);
        $this->assertSame('Export', $export['label']);

        // And it survives a save, arriving with the implied view (§4).
        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Exporter', 'permissions' => ['customers.export'],
        ])->assertCreated()->assertJsonPath('data.permissions', [
            'customers.export', 'customers.view',
        ]);
    }

    /**
     * The migration, tested against the state it exists for.
     *
     * A new permission that nobody holds turns export off for every existing
     * customer overnight. The migration grants `<resource>.export` to every role
     * that already holds `<resource>.view`, so the day of the upgrade behaves
     * exactly like the day before it — and the control becomes something an
     * organization can now REMOVE.
     *
     * Built by hand, for the reason the test above it records: under
     * RefreshDatabase the schema is migrated before any role exists, so the
     * migration's own run had nobody to grant anything to and asserting the end
     * state would pass with the file deleted. The upgrade state is a role
     * holding the view and not the export, which is what `userWithRole` leaves
     * behind, and the assertion is behavioural on both sides of the run.
     */
    public function test_the_migration_grants_export_to_a_role_an_upgrade_left_with_only_view(): void
    {
        $alpha = $this->provisionTenant('Xi');
        $this->makeCustomerIn($alpha, 'Alpha Person');

        // The pre-migration world: a role that could export customers the day
        // before, because export was the view permission.
        $reader = $this->userWithRole($alpha, 'Reader', ['customers.view', 'jobs.view']);

        // Somebody who could not, and must not be given the grant by a migration
        // that took "everybody" to mean "every role".
        $stranger = $this->userWithRole($alpha, 'Stranger', ['jobs.view']);

        $this->signedInAs($reader)->get('/api/v1/exports/customers')->assertForbidden();

        $migration = require database_path(
            'migrations/2026_08_12_120000_grant_export_to_roles_that_already_view.php',
        );

        // The second run against this schema — the first was RefreshDatabase's,
        // before any of these roles existed — so this also proves it can be
        // re-run without colliding with the pivot's composite key.
        $migration->up();

        $body = $this->signedInAs($reader)->get('/api/v1/exports/customers')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Alpha Person', $body,
            'The upgrade was supposed to be invisible: a role that could export customers '
            .'yesterday still can.',
        );

        // Granted for what they hold and nothing else. Without this the
        // migration could hand every role every export key and still pass.
        //
        // Roles and permissions are tenant data, so the read has to say which
        // organization it is about — see BelongsToTenant, which refuses an
        // unscoped one rather than quietly answering across tenants.
        [$mayExportJobs, $mayExportCustomers] = $this->inTenant($alpha['org'], fn () => [
            $stranger->fresh()->hasPermission('jobs.export'),
            $stranger->fresh()->hasPermission('customers.export'),
        ]);

        $this->assertTrue($mayExportJobs);
        $this->assertFalse($mayExportCustomers);

        // The row the roles screen reads, worded as PermissionCatalogue would —
        // a label that disagrees with the sync command is a permission spelled
        // two ways forever.
        $this->assertDatabaseHas('permissions', [
            'key' => 'customers.export', 'module' => 'customers',
            'action' => 'export', 'label' => 'Export customers',
        ]);

        $this->assertSame(
            1,
            DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permissions.key', 'customers.export')
                ->where('permission_role.role_id', $reader->role_id)
                ->count(),
            'The grant is there exactly once — a second run must not duplicate it.',
        );
    }

    /** A customer in a tenant built by BuildsTenants, which keys its array differently. */
    private function makeCustomerIn(array $tenant, string $name): Customer
    {
        return $this->inTenant($tenant['org'], fn () => Customer::create([
            'owning_franchise_id' => $tenant['franchise']->id,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name'   => $name,
        ]));
    }
}
