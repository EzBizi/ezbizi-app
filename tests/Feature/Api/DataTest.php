<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Subscriptions\SubscriptionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
                'role_id' => $this->alpha['admin']->role_id,
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
}
