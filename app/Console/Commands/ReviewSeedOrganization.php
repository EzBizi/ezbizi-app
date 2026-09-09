<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\Automation;
use App\Models\AutomationVersion;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\Job;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceGroup;
use App\Models\ServiceLabel;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Fleet\VehicleBodyType;
use App\Support\Fleet\VehicleFuelType;
use App\Support\Fleet\VehicleStatus;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * A demonstration tenant for app-store reviewers.
 *
 * ── Why this exists ─────────────────────────────────────────────
 *
 * Google Play, Apple, TikTok, Meta and the rest all demand a working account
 * with full access, re-used on every submission for as long as the app is
 * listed. The only accounts on this platform belong to A1-Group and hold real
 * customers, real jobs and real money. Handing those to an anonymous reviewer,
 * permanently, is not a thing to do — so reviewers get their own organization,
 * full of invented people, and the real tenant is never involved.
 *
 * ── Why the accounts are ordinary users, not staff ──────────────
 *
 * Staff accounts are the control plane and their second factor is mandatory —
 * `an account without a confirmed authenticator cannot sign in at all`. A
 * reviewer cannot hold an authenticator, so a staff account can never be given
 * to one. These are plain customer users: no staff link, no TOTP, and the
 * organization does not set `security.require_two_factor` — which together are
 * exactly the three conditions AuthenticateUser checks before demanding a code.
 * Do not link any of these to a staff record; that alone would break every
 * review by turning the login into a staff login.
 *
 * ── Why the trial never ends ────────────────────────────────────
 *
 * `Organization::isReadOnly()` is true once `trial_ends_at` is past, and a
 * read-only app fails a review with "the buttons do nothing". Reviews happen on
 * somebody else's schedule, months apart, so a date that has to be remembered is
 * a date that will be forgotten. Null never expires.
 *
 * ── What it deliberately does not seed ──────────────────────────
 *
 * Email and Marketing connect through real OAuth to real accounts. There is no
 * honest way to fabricate a working mailbox or a connected Facebook Page, and a
 * broken one is worse than an empty one — an empty module explains itself, a
 * fake one looks like a bug. Phone is the same: `phone_numbers` requires a real
 * Twilio connection. Web Management has no editor yet.
 *
 * Idempotent on the organization slug. Re-running adds nothing and changes no
 * password. To rebuild it, delete the organization and run this again.
 */
class ReviewSeedOrganization extends Command
{
    protected $signature = 'review:seed-organization
                            {--live : Allow the production database}
                            {--force : Skip the confirmation}
                            {--password= : Password for every review account; read from stdin when omitted}';

    protected $description = 'Build the demonstration organization that app-store reviewers sign in to';

    private const ORG_NAME = 'EzBizi Demo';

    /** Every store that has asked, or will ask, for a login. */
    private const REVIEWERS = [
        'android@ezbizi.com'   => 'Android Review',
        'apple@ezbizi.com'     => 'Apple Review',
        'google@ezbizi.com'    => 'Google Review',
        'tiktok@ezbizi.com'    => 'TikTok Review',
        'pinterest@ezbizi.com' => 'Pinterest Review',
        'meta@ezbizi.com'      => 'Meta Review',
        'x@ezbizi.com'         => 'X Review',
        'linkedin@ezbizi.com'  => 'LinkedIn Review',
    ];

    public function handle(TenantContext $tenant, RecordNumberGenerator $numbers): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($database === 'ezbizi' && ! $this->option('live')) {
            $this->error('Refusing to touch production without --live.');

            return self::FAILURE;
        }

        $password = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        if (Organization::where('name', self::ORG_NAME)->exists()) {
            $this->warn(self::ORG_NAME.' already exists. Nothing to do.');
            $this->line('Delete the organization first if you mean to rebuild it.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Create "'.self::ORG_NAME."\" in database [{$database}] with ".count(self::REVIEWERS).' reviewer accounts?'
        )) {
            return self::FAILURE;
        }

