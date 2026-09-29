<?php

namespace App\Support\Hr;

use App\Models\Assignment;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\EmergencyContact;
use App\Models\Franchise;
use App\Models\HeldEquipment;
use App\Models\HrNote;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\Organization;
use App\Models\PayRate;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Contracts\FieldType;
use App\Support\Contracts\FilledBy;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\WorkSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A1 Tools' current staff into EzBizi (docs/A1-TOOLS-PEOPLE-MIGRATION.md).
 *
 * The bundle's format, the mapping tables below, and how a historical
 * onboarding is stored are all written down in that spec's HR section; this
 * class is the code for it, and the spec is the part to read first.
 *
 * ── Two passes, and the first one can refuse everything ──────────
 *
 * `plan()` reads the whole bundle and checks every file's size and sha256,
 * every key, date, role, place and schedule, and against the database decides
 * who is new, who is matched and who is already imported. It writes nothing,
 * which is what `--dry-run` runs. A problem anywhere in it refuses the whole
 * run: an export with a typo in one key would otherwise become one person
 * quietly missing a field, and nobody would look.
 *
 * `run()` then writes, one transaction per person. A person whose write fails
 * is reported and the run carries on; files already copied for them are
 * deleted again, so a rolled-back person leaves nothing on disk.
 *
 * ── Nothing about a person is ever printed ────────────────────────
 *
 * People are named by their A1 account ids in everything this reports. The
 * bundle holds decrypted SSNs and bank numbers, and the desk reads this output
 * in a terminal that is not a place for any of them.
 */
final class A1ToolsPeopleImport
{
    public const FORMAT = 'ezbizi.a1-people/1';

    public const CONTRACT_NAME = 'A1 Tools record (imported)';

    /** A1 account role key => EzBizi role name (spec, "Roles"). */
    public const ROLES = [
        'technician'          => 'Technician',
        'dispatcher'          => 'Dispatcher',
        'remote_dispatcher'   => 'Dispatcher',
        'customer_experience' => 'Dispatcher',
        'local_manager'       => 'Local Manager',
        'marketing'           => 'Marketing',
        'accounting_manager'  => 'Accounting',
        'developer'           => 'Administrator',
        'management'          => 'Recruiter',
        'training'            => 'Training',
    ];

    /** A1 company name => EzBizi company name (spec, G1). */
    public const COMPANIES = [
        'A1 Chimney'            => 'A-1 Chimney',
        'Everdoors'             => 'Everdoors',
        'Roofirst'              => 'Roofirst',
        'Crestline'             => 'Crestline Home',
        'Premier Garage Repair' => 'Premier Garage Repair',
        'Speedy Garage Doors'   => 'Speedy Garage Doors',
        'Air 75'                => 'Air 75',
        'AngiSweep'             => 'AngiSweep',
        'A1 Garage Doors'       => 'A1 Garage Doors',
        'Chimney Star'          => 'Chimney Star',
    ];

    /** Every A1 franchise sits under A1 Chimney, and matches only there (G2). */
    public const FRANCHISE_COMPANY = 'A-1 Chimney';

    /** A1 franchise code => EzBizi franchise name under A-1 Chimney (G2). */
    public const FRANCHISES = [
        'CT'         => 'Connecticut',
        'GA'         => 'Georgia',
        'IL'         => 'Illinois',
        'NJ'         => 'New Jersey',
        'OH'         => 'Ohio',
        'TX'         => 'Texas',
        'DMV'        => 'DMV',
        'NY'         => 'New York',
        'Nationwide' => 'Nationwide',
    ];

    /** Answer key => [label, sensitive]. */
    public const ANSWERS = [
        'job_title'           => ['Job title in A1 Tools', false],
        'tax_id_type'         => ['Tax ID type', false],
        'ssn'                 => ['Social Security number', true],
        'ein'                 => ['EIN', true],
        'ein_company_name'    => ['EIN company name', false],
        'bank_name'           => ['Bank name', false],
        'bank_address'        => ['Bank address', false],
        'bank_account_name'   => ['Name on the bank account', false],
        'bank_account_number' => ['Bank account number', true],
        'bank_routing_number' => ['Bank routing number', true],
        'whatsapp_phone'      => ['WhatsApp number', false],
        'csia_number'         => ['CSIA number', false],
        'vehicle_plate'       => ['Personal vehicle plate', false],
    ];

