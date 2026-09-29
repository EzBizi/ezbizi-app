<?php

namespace Tests\Feature\Hr;

use App\Models\Assignment;
use App\Models\Attachment;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\EmergencyContact;
use App\Models\EmploymentEvent;
use App\Models\Franchise;
use App\Models\HeldEquipment;
use App\Models\HrNote;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\PayRate;
use App\Models\Role;
use App\Models\User;
use App\Notifications\WelcomeSetPassword;
use App\Support\Hr\A1ToolsPeopleImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `hr:import-a1-tools` — docs/A1-TOOLS-PEOPLE-MIGRATION.md, HR's section.
 *
 * Every fixture here is built from the bundle format in that spec, so a test
 * that passes is a claim about the format the Dismantler writes, not about a
 * shape this file invented.
 */
class A1ToolsPeopleImportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private array $t;

    private string $dir;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->t = $this->provisionTenant('Alpha');

        $this->inTenant($this->t['org'], function () {
            $home = Company::create(['organization_id' => $this->t['org']->id, 'name' => 'A-1 Chimney']);
            $this->ids['home'] = $home->id;
            $this->ids['ct'] = Franchise::create([
                'organization_id' => $this->t['org']->id, 'company_id' => $home->id, 'name' => 'Connecticut',
            ])->id;

            // The decoy: a Connecticut under ANOTHER company. A1's franchises
            // match under A-1 Chimney only (spec, G2).
            $other = Company::create(['organization_id' => $this->t['org']->id, 'name' => 'Everdoors']);
            $this->ids['everdoors'] = $other->id;
            $this->ids['decoy_ct'] = Franchise::create([
                'organization_id' => $this->t['org']->id, 'company_id' => $other->id, 'name' => 'Connecticut',
            ])->id;

            foreach (['Technician', 'Dispatcher'] as $name) {
                $this->ids[$name] = Role::create([
                    'organization_id' => $this->t['org']->id, 'name' => $name, 'is_system' => false,
                ])->id;
            }
        });

        $this->dir = sys_get_temp_dir().'/a1-bundle-'.uniqid();
        mkdir("{$this->dir}/files", 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/files/*") ?: [] as $file) {
            unlink($file);
        }
        @unlink("{$this->dir}/people.json");
        @rmdir("{$this->dir}/files");
        @rmdir($this->dir);

        parent::tearDown();
    }

    // ── the bundle ───────────────────────────────────────────────

    private function file(string $bytes, string $ext, string $mime): array
    {
        $sha = hash('sha256', $bytes);
        file_put_contents("{$this->dir}/files/{$sha}.{$ext}", $bytes);

        return ['path' => "files/{$sha}.{$ext}", 'sha256' => $sha, 'bytes' => strlen($bytes), 'mime' => $mime];
    }

    private function bundle(array $people, array $files, array $extra = []): void
    {
        file_put_contents("{$this->dir}/people.json", json_encode([
            'format'       => A1ToolsPeopleImport::FORMAT,
            'exported_at'  => '2026-09-30T14:00:00Z',
            'organization' => $this->t['org']->name,
            'files'        => $files,
            'people'       => $people,
        ] + $extra));
    }

    private function newPerson(array $over = []): array
    {
        return array_replace([
            'email'              => 'Jon.Ames@Example.com ',
            'a1_user_ids'        => [83],
            'first_name'         => 'Jon',
            'middle_name'        => null,
            'last_name'          => 'Ames',
            'phone'              => '(203) 555-0148',
            'address'            => '41 Elm St, Stamford, CT 06902, US',
            'date_of_birth'      => '1991-03-04',
            'gender'             => null,
            'a1_role'            => 'customer_experience',
            'scope'              => ['type' => 'franchises', 'franchises' => ['CT']],
            'started_on'         => '2025-06-02',
            'work_schedule'      => [
                'monday'  => ['start' => '08:00', 'end' => '17:00', 'off' => false],
                'tuesday' => ['start' => '08:00:00', 'end' => '17:00:00', 'off' => false],
                'sunday'  => ['start' => '', 'end' => '', 'off' => true],
            ],
            'lock_screen_exempt' => false,
            'avatar'             => null,
            'onboarding'         => null,
            'pay_rates'          => [],
            'emergency_contacts' => [],
            'hr_notes'           => [],
            'equipment'          => [],
        ], $over);
    }

    private function import(bool $dry = false): int
    {
        return $this->artisan('hr:import-a1-tools', ['bundle' => $this->dir] + ($dry ? ['--dry-run' => true] : []))
            ->run();
    }

    private function jon(): ?User
    {
        return $this->inTenant($this->t['org'], fn () => User::query()->where('email', 'jon.ames@example.com')->first());
    }

    // ── tests ────────────────────────────────────────────────────

    public function test_a_dry_run_checks_everything_and_writes_nothing(): void
    {
        $this->bundle([$this->newPerson()], []);

        $this->artisan('hr:import-a1-tools', ['bundle' => $this->dir, '--dry-run' => true])
            ->expectsOutputToContain('1 people: 1 new, 0 matched, 0 already imported.')
            ->expectsOutputToContain('A1 #83: would be new')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();

        $this->assertNull($this->jon());
        $this->assertSame(0, DB::table('onboardings')->count());
        $this->assertSame(0, DB::table('contracts')->where('name', A1ToolsPeopleImport::CONTRACT_NAME)->count(),
            'A dry run made the record contract.');
    }

    public function test_a_new_person_arrives_with_everything(): void
    {
        $png = $this->file(base64_decode(self::PNG), 'png', 'image/png');
        $pdf = $this->file('%PDF-1.4 signed', 'pdf', 'application/pdf');

        $this->bundle([$this->newPerson([
            'avatar'     => $png['path'],
            'onboarding' => [
                'submitted_at' => '2026-08-01T15:20:00Z',
                'answers'      => ['ssn' => '123456789', 'bank_name' => 'Chase', 'csia_number' => null],
                'documents'    => [['kind' => 'signed_contract', 'file' => $pdf['path'], 'original_name' => 'contract.pdf']],
            ],
            'pay_rates'          => [['basis' => 'hourly', 'amount' => '27.50', 'period' => 'hour', 'effective_on' => '2026-06-01', 'note' => null]],
            'emergency_contacts' => [['name' => 'Ana Ames', 'relationship' => 'Sister', 'phone' => '(203) 555-0101']],
            'hr_notes'           => ['Came over from A1 Tools.'],
            'equipment'          => [['name' => 'Chimney camera', 'category' => 'equipment', 'quantity' => 1,
                'identifier' => 'CAM-17', 'issued_on' => '2026-05-01', 'returned_on' => null, 'note' => null]],
        ])], [$png, $pdf]);

        $this->assertSame(0, $this->import());

        $jon = $this->jon();
        $this->assertNotNull($jon, 'Created, and found by the lower-cased, trimmed email.');

        $this->inTenant($this->t['org'], function () use ($jon) {
            $this->assertSame('Jon Ames', $jon->name);
            $this->assertSame($this->ids['Dispatcher'], $jon->role_id, 'customer_experience maps to Dispatcher.');
            $this->assertTrue($jon->is_active);
            $this->assertNotNull($jon->email_verified_at, 'Otherwise they could sign in and not clock in.');
            $this->assertFalse(Hash::check('', $jon->password));
            $this->assertNotNull($jon->avatar_path);
            Storage::disk(config('filesystems.default'))->assertExists($jon->avatar_path);

            $this->assertSame('America/New_York', $jon->work_schedule['timezone']);
            // assertEquals, not assertSame: MySQL's JSON column orders keys its
            // own way, and the order is not what this pins.
            $this->assertEquals(['start' => '08:00', 'end' => '17:00'], $jon->work_schedule['days']['tuesday'],
                'A1 keeps seconds on some rows; EzBizi takes HH:MM.');
            $this->assertNull($jon->work_schedule['days']['sunday']);

            $this->assertSame('2025-06-02', EmploymentEvent::query()->where('user_id', $jon->id)->sole()->occurred_on->toDateString());

            $onboarding = Onboarding::query()->where('user_id', $jon->id)->sole();
            $this->assertNull($onboarding->invitation_id);
            $this->assertSame(Onboarding::COMPLETED, $onboarding->status);
            $this->assertSame('2026-08-01 15:20:00', $onboarding->submitted_at->toDateTimeString());

            $contract = Contract::query()->where('name', A1ToolsPeopleImport::CONTRACT_NAME)->sole();
            $this->assertNotNull($contract->archived_at);
            $this->assertSame($contract->current_version_id, $onboarding->contract_version_id);

            $answers = OnboardingAnswer::query()->where('onboarding_id', $onboarding->id)->pluck('value', 'field_key');
            $this->assertSame('123456789', $answers['ssn']);
            $this->assertArrayNotHasKey('csia_number', $answers->all(), 'A null answer is not stored as an empty one.');

            $raw = DB::table('onboarding_answers')->where('field_key', 'ssn')->value('value');
            $this->assertStringNotContainsString('123456789', $raw, 'The SSN is stored in the clear.');

            $attachment = Attachment::query()->findOrFail($answers['signed_contract']);
            $this->assertSame('onboardings', $attachment->attachable_type);
            $this->assertSame($onboarding->id, $attachment->attachable_id);
            $this->assertSame(hash('sha256', '%PDF-1.4 signed'), $attachment->checksum);

            $this->assertSame('27.50', PayRate::query()->where('user_id', $jon->id)->sole()->amount);
            $this->assertSame('Ana Ames', EmergencyContact::query()->where('user_id', $jon->id)->sole()->name);
            $this->assertSame('Came over from A1 Tools.', HrNote::query()->where('user_id', $jon->id)->sole()->body);
            $this->assertSame('CAM-17', HeldEquipment::query()->where('user_id', $jon->id)->sole()->identifier);
        });
    }

    /** The version marks the SSN sensitive, so the screen masks it and a reveal is audited. */
    public function test_the_record_version_marks_the_sensitive_answers(): void
    {
        $this->bundle([$this->newPerson()], []);
        $this->import();

        $fields = $this->inTenant($this->t['org'], fn () => collect(
            Contract::query()->where('name', A1ToolsPeopleImport::CONTRACT_NAME)->sole()->currentVersion->fields
        )->keyBy('key'));

        $this->assertTrue($fields['ssn']['sensitive']);
        $this->assertTrue($fields['bank_routing_number']['sensitive']);
        $this->assertFalse($fields['bank_name']['sensitive']);
        $this->assertSame('file', $fields['w2']['type']);
    }

    public function test_a_franchise_lands_under_a1_chimney_and_not_its_namesake(): void
    {
        $this->bundle([$this->newPerson()], []);
        $this->import();

        $franchises = $this->inTenant($this->t['org'], fn () => Assignment::query()
            ->where('user_id', $this->jon()->id)->pluck('assigned_franchise_id')->all());

        $this->assertSame([$this->ids['ct']], $franchises);
        $this->assertNotContains($this->ids['decoy_ct'], $franchises);
    }

    /**
     * A matched person keeps what EzBizi knows. Their role, email and
     * assignments are untouched, a filled field is not overwritten, a photo
     * they chose is not replaced — and their start date moves, because A1's is
     * the real one.
     */
    public function test_a_matched_person_is_filled_in_and_not_overwritten(): void
    {
        $existing = $this->userWithRole($this->t, 'Marketing', ['customers.view'], 'jon.ames@example.com');
        $this->inTenant($this->t['org'], fn () => $existing->forceFill([
            'phone' => '(999) 000-0000', 'address' => null, 'avatar_path' => 'avatars/theirs.jpg',
        ])->save());
        $roleBefore = $existing->role_id;
        $assignmentsBefore = $this->inTenant($this->t['org'], fn () => Assignment::query()->where('user_id', $existing->id)->count());

        $png = $this->file(base64_decode(self::PNG), 'png', 'image/png');
        // An unmapped A1 role and a null scope, both of which refuse a NEW
        // person — and must not refuse a matched one (Eve's `administrator`,
        // Bar's missing company access; the desk's rule of 2026-09-29).
        $this->bundle([$this->newPerson([
            'avatar' => $png['path'], 'a1_role' => 'administrator', 'scope' => null,
        ])], [$png]);

        $this->assertSame(0, $this->import());

        $this->inTenant($this->t['org'], function () use ($existing, $roleBefore, $assignmentsBefore) {
            $fresh = $existing->fresh();
            $this->assertSame($roleBefore, $fresh->role_id, 'A matched person\'s role was changed.');
            $this->assertSame('(999) 000-0000', $fresh->phone, 'A filled field was overwritten.');
            $this->assertSame('41 Elm St, Stamford, CT 06902, US', $fresh->address, 'An empty field was not filled.');
            $this->assertSame('avatars/theirs.jpg', $fresh->avatar_path, 'Their own photo was replaced.');
            $this->assertSame($assignmentsBefore, Assignment::query()->where('user_id', $existing->id)->count());
            $this->assertSame('2025-06-02', EmploymentEvent::query()->where('user_id', $existing->id)
                ->where('kind', EmploymentEvent::STARTED)->orderBy('occurred_on')->first()->occurred_on->toDateString());
        });
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->bundle([$this->newPerson([
            'hr_notes' => ['Once only.'],
            'onboarding' => ['submitted_at' => null, 'answers' => ['bank_name' => 'Chase'], 'documents' => []],
        ])], []);

        $this->import();

        $this->artisan('hr:import-a1-tools', ['bundle' => $this->dir])
            ->expectsOutputToContain('A1 #83: already imported')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('users')->where('email', 'jon.ames@example.com')->count());
        $this->assertSame(1, DB::table('onboardings')->count());
        $this->assertSame(1, DB::table('hr_notes')->count());
        $this->assertSame(1, DB::table('contracts')->where('name', A1ToolsPeopleImport::CONTRACT_NAME)->count());
    }

    /** One unknown key anywhere refuses the whole run — nobody is half-imported. */
    public function test_an_unknown_key_refuses_everybody(): void
    {
        $good = $this->newPerson(['email' => 'good@example.com', 'a1_user_ids' => [1]]);
        $bad = $this->newPerson(['email' => 'bad@example.com', 'a1_user_ids' => [2], 'social_security' => '1']);

        $this->bundle([$good, $bad], []);

        $this->artisan('hr:import-a1-tools', ['bundle' => $this->dir])
            ->expectsOutputToContain('Refused. Nothing was written.')
            ->expectsOutputToContain('A1 #2: unknown key social_security.')
            ->assertFailed();

        $this->assertSame(0, DB::table('users')->whereIn('email', ['good@example.com', 'bad@example.com'])->count());
    }

    public function test_a_file_that_does_not_match_its_checksum_refuses_the_run(): void
    {
        $png = $this->file(base64_decode(self::PNG), 'png', 'image/png');
        $png['sha256'] = str_repeat('0', 64);

        $this->bundle([$this->newPerson(['avatar' => $png['path']])], [$png]);

        $this->artisan('hr:import-a1-tools', ['bundle' => $this->dir])
            ->expectsOutputToContain('Checksum does not match')
            ->assertFailed();

        $this->assertNull($this->jon());
    }

    /** History, not events: nothing reaches automations. */
    public function test_nothing_fires(): void
    {
        $this->bundle([$this->newPerson([
            'onboarding' => ['submitted_at' => null, 'answers' => ['bank_name' => 'Chase'], 'documents' => []],
        ])], []);

        $this->import();

        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()
            ->whereIn('event_key', ['user.activated', 'onboarding.completed', 'invitation.accepted'])->count());
    }

    /** A second document of a kind, and every "other", fills the other slots. */
    public function test_extra_documents_take_the_other_slots(): void
    {
        $a = $this->file('w2 one', 'pdf', 'application/pdf');
        $b = $this->file('w2 two', 'pdf', 'application/pdf');
        $c = $this->file('misc', 'pdf', 'application/pdf');

        $this->bundle([$this->newPerson(['onboarding' => [
            'submitted_at' => null,
            'answers'      => [],
            'documents'    => [
                ['kind' => 'w2', 'file' => $a['path'], 'original_name' => 'a.pdf'],
                ['kind' => 'w2', 'file' => $b['path'], 'original_name' => 'b.pdf'],
                ['kind' => 'other', 'file' => $c['path'], 'original_name' => 'c.pdf'],
            ],
        ]])], [$a, $b, $c]);

        $this->import();

        $keys = $this->inTenant($this->t['org'], fn () => OnboardingAnswer::query()->pluck('field_key')->sort()->values()->all());

        $this->assertSame(['other_1', 'other_2', 'w2'], $keys);
    }

    /**
     * The record contract is archived, so it is not offered for new paperwork:
     * absent from the contracts list and refused if named anyway.
     */
    public function test_the_record_contract_is_not_offered_for_new_onboardings(): void
    {
        $this->bundle([$this->newPerson()], []);
        $this->import();

        $names = collect($this->signedInAs($this->t['admin'])->getJson('/api/v1/hr/contracts')->assertOk()->json('data'))
            ->pluck('name');
        $this->assertNotContains(A1ToolsPeopleImport::CONTRACT_NAME, $names->all());

        $contractId = $this->inTenant($this->t['org'], fn () => Contract::query()->where('name', A1ToolsPeopleImport::CONTRACT_NAME)->sole()->id);

        $this->signedInAs($this->t['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => (string) \Illuminate\Support\Str::uuid(),
            'contract_id'   => $contractId,
        ])->assertJsonValidationErrors('contract_id');
    }

    /**
     * A person whose write fails halfway leaves nothing: no row, and none of
     * the files already copied for them. The failure is forced AFTER the photo
     * and a document have been copied, which is the case the clean-up exists
     * for — a failure before any copy would pass this with the clean-up gone.
     */
    public function test_a_person_who_fails_halfway_leaves_no_rows_and_no_files(): void
    {
        $png = $this->file(base64_decode(self::PNG), 'png', 'image/png');
        $pdf = $this->file('%PDF-1.4 w2', 'pdf', 'application/pdf');

        $this->bundle([$this->newPerson([
            'avatar'     => $png['path'],
            'onboarding' => ['submitted_at' => null, 'answers' => [], 'documents' => [
                ['kind' => 'w2', 'file' => $pdf['path'], 'original_name' => 'w2.pdf'],
            ]],
        ])], [$png, $pdf]);

        $import = app(A1ToolsPeopleImport::class);
        $plan = $import->plan($this->dir);
        $this->assertSame([], $plan['errors']);

        // Pay rates are written after the photo and the documents are copied.
        $plan['people'][0]['pay_rates'][] = [
            'basis' => 'hourly', 'amount' => '1', 'period' => 'hour', 'effective_on' => 'not-a-date', 'note' => null,
        ];

        $said = [];
        $totals = $import->run($plan, function (string $line) use (&$said) {
            $said[] = $line;
        });

        $this->assertSame(1, $totals['failed']);
        $this->assertStringContainsString('A1 #83: FAILED', implode("\n", $said));
        $this->assertStringNotContainsString('not-a-date', implode("\n", $said), 'The message leaked a value.');

        $this->assertNull($this->jon(), 'The user survived a rolled-back person.');
        $this->assertSame(0, DB::table('attachments')->count());
        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles(),
            'Files copied for a rolled-back person were left on disk.');
    }

    // ── the "set your password" email ─────────────────────────────

    /**
     * Nothing is sent without --send: Jose's condition is "when the import is
     * verified", and the desk decides when that is.
     */
    public function test_the_welcome_sends_nothing_without_the_flag(): void
    {
        Notification::fake();

        $this->bundle([$this->newPerson()], []);
        $this->import();

        $this->artisan('hr:send-a1-welcome', ['--organization' => $this->t['org']->name])
            ->expectsOutputToContain('1 imported and active; 0 already sent one; 1 to send.')
            ->expectsOutputToContain('Nothing was sent.')
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_resets')->count());
    }

    /**
     * Sent to the people the import CREATED, once, with a week to use it — and
     * never to a matched person, who already has a password they use.
     */
    public function test_the_welcome_goes_to_new_people_once_and_not_to_matched_ones(): void
    {
        Notification::fake();

        $matched = $this->userWithRole($this->t, 'Marketing', ['customers.view'], 'eli@example.com');

        $this->bundle([
            $this->newPerson(),
            $this->newPerson(['email' => 'eli@example.com', 'a1_user_ids' => [40], 'a1_role' => 'dispatcher']),
        ], []);
        $this->import();

        $this->artisan('hr:send-a1-welcome', ['--organization' => $this->t['org']->name, '--send' => true])
            ->assertSuccessful();

        Notification::assertSentOnDemandTimes(WelcomeSetPassword::class, 1);
        Notification::assertSentOnDemand(WelcomeSetPassword::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'jon.ames@example.com');

        $expires = Carbon::parse(DB::table('password_resets')->where('subject_id', $this->jon()->id)->value('expires_at'));
        $this->assertTrue($expires->greaterThan(now()->addDays(6)), 'The link does not last the week.');
        $this->assertSame(0, DB::table('password_resets')->where('subject_id', $matched->id)->count());

        // A second --send mails nobody again, and leaves Jon's link alive.
        $this->artisan('hr:send-a1-welcome', ['--organization' => $this->t['org']->name, '--send' => true])
            ->expectsOutputToContain('1 already sent one; 0 to send.')
            ->assertSuccessful();

        Notification::assertSentOnDemandTimes(WelcomeSetPassword::class, 1);
        $this->assertSame(1, DB::table('password_resets')->whereNull('used_at')->count());
    }

    /**
     * A1's own period words are accepted: the exporter sends salaries as A1
     * has them, "weekly", and refusing the run over a spelling would stop all
     * 76 people for a word the importer can read.
     */
    public function test_a1_period_words_are_read_as_ezbizi_periods(): void
    {
        $this->bundle([$this->newPerson(['pay_rates' => [
            ['basis' => 'salary', 'amount' => '950', 'period' => 'weekly', 'effective_on' => '2026-01-05', 'note' => null],
            ['basis' => 'hourly', 'amount' => '25', 'period' => 'hourly', 'effective_on' => '2025-06-02', 'note' => null],
        ]])], []);

        $this->assertSame(0, $this->import());

        $periods = $this->inTenant($this->t['org'], fn () => PayRate::query()
            ->orderBy('effective_on')->pluck('period')->all());

        $this->assertSame(['hour', 'week'], $periods);
    }

    /** An unknown period is still refused — only A1's known words are mapped. */
    public function test_an_unknown_pay_period_still_refuses(): void
    {
        $this->bundle([$this->newPerson(['pay_rates' => [
            ['basis' => 'salary', 'amount' => '950', 'period' => 'fortnightly', 'effective_on' => '2026-01-05', 'note' => null],
        ]])], []);

        $this->artisan('hr:import-a1-tools', ['bundle' => $this->dir])->assertFailed();
    }

    /** A role with no mapping refuses a NEW person — never a guessed role. */
    public function test_an_unmapped_role_refuses_a_new_person(): void
    {
        $this->bundle([$this->newPerson(['a1_role' => 'administrator'])], []);

        $this->artisan('hr:import-a1-tools', ['bundle' => $this->dir])
            ->expectsOutputToContain("A1 role 'administrator' has no EzBizi role in the mapping.")
            ->assertFailed();
    }
}