        // The first reviewer doubles as the administrator ProvisionOrganization
        // insists on, rather than inventing a ninth account nobody will use.
        $firstEmail = array_key_first(self::REVIEWERS);

        $built = app(ProvisionOrganization::class)(
            organizationName: self::ORG_NAME,
            administrator: [
                'name'     => self::REVIEWERS[$firstEmail],
                'email'    => $firstEmail,
                'password' => $password,
            ],
            companyName:   'Ridgeline Chimney',
            franchiseName: 'Ridgeline — Miami',
            timezone:      'America/New_York',
            isDemo:        true,
        );

        /** @var Organization $org */
        $org = $built['organization'];

        // Never read-only, whenever the next review happens to land.
        $org->forceFill(['trial_ends_at' => null])->save();

        $this->line('  organization  '.$org->name.'  (slug '.$org->slug.')');

        $tenant->run($org, function () use ($org, $built, $password, $numbers) {
            $tree = $this->buildTree($org, $built['company'], $built['franchise']);
            $this->buildReviewers($org, $built['administrator'], $password);
            $this->buildServices($org, $numbers);
            $this->buildCustomersAndJobs($org, $tree, $numbers);
            $this->buildFleet($org, $tree, $numbers);
            $this->buildInspection($org, $built['administrator']);
            $this->buildAutomations($org);
        });

        $this->newLine();
        $this->info('✓ '.self::ORG_NAME.' is ready.');
        $this->line('  Sign in at https://app.ezbizi.com with any of:');
        foreach (array_keys(self::REVIEWERS) as $email) {
            $this->line('    '.$email);
        }
        $this->newLine();
        $this->line('  Inventory has its own seeder — run it next to fill that module:');
        $this->line('    php artisan inventory:seed-demo "Ridgeline Chimney" --live --force');
        $this->newLine();
        $this->line('  Email, Marketing and Phone are deliberately empty: each needs a real');
        $this->line('  OAuth or Twilio connection, and a fake one reads as a bug.');