    /** Document kind => [label, sensitive]. `other` is filed as other_1 … other_10. */
    public const DOCUMENTS = [
        'drivers_license_front' => ['Driver licence (front)', true],
        'drivers_license_back'  => ['Driver licence (back)', true],
        'id_document'           => ['Passport or ID', true],
        'w2'                    => ['W-2', true],
        'w4'                    => ['W-4', true],
        'signed_contract'       => ['Signed contract (A1 Tools PDF)', true],
        'uniform_photo'         => ['Uniform photo', false],
        'car_front'             => ['Personal car (front)', false],
        'car_back'              => ['Personal car (back)', false],
        'car_left'              => ['Personal car (left side)', false],
        'car_right'             => ['Personal car (right side)', false],
        'car_interior'          => ['Personal car (interior)', false],
        'car_mileage'           => ['Personal car (mileage)', false],
    ];

    public const OTHER_SLOTS = 10;

    /** A1's period words => EzBizi's. EzBizi's own words pass through unchanged. */
    private const PERIOD_SPELLINGS = [
        'hourly'   => 'hour',
        'weekly'   => 'week',
        'monthly'  => 'month',
        'yearly'   => 'year',
        'annual'   => 'year',
        'annually' => 'year',
    ];

    private const TOP_KEYS = ['format', 'exported_at', 'organization', 'files', 'people'];

    private const PERSON_KEYS = [
        'email', 'a1_user_ids', 'first_name', 'middle_name', 'last_name', 'phone', 'address',
        'date_of_birth', 'gender', 'a1_role', 'scope', 'started_on', 'work_schedule',
        'lock_screen_exempt', 'avatar', 'onboarding', 'pay_rates', 'emergency_contacts',
        'hr_notes', 'equipment',
    ];

    private const ONBOARDING_KEYS = ['submitted_at', 'answers', 'documents'];

    private const AVATAR_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var string[] */
    private array $errors = [];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FileStore $files,
        private readonly AuditLogger $audit,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Pass one: read and check everything, write nothing
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array{errors: string[], organization: ?Organization, people: list<array>, files: array<string, array>, dir: string}
     */
    public function plan(string $dir): array
    {
        $this->errors = [];
        $dir = rtrim($dir, '/\\');
        $empty = ['errors' => [], 'organization' => null, 'people' => [], 'files' => [], 'dir' => $dir];

        $json = is_file("{$dir}/people.json") ? json_decode((string) file_get_contents("{$dir}/people.json"), true) : null;

        if (! is_array($json)) {
            return ['errors' => ["No readable people.json in {$dir}."]] + $empty;
        }

        foreach (array_diff(array_keys($json), self::TOP_KEYS) as $unknown) {
            $this->errors[] = "people.json has a key this importer does not know: {$unknown}.";
        }

        if (($json['format'] ?? null) !== self::FORMAT) {
            $this->errors[] = 'people.json is not format '.self::FORMAT.'.';
        }

        $organization = Organization::query()->where('name', $json['organization'] ?? '')->first();

        if ($organization === null) {
            $this->errors[] = 'The organization named in people.json does not exist.';

            return ['errors' => $this->errors] + $empty;
        }

        $this->tenant->set($organization);

        $files = $this->checkFiles($dir, $json['files'] ?? null);
        $places = $this->places($organization);
        $roles = Role::query()->pluck('id', 'name');

        $existing = User::query()->get(['id', 'email'])
            ->keyBy(fn (User $u) => mb_strtolower(trim((string) $u->email)));

        $imported = $this->importedUserIds($organization);

        $people = [];
        $seen = [];

        foreach (is_array($json['people'] ?? null) ? $json['people'] : [] as $i => $raw) {
            $person = $this->checkPerson($raw, $i, $files, $places, $roles, $existing, $imported, $seen);

            if ($person !== null) {
                $people[] = $person;
            }
        }

        if (! is_array($json['people'] ?? null) || $json['people'] === []) {
            $this->errors[] = 'people.json lists nobody.';
        }

        return [
            'errors'       => $this->errors,
            'organization' => $organization,
            'people'       => $people,
            'files'        => $files,
            'dir'          => $dir,
        ];
    }

    /** @return array<string, array{sha256: string, bytes: int, mime: string}> */
    private function checkFiles(string $dir, mixed $list): array
    {
        if (! is_array($list)) {
            $this->errors[] = 'people.json has no files list.';

            return [];
        }

        $files = [];

        foreach ($list as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : null;

            if (! is_string($path) || ! str_starts_with($path, 'files/') || str_contains($path, '..')) {
                $this->errors[] = 'A files entry has a path outside files/.';

                continue;
            }

            $full = "{$dir}/{$path}";

            if (! is_file($full)) {
                $this->errors[] = "Listed but missing: {$path}.";

                continue;
            }

            if (filesize($full) !== ($entry['bytes'] ?? null)) {
                $this->errors[] = "Wrong size: {$path}.";

                continue;
            }

            $sha = hash_file('sha256', $full);

            if ($sha !== ($entry['sha256'] ?? null)) {
                $this->errors[] = "Checksum does not match: {$path}.";

                continue;
            }

            $files[$path] = ['sha256' => $sha, 'bytes' => (int) $entry['bytes'], 'mime' => (string) ($entry['mime'] ?? 'application/octet-stream')];
        }

        return $files;
    }

    /** @return array{companies: array<string, string>, franchises: array<string, string>} */
    private function places(Organization $organization): array
    {
        $companies = Company::query()->pluck('id', 'name')->all();
        $home = $companies[self::FRANCHISE_COMPANY] ?? null;

        if ($home === null) {
            $this->errors[] = 'The organization has no company called '.self::FRANCHISE_COMPANY.'.';
        }

        // Under A-1 Chimney ONLY: Connecticut alone exists under eight companies.
        $franchises = $home === null ? [] : Franchise::query()
            ->where('company_id', $home)
            ->pluck('id', 'name')
            ->all();

        return ['companies' => $companies, 'franchises' => $franchises];
    }

    /** People who already have an Onboarding on the A1 record contract. */
    private function importedUserIds(Organization $organization): array
    {
        $contract = Contract::query()->where('name', self::CONTRACT_NAME)->first();

        if ($contract === null) {
            return [];
        }

        return Onboarding::query()
            ->whereIn('contract_version_id', ContractVersion::query()->where('contract_id', $contract->id)->pluck('id'))
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->flip()
            ->all();
    }