        return self::SUCCESS;
    }

    /**
     * The password is never an argument by default.
     *
     * `--password=` puts it in shell history and in the process list of every
     * user on the box. Reading stdin lets the caller pipe it from wherever they
     * keep it without either. The flag stays for interactive use where that
     * does not matter.
     */
    private function resolvePassword(): ?string
    {
        $given = $this->option('password');

        if (is_string($given) && $given !== '') {
            return $given;
        }

        $piped = stream_get_contents(STDIN);
        $piped = is_string($piped) ? trim($piped) : '';

        if ($piped === '') {
            $this->error('No password. Pass --password= or pipe one in:');
            $this->line('  printf %s "$PW" | php artisan review:seed-organization --live --force');

            return null;
        }

        if (strlen($piped) < 12) {
            $this->error('That password is under 12 characters. These accounts sit on the public internet for years.');

            return null;
        }

        return $piped;
    }

    /**
     * A second company and three more franchises.
     *
     * One company would demonstrate nothing: the whole shape of this product is
     * that A-1 Chimney and Premier Garage do not see each other while the group
     * above sees both. A reviewer switching the scope picker needs something to
     * switch between, or the switcher looks broken.
     *
     * @return array{companies: Company[], franchises: Franchise[]}
     */
    private function buildTree(Organization $org, Company $first, Franchise $firstFranchise): array
    {
        $second = Company::create([
            'organization_id' => $org->id,
            'name'            => 'Northgate Garage Doors',
            'is_active'       => true,
            'address_line1'   => '4120 NW 12th St',
            'city'            => 'Miami',
            'state'           => 'FL',
            'postal_code'     => '33126',
            'phone'           => '+13055550142',
        ]);

        $franchises = [$firstFranchise];

        foreach ([
            [$first,  'Ridgeline — Fort Lauderdale'],
            [$second, 'Northgate — Miami'],
            [$second, 'Northgate — Orlando'],
        ] as [$company, $name]) {
            $franchises[] = Franchise::create([
                'organization_id' => $org->id,
                'company_id'      => $company->id,
                'name'            => $name,
                'timezone'        => 'America/New_York',
                'currency'        => 'USD',
                'is_active'       => true,
            ]);
        }

        $this->line('  companies     2, franchises 4');

        return ['companies' => [$first, $second], 'franchises' => $franchises];
    }

    /**
     * Every reviewer is an Administrator assigned at organization level.
     *
     * Play asks for "full access" in as many words, and a reviewer who cannot
     * open a module reports it as a broken app rather than as a permission they
     * were not given. The data is invented, so there is nothing here to protect
     * by handing out less.
     */
    private function buildReviewers(Organization $org, User $administrator, string $password): void
    {
        $role = $administrator->role;

        foreach (self::REVIEWERS as $email => $name) {
            if ($email === $administrator->email) {
                continue;
            }

            $user = User::create([
                'organization_id' => $org->id,
                'role_id'         => $role->id,
                'name'            => $name,
                'email'           => $email,
                'password'        => $password,
                'is_active'       => true,
            ]);

            Assignment::create([
                'organization_id'          => $org->id,
                'user_id'                  => $user->id,
                'assigned_organization_id' => $org->id,
            ]);
        }

        $this->line('  accounts      '.count(self::REVIEWERS).' administrators, no second factor');
    }

    private function buildServices(Organization $org, RecordNumberGenerator $numbers): void
    {
        $groups = [];
        foreach (['Chimney', 'Garage doors'] as $name) {
            $groups[$name] = ServiceGroup::create(['organization_id' => $org->id, 'name' => $name]);
        }

        $labels = [];
        foreach (['Popular', 'Seasonal'] as $name) {
            $labels[$name] = ServiceLabel::create(['organization_id' => $org->id, 'name' => $name]);
        }

        $catalogue = [
            ['Chimney sweep',            'A full sweep and a written safety report.',        18900, 'Chimney',      'Popular'],
            ['Chimney cap replacement',  'Stainless cap, fitted and sealed.',                 34500, 'Chimney',      null],
            ['Fireplace inspection',     'Level 2 inspection with camera survey.',            27500, 'Chimney',      'Seasonal'],
            ['Dryer vent cleaning',      'Full duct clean and airflow test.',                 14900, 'Chimney',      null],
            ['Garage door tune-up',      'Balance, lubricate, and safety-test the opener.',   12900, 'Garage doors', 'Popular'],
            ['Torsion spring replacement', 'Matched pair, fitted and balanced.',              42500, 'Garage doors', null],
            ['Opener installation',      'Belt-drive opener supplied and installed.',         58900, 'Garage doors', null],
            ['Panel replacement',        'Single panel, colour matched.',                     39500, 'Garage doors', 'Seasonal'],
        ];

        foreach ($catalogue as [$name, $description, $price, $group, $label]) {
            Service::create([
                'organization_id'  => $org->id,
                'number'           => $numbers->next(RecordNumberGenerator::SERVICES, $org),
                'name'             => $name,
                'description'      => $description,
                'price_minor'      => $price,
                'service_group_id' => $groups[$group]->id,
                'service_label_id' => $label === null ? null : $labels[$label]->id,
            ]);
        }

        $this->line('  services      '.count($catalogue).' in 2 groups, 2 labels');
    }

    /**
     * Customers spread across all four franchises, each with work booked.
     *
     * Spread deliberately: customers piled onto one franchise would let a
     * reviewer switch scope and see an empty screen, which reads as a bug rather
     * than as the scoping working exactly as designed.
     *
     * @param  array{companies: Company[], franchises: Franchise[]}  $tree
     */
    private function buildCustomersAndJobs(Organization $org, array $tree, RecordNumberGenerator $numbers): void
    {
        $people = [
            ['Marcus Whitfield', 'marcus.whitfield@example.test', '+13055550118'],
            ['Priya Raman',      'priya.raman@example.test',      '+13055550119'],
            ['Denise Okafor',    'denise.okafor@example.test',    '+13055550120'],
            ['Tomas Beckett',    'tomas.beckett@example.test',    '+19545550121'],
            ['Helen Vasquez',    'helen.vasquez@example.test',    '+19545550122'],
            ['Arthur Lindqvist', 'arthur.lindqvist@example.test', '+19545550123'],
            ['Nadia Oyelaran',   'nadia.oyelaran@example.test',   '+13055550124'],
            ['Georgia Palmer',   'georgia.palmer@example.test',   '+13055550125'],
            ['Ruben Castellanos', 'ruben.castellanos@example.test', '+14075550126'],
            ['Miriam Hollowell', 'miriam.hollowell@example.test', '+14075550127'],
            ['Ivan Petrossian',  'ivan.petrossian@example.test',  '+14075550128'],
            ['Sunita Bhattarai', 'sunita.bhattarai@example.test', '+13055550129'],
        ];

        $work = [
            ['Annual sweep and safety report', 'completed'],
            ['Cap replacement after storm',    'scheduled'],
            ['Level 2 camera inspection',      'in_progress'],
            ['Dryer vent blocked — callout',   'scheduled'],
            ['Opener will not close',          'completed'],
            ['Spring snapped, door stuck',     'in_progress'],
        ];

        $franchises = $tree['franchises'];
        $jobCount = 0;

        foreach ($people as $i => [$name, $email, $phone]) {
            $franchise = $franchises[$i % count($franchises)];

            $customer = Customer::create([
                'organization_id'     => $org->id,
                'owning_franchise_id' => $franchise->id,
                'number'              => $numbers->next(RecordNumberGenerator::CUSTOMERS, $franchise),
                'name'                => $name,
                'email'               => $email,
                'phone'               => $phone,
                'is_active'           => true,
            ]);

            // Two apiece, offset so neighbouring customers do not read as copies.
            foreach ([0, 1] as $n) {
                [$title, $status] = $work[($i + $n * 3) % count($work)];

                Job::create([
                    'organization_id' => $org->id,
                    'franchise_id'    => $franchise->id,
                    'customer_id'     => $customer->id,
                    'number'          => $numbers->next(RecordNumberGenerator::JOBS, $franchise),
                    'title'           => $title,
                    'status'          => $status,
                ]);

                $jobCount++;
            }
        }

        $this->line('  customers     '.count($people).' across 4 franchises, '.$jobCount.' jobs');
    }

    /** @param  array{companies: Company[], franchises: Franchise[]}  $tree */
    private function buildFleet(Organization $org, array $tree, RecordNumberGenerator $numbers): void
    {
        $vans = [
            ['Sweep 1',   2021, 'Ford',      'Transit 250',  'RDG-1041', VehicleBodyType::Van],
            ['Sweep 2',   2019, 'Ram',       'ProMaster',    'RDG-1042', VehicleBodyType::Van],
            ['Doors 1',   2022, 'Chevrolet', 'Express 2500', 'NGD-2071', VehicleBodyType::Van],
            ['Doors 2',   2020, 'Ford',      'F-150',        'NGD-2072', VehicleBodyType::Truck],
            ['Surveyor',  2023, 'Toyota',    'RAV4',         'NGD-2073', VehicleBodyType::Suv],
        ];

        foreach ($vans as $i => [$label, $year, $make, $model, $plate, $body]) {
            $company = $tree['companies'][$i < 2 ? 0 : 1];

            Vehicle::create([
                'organization_id' => $org->id,
                'company_id'      => $company->id,
                'number'          => $numbers->next(RecordNumberGenerator::VEHICLES, $company),
                'label'           => $label,
                'year'            => $year,
                'make'            => $make,
                'model'           => $model,
                'plate'           => $plate,
                'body_type'       => $body,
                'fuel_type'       => VehicleFuelType::Gas,
                'status'          => VehicleStatus::Active,
            ]);
        }

        $this->line('  fleet         '.count($vans).' vehicles across 2 companies');
    }

    /**
     * One published inspection, so the module opens onto something.
     *
     * `inspection:seed-demo` cannot help here: it refuses any database whose
     * name lacks "testing", and it seeds customers and jobs rather than a
     * definition. Built by hand instead, and published — an unpublished
     * definition cannot be started, so a reviewer would find a Builder with a
     * draft in it and a New Inspection screen offering nothing.
     *
     * LEFT ORGANIZATION-WIDE ON PURPOSE — `scope_type` stays NULL, and that is
     * not an oversight to be tidied. `InspectionScope` reads NULL as "everyone
     * in this organization", which is exactly right here: the reviewer accounts
     * are created at organization level and an anonymous store reviewer must
     * find this inspection whichever company they land in. Scoping it to one of
     * the demo companies would hide it from a reviewer placed at the other, and
     * the symptom is an empty New Inspection picker during an App Store review
     * — a rejected submission, discovered days later, for a change that looked
     * like a scoping correction.
     *
     * A1-Group's ported inspection is the opposite case and is correctly
     * `scope_type = 'company'`, because it belongs to A-1 Chimney specifically.
     * The two NULLs mean different things and only one of them is a default.
     */
    private function buildInspection(Organization $org, User $publisher): void
    {
        $screens = [
            [
                'name'   => 'Exterior',
                'fields' => [
                    ['key' => 'crown',    'label' => 'Crown condition',     'type' => 'text',   'required' => true],
                    ['key' => 'cap',      'label' => 'Cap fitted and sound', 'type' => 'text',  'required' => false],
                    ['key' => 'flashing', 'label' => 'Flashing sealed',      'type' => 'text',  'required' => false],
                ],
            ],
            [
                'name'   => 'Flue',
                'fields' => [
                    ['key' => 'liner',    'label' => 'Liner condition',      'type' => 'text',  'required' => true],
                    ['key' => 'creosote', 'label' => 'Creosote level',       'type' => 'text',  'required' => true],
                ],
            ],
            [
                'name'   => 'Findings',
                'fields' => [
                    ['key' => 'notes',    'label' => 'Notes for the customer', 'type' => 'text', 'required' => false],
                ],
            ],
        ];

        $definition = InspectionDefinition::create([
            'organization_id' => $org->id,
            'name'            => 'Level 1 Chimney Inspection',
            'layout_preset'   => 'step_strip',
            'screens'         => $screens,
            'intro'           => 'A visual inspection of readily accessible portions of the chimney.',
        ]);

        $version = InspectionDefinitionVersion::create([
            'organization_id'          => $org->id,
            'inspection_definition_id' => $definition->id,
            'version'                  => 1,
            'layout_preset'            => 'step_strip',
            'screens'                  => $screens,
            'intro'                    => $definition->intro,
            'published_by'             => $publisher->id,
        ]);

        // published_at is what makes it startable; current_version_id is which
        // frozen version a new run is taken from. A definition with one and not
        // the other is a draft that claims to be published, or the reverse.
        $definition->forceFill([
            'current_version_id' => $version->id,
            'published_at'       => now(),
        ])->save();

        $this->line('  inspection    1 published definition, 3 screens');
    }

    /**
     * Two rules, switched off.
     *
     * Off because a live rule in a demo tenant fires on the reviewer's own
     * clicking and sends notifications nobody is expecting. Present because an
     * empty Automations tab teaches a reviewer nothing about what the module is.
     */
    private function buildAutomations(Organization $org): void
    {
        $rules = [
            ['Tell the office when a job is booked', 'job.created'],
            ['Flag a customer with no work in 90 days', 'customer.created'],
        ];

        foreach ($rules as [$name, $trigger]) {
            $automation = Automation::create([
                'organization_id' => $org->id,
                'name'            => $name,
                'trigger_key'     => $trigger,
                'is_active'       => false,
            ]);

            // The model, not a raw insert: automation_versions is written once
            // and never edited, so it has created_at and no updated_at, and a
            // hand-built insert guessing at Laravel's usual pair fails on it.
            AutomationVersion::create([
                'organization_id' => $org->id,
                'automation_id'   => $automation->id,
                'version'         => 1,
                'conditions'      => [],
                'actions'         => [],
            ]);
        }

        $this->line('  automations   '.count($rules).' rules, switched off');
    }
}