    private function checkPerson(
        mixed $raw, int $i, array $files, array $places, $roles, $existing, array $imported, array &$seen,
    ): ?array {
        $label = 'person '.($i + 1);

        if (! is_array($raw)) {
            $this->errors[] = "{$label} is not an object.";

            return null;
        }

        $ids = array_values(array_filter((array) ($raw['a1_user_ids'] ?? []), 'is_int'));
        $label = $ids === [] ? $label : 'A1 #'.implode('/#', $ids);
        $before = count($this->errors);

        foreach (array_diff(array_keys($raw), self::PERSON_KEYS) as $unknown) {
            $this->errors[] = "{$label}: unknown key {$unknown}.";
        }

        $email = mb_strtolower(trim((string) ($raw['email'] ?? '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "{$label}: no valid email.";
        } elseif (isset($seen[$email])) {
            $this->errors[] = "{$label}: the same email as {$seen[$email]}; two A1 accounts must be one object.";
        } else {
            $seen[$email] = $label;
        }

        $user = $existing->get($email);
        $status = $user === null ? 'new' : (isset($imported[$user->id]) ? 'already_imported' : 'matched');

        $person = [
            'label'     => $label,
            'status'    => $status,
            'user_id'   => $user?->id,
            'email'     => $email,
            'personal'  => [
                'first_name'    => $this->text($raw, 'first_name', $label, 60),
                'middle_name'   => $this->text($raw, 'middle_name', $label, 60),
                'last_name'     => $this->text($raw, 'last_name', $label, 60),
                'phone'         => $this->text($raw, 'phone', $label, 40),
                'address'       => $this->text($raw, 'address', $label, 500),
                'gender'        => $this->text($raw, 'gender', $label, 40),
                'date_of_birth' => $this->date($raw['date_of_birth'] ?? null, "{$label}: date_of_birth"),
            ],
            'started_on'  => $this->date($raw['started_on'] ?? null, "{$label}: started_on"),
            'exempt'      => (bool) ($raw['lock_screen_exempt'] ?? false),
            'schedule'    => $this->schedule($raw['work_schedule'] ?? null, $label),
            'avatar'      => $this->avatar($raw['avatar'] ?? null, $files, $label),
            'onboarding'  => $this->onboarding($raw['onboarding'] ?? null, $files, $label),
            'pay_rates'   => $this->payRates($raw['pay_rates'] ?? [], $label),
            'contacts'    => $this->contacts($raw['emergency_contacts'] ?? [], $label),
            'notes'       => $this->notes($raw['hr_notes'] ?? [], $label),
            'equipment'   => $this->equipment($raw['equipment'] ?? [], $label),
            'role_id'     => null,
            'assignments' => [],
        ];

        // Role and places matter only for somebody being created: a matched
        // person's EzBizi role and assignments are authoritative (spec, G3).
        if ($status === 'new') {
            if ($person['personal']['first_name'] === null || $person['personal']['last_name'] === null) {
                $this->errors[] = "{$label}: a new person needs a first and last name.";
            }

            $a1Role = (string) ($raw['a1_role'] ?? '');
            $roleName = self::ROLES[$a1Role] ?? null;

            if ($roleName === null) {
                $this->errors[] = "{$label}: A1 role '{$a1Role}' has no EzBizi role in the mapping.";
            } elseif (! isset($roles[$roleName])) {
                $this->errors[] = "{$label}: the role {$roleName} does not exist yet in this organization.";
            } else {
                $person['role_id'] = $roles[$roleName];
            }

            $person['assignments'] = $this->assignments($raw['scope'] ?? null, $places, $label);
        }

        return count($this->errors) === $before ? $person : null;
    }

    private function text(array $raw, string $key, string $label, int $max): ?string
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $this->errors[] = "{$label}: {$key} is not text.";

            return null;
        }

        $value = trim($value);

        if (mb_strlen($value) > $max) {
            $this->errors[] = "{$label}: {$key} is longer than {$max} characters.";
        }

        return $value === '' ? null : $value;
    }

    private function date(mixed $value, string $where): ?string
    {
        if ($value === null) {
            return null;
        }

        $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $this->errors[] = "{$where} is not a YYYY-MM-DD date.";

            return null;
        }

        return $value;
    }

    private function moment(mixed $value, string $where): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc()->toDateTimeString();
        } catch (Throwable) {
            $this->errors[] = "{$where} is not a date and time.";

            return null;
        }
    }

    /** A1's `{monday: {start, end, off}}` into EzBizi's `{timezone, days}`. */
    private function schedule(mixed $raw, string $label): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            $this->errors[] = "{$label}: work_schedule is not an object.";

            return null;
        }

        $days = [];

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $window = $raw[$day] ?? null;
            $off = ! is_array($window) || ! empty($window['off'])
                || ($window['start'] ?? '') === '' || ($window['end'] ?? '') === '';

            $days[$day] = $off ? null : [
                'start' => substr((string) $window['start'], 0, 5),
                'end'   => substr((string) $window['end'], 0, 5),
            ];
        }

        try {
            return ['timezone' => 'America/New_York', 'days' => WorkSchedule::validate($days)];
        } catch (ValidationException $e) {
            $this->errors[] = "{$label}: work_schedule — ".collect($e->errors())->flatten()->first();

            return null;
        }
    }

    private function avatar(mixed $path, array $files, string $label): ?string
    {
        if ($path === null) {
            return null;
        }

        if (! is_string($path) || ! isset($files[$path])) {
            $this->errors[] = "{$label}: avatar names a file that is not in the files list.";

            return null;
        }

        if (! in_array($files[$path]['mime'], self::AVATAR_MIMES, true)) {
            $this->errors[] = "{$label}: avatar is not a JPEG, PNG or WebP.";

            return null;
        }

        return $path;
    }

    private function onboarding(mixed $raw, array $files, string $label): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            $this->errors[] = "{$label}: onboarding is not an object.";

            return null;
        }

        foreach (array_diff(array_keys($raw), self::ONBOARDING_KEYS) as $unknown) {
            $this->errors[] = "{$label}: onboarding has an unknown key {$unknown}.";
        }

        $answers = [];

        foreach ((array) ($raw['answers'] ?? []) as $key => $value) {
            if (! isset(self::ANSWERS[$key])) {
                $this->errors[] = "{$label}: answer key {$key} is not one of the thirteen.";

                continue;
            }

            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            if (! is_string($value)) {
                $this->errors[] = "{$label}: answer {$key} is not text.";

                continue;
            }

            $answers[$key] = trim($value);
        }

        $documents = [];
        $others = 0;

        foreach ((array) ($raw['documents'] ?? []) as $document) {
            $kind = is_array($document) ? ($document['kind'] ?? null) : null;
            $file = is_array($document) ? ($document['file'] ?? null) : null;

            if ($kind !== 'other' && ! isset(self::DOCUMENTS[$kind])) {
                $this->errors[] = "{$label}: document kind ".json_encode($kind).' is not one of the kinds.';

                continue;
            }

            if (! is_string($file) || ! isset($files[$file])) {
                $this->errors[] = "{$label}: a document names a file that is not in the files list.";

                continue;
            }

            // A second of a kind, and every "other", takes the next other slot.
            $slot = $kind !== 'other' && ! isset($documents[$kind]) ? $kind : 'other_'.(++$others);

            if ($others > self::OTHER_SLOTS) {
                $this->errors[] = "{$label}: more than ".self::OTHER_SLOTS.' extra documents; none is dropped silently.';

                break;
            }

            $documents[$slot] = [
                'file' => $file,
                'name' => mb_substr((string) ($document['original_name'] ?? basename($file)), 0, 255),
            ];
        }

        return [
            'submitted_at' => $this->moment($raw['submitted_at'] ?? null, "{$label}: onboarding.submitted_at"),
            'answers'      => $answers,
            'documents'    => $documents,
        ];
    }

    private function payRates(mixed $list, string $label): array
    {
        $rates = [];

        foreach ($this->listOf($list, "{$label}: pay_rates") as $rate) {
            $amount = $rate['amount'] ?? null;

            // A1 spells its periods as adverbs ("weekly"); the exporter passes
            // them through as A1 has them, which is the rule the spec gives
            // for roles and places too. Refusing the whole run over a spelling
            // would be the importer being right and useless.
            $rate['period'] = self::PERIOD_SPELLINGS[$rate['period'] ?? ''] ?? ($rate['period'] ?? null);

            if (! in_array($rate['basis'] ?? null, PayRate::BASES, true)
                || ! in_array($rate['period'] ?? null, PayRate::PERIODS, true)
                || ! is_numeric($amount) || (float) $amount < 0) {
                $this->errors[] = "{$label}: a pay rate needs a basis, a period and a non-negative amount.";

                continue;
            }

            $on = $this->date($rate['effective_on'] ?? null, "{$label}: pay rate effective_on");

            if ($on === null) {
                $this->errors[] = "{$label}: a pay rate needs an effective_on date.";

                continue;
            }

            $rates[] = [
                'basis'        => $rate['basis'],
                'amount'       => (string) $amount,
                'period'       => $rate['period'],
                'effective_on' => $on,
                'note'         => is_string($rate['note'] ?? null) ? mb_substr(trim($rate['note']), 0, 1000) : null,
            ];
        }

        return $rates;
    }

    private function contacts(mixed $list, string $label): array
    {
        $contacts = [];

        foreach ($this->listOf($list, "{$label}: emergency_contacts") as $contact) {
            $name = is_string($contact['name'] ?? null) ? trim($contact['name']) : '';

            if ($name === '') {
                $this->errors[] = "{$label}: an emergency contact has no name.";

                continue;
            }

            $contacts[] = [
                'name'         => mb_substr($name, 0, 120),
                'relationship' => is_string($contact['relationship'] ?? null) ? mb_substr(trim($contact['relationship']), 0, 60) : null,
                'phone'        => is_string($contact['phone'] ?? null) ? mb_substr(trim($contact['phone']), 0, 40) : null,
            ];
        }

        return $contacts;
    }

    private function notes(mixed $list, string $label): array
    {
        if (! is_array($list)) {
            $this->errors[] = "{$label}: hr_notes is not a list.";

            return [];
        }

        return array_values(array_filter(array_map(
            fn ($note) => is_string($note) && trim($note) !== '' ? mb_substr(trim($note), 0, 5000) : null,
            $list,
        )));
    }

    private function equipment(mixed $list, string $label): array
    {
        $items = [];

        foreach ($this->listOf($list, "{$label}: equipment") as $item) {
            $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';

            if ($name === '' || ! in_array($item['category'] ?? null, HeldEquipment::CATEGORIES, true)) {
                $this->errors[] = "{$label}: an equipment item needs a name and a category of tool or equipment.";

                continue;
            }

            $items[] = [
                'name'        => mb_substr($name, 0, 160),
                'category'    => $item['category'],
                'quantity'    => max(1, (int) ($item['quantity'] ?? 1)),
                'identifier'  => is_string($item['identifier'] ?? null) ? mb_substr(trim($item['identifier']), 0, 120) : null,
                'issued_on'   => $this->date($item['issued_on'] ?? null, "{$label}: equipment issued_on"),
                'returned_on' => $this->date($item['returned_on'] ?? null, "{$label}: equipment returned_on"),
                'note'        => is_string($item['note'] ?? null) ? mb_substr(trim($item['note']), 0, 1000) : null,
            ];
        }

        return $items;
    }

    private function listOf(mixed $list, string $where): array
    {
        if (! is_array($list) || ! array_is_list($list)) {
            $this->errors[] = "{$where} is not a list.";

            return [];
        }

        return array_filter($list, 'is_array');
    }

    /** @return list<array{assigned_organization_id: ?string, assigned_company_id: ?string, assigned_franchise_id: ?string}> */
    private function assignments(mixed $scope, array $places, string $label): array
    {
        $type = is_array($scope) ? ($scope['type'] ?? null) : null;
        $none = ['assigned_organization_id' => null, 'assigned_company_id' => null, 'assigned_franchise_id' => null];

        if ($type === 'organization') {
            return [['assigned_organization_id' => $this->tenant->idOrFail()] + $none];
        }

        if ($type === 'companies' || $type === 'franchises') {
            $names = $scope[$type] ?? null;
            $map = $type === 'companies' ? self::COMPANIES : self::FRANCHISES;
            $ids = $type === 'companies' ? $places['companies'] : $places['franchises'];
            $column = $type === 'companies' ? 'assigned_company_id' : 'assigned_franchise_id';

            if (! is_array($names) || $names === []) {
                $this->errors[] = "{$label}: a {$type} scope lists none.";

                return [];
            }

            $rows = [];

            foreach ($names as $name) {
                $id = $ids[$map[$name] ?? ''] ?? null;

                if ($id === null) {
                    $this->errors[] = "{$label}: {$type} '{$name}' does not map to a place in this organization.";

                    continue;
                }

                $rows[$id] = [$column => $id] + $none;
            }

            return array_values($rows);
        }

        $this->errors[] = "{$label}: scope must be organization, companies or franchises.";

        return [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pass two: write, one person at a time
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  callable(string): void  $say
     * @return array{created: int, updated: int, skipped: int, failed: int}
     */
    public function run(array $plan, callable $say): array
    {
        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        $this->tenant->set($plan['organization']);
        $version = $this->recordVersion();

        foreach ($plan['people'] as $person) {
            if ($person['status'] === 'already_imported') {
                $say("{$person['label']}: already imported, left as it is.");
                $totals['skipped']++;

                continue;
            }

            $copied = [];

            try {
                DB::transaction(function () use ($person, $plan, $version, &$copied) {
                    $this->writePerson($person, $plan, $version, $copied);
                });

                $say("{$person['label']}: {$person['status']} — ".$this->summary($person));
                $totals[$person['status'] === 'new' ? 'created' : 'updated']++;
            } catch (Throwable $e) {
                foreach ($copied as $path) {
                    $this->files->delete($path);
                }

                // The exception's class only, never its message: a message can
                // carry a value from the row that was being written.
                $say("{$person['label']}: FAILED (".$e::class.' at '.basename($e->getFile()).':'
                    .$e->getLine().') — nothing of theirs was kept.');
                $totals['failed']++;
            }
        }

        return $totals;
    }

    public function summary(array $person): string
    {
        $o = $person['onboarding'];

        return sprintf(
            '%d answers, %d documents, %d pay rates, %d contacts, %d notes, %d equipment%s%s%s',
            $o === null ? 0 : count($o['answers']),
            $o === null ? 0 : count($o['documents']),
            count($person['pay_rates']),
            count($person['contacts']),
            count($person['notes']),
            count($person['equipment']),
            $person['avatar'] ? ', photo' : '',
            $person['schedule'] ? ', schedule' : '',
            $person['started_on'] ? ', start date' : '',
        );
    }

    private function writePerson(array $person, array $plan, ContractVersion $version, array &$copied): void
    {
        $user = $person['status'] === 'new'
            ? $this->createUser($person)
            : User::query()->findOrFail($person['user_id']);

        if ($person['status'] === 'matched') {
            $this->fillGaps($user, $person);
        }

        if ($person['avatar'] !== null && $user->avatar_path === null) {
            $path = $this->copy($plan, $person['avatar'], 'avatars');
            $copied[] = $path;
            $user->forceFill(['avatar_path' => $path])->save();
        }

        if ($person['started_on'] !== null) {
            StartDate::record($user, $person['started_on']);
        }

        $this->writeOnboarding($user, $person, $plan, $version, $copied);

        foreach ($person['pay_rates'] as $rate) {
            PayRate::create($rate + ['user_id' => $user->id]);
        }

        foreach ($person['contacts'] as $contact) {
            EmergencyContact::create($contact + ['user_id' => $user->id]);
        }

        foreach ($person['notes'] as $body) {
            HrNote::create(['user_id' => $user->id, 'body' => $body]);
        }

        foreach ($person['equipment'] as $item) {
            HeldEquipment::create($item + ['user_id' => $user->id]);
        }

        // THAT they were imported, and by the import; never what was in it.
        $this->audit->log('person.imported_from_a1', $user, metadata: ['status' => $person['status']]);
    }

    private function createUser(array $person): User
    {
        $p = $person['personal'];

        $user = new User;
        $user->forceFill([
            'role_id'            => $person['role_id'],
            'email'              => $person['email'],
            'first_name'         => $p['first_name'],
            'middle_name'        => $p['middle_name'],
            'last_name'          => $p['last_name'],
            'name'               => trim(implode(' ', array_filter([$p['first_name'], $p['middle_name'], $p['last_name']]))),
            'phone'              => $p['phone'],
            'address'            => $p['address'],
            'gender'             => $p['gender'],
            'date_of_birth'      => $p['date_of_birth'],
            'is_active'          => true,
            // Random, hashed by the model's cast, and never shown: nobody can
            // sign in with it. The only way in is the "set your password" link,
            // which is sent to this address — and that is also why the address
            // is marked verified here. Somebody who cannot read mail at it can
            // never get in, so there is nothing for verification to protect,
            // and without it they could sign in and then not clock in.
            'password'           => Str::random(64),
            'email_verified_at'  => now(),
            'lock_screen_exempt' => $person['exempt'],
            'work_schedule'      => $person['schedule'],
        ])->save();

        foreach ($person['assignments'] as $columns) {
            Assignment::create($columns + ['user_id' => $user->id]);
        }

        return $user;
    }

    /**
     * A matched person keeps what EzBizi already knows. Only an empty field is
     * filled, and role, email and assignments are never touched: EzBizi's are
     * the ones somebody set on purpose (spec, G3).
     */
    private function fillGaps(User $user, array $person): void
    {
        foreach ($person['personal'] as $key => $value) {
            if ($value !== null && blank($user->getAttribute($key))) {
                $user->setAttribute($key, $value);
            }
        }

        if ($user->isDirty(['first_name', 'middle_name', 'last_name'])) {
            $user->name = trim(implode(' ', array_filter([$user->first_name, $user->middle_name, $user->last_name])));
        }

        if ($person['schedule'] !== null && $user->work_schedule === null) {
            $user->work_schedule = $person['schedule'];
        }

        $user->save();
    }

    private function writeOnboarding(User $user, array $person, array $plan, ContractVersion $version, array &$copied): void
    {
        $o = $person['onboarding'] ?? ['submitted_at' => null, 'answers' => [], 'documents' => []];
        $when = $o['submitted_at'] ?? now()->toDateTimeString();

        $onboarding = Onboarding::create([
            'invitation_id'       => null,
            'user_id'             => $user->id,
            'contract_version_id' => $version->id,
            'status'              => Onboarding::COMPLETED,
            'started_at'          => $when,
            'submitted_at'        => $o['submitted_at'],
            'completed_at'        => $when,
            'started_by_user_id'  => null,
        ]);

        foreach ($o['answers'] as $key => $value) {
            OnboardingAnswer::create([
                'onboarding_id' => $onboarding->id,
                'field_key'     => $key,
                'value'         => $value,
                'set_at'        => $when,
            ]);
        }

        foreach ($o['documents'] as $slot => $document) {
            $path = $this->copy($plan, $document['file'], 'attachments');
            $copied[] = $path;
            $file = $plan['files'][$document['file']];

            $attachment = new Attachment([
                'attachable_type' => 'onboardings',
                'attachable_id'   => $onboarding->id,
                'original_name'   => $document['name'],
                'mime'            => mb_substr($file['mime'], 0, 160),
            ]);
            $attachment->forceFill([
                'disk'       => $this->files->defaultDisk(),
                'path'       => $path,
                'size_bytes' => $file['bytes'],
                'checksum'   => $file['sha256'],
            ])->save();

            OnboardingAnswer::create([
                'onboarding_id' => $onboarding->id,
                'field_key'     => $slot,
                'value'         => $attachment->id,
                'set_at'        => $when,
            ]);
        }
    }

    private function copy(array $plan, string $file, string $kind): string
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION) ?: 'bin';

        return $this->files->putContents(
            (string) file_get_contents("{$plan['dir']}/{$file}"),
            $this->files->directoryFor($kind, $this->tenant->idOrFail()),
            $extension,
        );
    }

    /**
     * The organization's "A1 Tools record (imported)" contract and its one
     * version, made the first time and found every time after. Archived at
     * birth: it is a record of the past, not something to start paperwork from.
     */
    public function recordVersion(): ContractVersion
    {
        $contract = Contract::query()->where('name', self::CONTRACT_NAME)->first();

        if ($contract?->currentVersion !== null) {
            return $contract->currentVersion;
        }

        return DB::transaction(function () use ($contract) {
            if ($contract === null) {
                $contract = new Contract([
                    'name'        => self::CONTRACT_NAME,
                    'description' => 'What A1 Tools collected about each person before they moved to '
                        .'EzBizi. Kept as it was; not used for new onboardings.',
                ]);
                $contract->forceFill(['archived_at' => now()])->save();
            }

            $fields = [];

            foreach (self::ANSWERS as $key => [$label, $sensitive]) {
                $fields[] = $this->field($key, $label, FieldType::TEXT, $sensitive);
            }

            foreach (self::DOCUMENTS as $key => [$label, $sensitive]) {
                $fields[] = $this->field($key, $label, FieldType::FILE, $sensitive);
            }

            for ($n = 1; $n <= self::OTHER_SLOTS; $n++) {
                $fields[] = $this->field("other_{$n}", "Other document {$n}", FieldType::FILE, true);
            }

            $version = ContractVersion::create([
                'contract_id' => $contract->id,
                'version'     => 1,
                'fields'      => $fields,
                'body'        => null,
                'notes'       => 'Created by the A1 Tools people import.',
            ]);

            $contract->forceFill(['current_version_id' => $version->id])->save();

            return $version;
        });
    }

    private function field(string $key, string $label, string $type, bool $sensitive): array
    {
        return [
            'key'       => $key,
            'label'     => $label,
            'type'      => $type,
            'options'   => [],
            'filled_by' => FilledBy::EMPLOYER,
            'required'  => false,
            'sensitive' => $sensitive,
            'retired'   => false,
            'is_expiry' => false,
        ];
    }
}
