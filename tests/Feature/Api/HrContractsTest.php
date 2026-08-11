<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\ContractVersion;
use App\Support\Contracts\DocumentFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Contracts tab — the template designer (EZBIZI-HR-PLAN.md).
 *
 * The plan calls this "the schema for everything else", so most of what is
 * tested here is not the happy path. It is the set of things that must be
 * IMPOSSIBLE, because each one, allowed once, silently breaks records nobody
 * will look at again until somebody disputes their contract:
 *
 *   a key cannot be renamed        an answer is stored against a key
 *   a key cannot be removed        same reason, seen from the other side
 *   a key cannot change type       worse than orphaned — misread
 *   the common core is not redeclarable   two homes for one fact
 *   a signature is attributable    ESIGN, and it is why `either` is refused
 */
class HrContractsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * The smallest legal field. Spelt out here so each test varies one thing.
     *
     * `$overrides + $defaults`, in that order: PHP's array union keeps the LEFT
     * operand on a key collision, so the defaults have to come second or nothing
     * would ever override anything and half of these tests would pass by
     * asserting about a field they did not build.
     */
    private function field(array $overrides = []): array
    {
        return $overrides + [
            'key'       => 'wage',
            'label'     => 'Hourly wage',
            'type'      => 'number',
            'filled_by' => 'employer',
            'required'  => true,
            'sensitive' => false,
            'retired'   => false,
        ];
    }

    /** @return array{0: array, 1: string} the tenant, and a contract id */
    private function aContract(?array $fields = null): array
    {
        $alpha = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', [
                'name'   => 'Driver',
                'fields' => $fields ?? [$this->field()],
            ])
            ->assertCreated()
            ->json('data.id');

        return [$alpha, $id];
    }

    // ── the field list ───────────────────────────────────────────

    public function test_a_contract_is_created_with_its_first_version(): void
    {
        [$alpha, $id] = $this->aContract();

        $body = $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/contracts/{$id}")->assertOk()->json('data');

        $this->assertSame(1, $body['version']);
        $this->assertSame('wage', $body['fields'][0]['key']);
        // Defaults are filled in on the way through, so a client reading a field
        // never has to guess what an absent `sensitive` meant.
        $this->assertFalse($body['fields'][0]['sensitive']);
        $this->assertSame([], $body['fields'][0]['options']);
    }

    public function test_two_fields_cannot_share_a_key(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', [
                'name'   => 'Driver',
                'fields' => [
                    $this->field(),
                    $this->field(['label' => 'Something else', 'type' => 'text']),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.1.key');
    }

    public function test_a_choice_field_needs_options_and_others_may_not_have_any(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', [
                'name'   => 'A',
                'fields' => [$this->field(['key' => 'shift', 'type' => 'choice', 'options' => []])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.options');

        // The mirror image: options on a type that has none are refused rather
        // than ignored, because a silently ignored setting is worse than a
        // refused one.
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', [
                'name'   => 'B',
                'fields' => [$this->field(['type' => 'text', 'options' => ['a', 'b']])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.options');
    }

    public function test_who_fills_a_field_has_no_default(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $field  = $this->field();
        unset($field['filled_by']);

        // Deliberately not defaulted to `employer`: a field that silently
        // belonged to the employer could be a bank account nobody asked the
        // employee for.
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', ['name' => 'A', 'fields' => [$field]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.filled_by');
    }

    // ── the common core ──────────────────────────────────────────

    public function test_the_common_core_cannot_be_redeclared_by_a_template(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        foreach (['email', 'first_name', 'last_name', 'phone', 'date_of_birth', 'gender'] as $key) {
            $this->signedInAs($alpha['admin'])
                ->postJson('/api/v1/hr/contracts', [
                    'name'   => "Uses {$key}",
                    'fields' => [$this->field(['key' => $key, 'type' => 'text'])],
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('fields.0.key');
        }

        // Nothing was written on the way past — a refusal that half-created the
        // contract would leave a template with no fields.
        $this->assertSame(0, $this->inTenant($alpha['org'], fn () => Contract::count()));
    }

    public function test_the_reserved_keys_are_published_so_a_client_need_not_guess(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $body = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/hr/contracts/catalogue')->assertOk()->json('data');

        $this->assertContains('date_of_birth', array_column($body['reserved_keys'], 'key'));
        $this->assertContains('signature', array_column($body['field_types'], 'key'));
        $this->assertContains('either', array_column($body['filled_by'], 'key'));

        // The sentence comes from the server, so the form and the refusal cannot
        // disagree about why a key is unavailable.
        $reason = collect($body['reserved_keys'])->firstWhere('key', 'date_of_birth')['reason'];
        $this->assertStringContainsString('profile', $reason);
    }

    // ── keys are stable across versions ──────────────────────────

    public function test_a_key_cannot_be_renamed(): void
    {
        [$alpha, $id] = $this->aContract([$this->field(['key' => 'bank_account', 'type' => 'text'])]);

        // The rename as a client would express it: same field, same label, new
        // key. Indistinguishable from "removed one, added another", which is why
        // the rule is stated as "a key may never disappear".
        $response = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'fields' => [$this->field(['key' => 'bank_acct', 'type' => 'text'])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields');

        $this->assertStringContainsString('bank_account', $response->json('errors.fields.0'));

        // And the way that IS allowed: keep the key, change the label freely.
        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'fields' => [$this->field([
                    'key' => 'bank_account', 'type' => 'text', 'label' => 'Account for wages',
                ])],
            ])
            ->assertCreated();
    }

    public function test_a_published_key_cannot_change_type(): void
    {
        [$alpha, $id] = $this->aContract([$this->field(['key' => 'start', 'type' => 'date'])]);

        // The key survives, so nothing is orphaned — it is worse than that. Every
        // stored date would be read as a number.
        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'fields' => [$this->field(['key' => 'start', 'type' => 'number'])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.type');
    }

    public function test_a_key_retired_in_an_earlier_version_still_cannot_be_dropped(): void
    {
        [$alpha, $id] = $this->aContract([
            $this->field(),
            $this->field(['key' => 'locker', 'type' => 'text']),
        ]);

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'fields' => [
                    $this->field(),
                    $this->field(['key' => 'locker', 'type' => 'text', 'retired' => true]),
                ],
            ])->assertCreated();

        // Retiring is not a step towards deleting. The key stays for as long as
        // the contract does, because an answer somewhere is stored against it.
        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'fields' => [$this->field()],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields');
    }

    // ── retire, never delete ─────────────────────────────────────

    public function test_a_retired_field_survives_on_the_records_that_carry_it(): void
    {
        [$alpha, $id] = $this->aContract([
            $this->field(),
            $this->field(['key' => 'locker', 'label' => 'Locker number', 'type' => 'text']),
        ]);

        $first = $this->inTenant($alpha['org'], fn () => Contract::find($id)->current_version_id);

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'fields' => [
                    $this->field(),
                    $this->field([
                        'key' => 'locker', 'label' => 'Locker number', 'type' => 'text',
                        'retired' => true,
                    ]),
                ],
            ])->assertCreated();

        $this->inTenant($alpha['org'], function () use ($id, $first) {
            $contract = Contract::find($id);
            $produced = ContractVersion::find($first);      // what an old record points at
            $current  = $contract->currentVersion;

            // A record produced by version 1 reads its answers through version 1,
            // which is immutable and still asks for the locker.
            $this->assertNotNull($produced->field('locker'));
            $this->assertFalse($produced->field('locker')->retired);
            $this->assertSame(['wage', 'locker'], array_column(
                array_map(fn ($f) => $f->toArray(), $produced->liveFields()), 'key',
            ));

            // The current version still CARRIES the definition — that is what
            // keeps the label readable and the key reserved — and no longer asks
            // for it. Those two facts together are what "retired" means.
            $this->assertNotNull($current->field('locker'));
            $this->assertTrue($current->field('locker')->retired);
            $this->assertSame('Locker number', $current->field('locker')->label);
            $this->assertSame(['wage'], array_column(
                array_map(fn ($f) => $f->toArray(), $current->liveFields()), 'key',
            ));
        });

        // And the list screen counts what onboarding would ask, not what the
        // template has ever contained.
        $this->assertSame(1, $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/hr/contracts')->assertOk()->json('data.0.field_count'));
    }

    public function test_a_published_version_cannot_be_edited(): void
    {
        [$alpha, $id] = $this->aContract();

        $this->inTenant($alpha['org'], function () use ($id) {
            $version = Contract::find($id)->currentVersion;

            $this->expectException(RuntimeException::class);
            $version->forceFill(['fields' => []])->save();
        });
    }

    // ── publishing ───────────────────────────────────────────────

    public function test_publishing_produces_a_readable_diff(): void
    {
        [$alpha, $id] = $this->aContract([
            $this->field(),
            $this->field(['key' => 'locker', 'label' => 'Locker number', 'type' => 'text']),
        ]);

        $diff = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'notes'  => 'Payroll asked for the account details.',
                'fields' => [
                    // changed: same key, same type, new label
                    $this->field(['label' => 'Wage per hour']),
                    // retired
                    $this->field([
                        'key' => 'locker', 'label' => 'Locker number', 'type' => 'text',
                        'retired' => true,
                    ]),
                    // added
                    $this->field([
                        'key' => 'bank_account', 'label' => 'Bank account number',
                        'type' => 'text', 'filled_by' => 'employee', 'sensitive' => true,
                    ]),
                ],
            ])
            ->assertCreated()
            ->json('data.diff');

        $this->assertSame(['bank_account'], array_column($diff['added'], 'key'));
        // The added line carries what makes it news, not just the key: a new
        // required sensitive question for the employee is a bigger change than
        // an optional one.
        $this->assertTrue($diff['added'][0]['sensitive']);
        $this->assertSame('employee', $diff['added'][0]['filled_by']);

        $this->assertSame(['locker'], array_column($diff['retired'], 'key'));
        $this->assertSame(['wage'], array_column($diff['changed'], 'key'));
        $this->assertSame(
            ['from' => 'Hourly wage', 'to' => 'Wage per hour'],
            $diff['changed'][0]['properties']['label'],
        );

        // Retiring is reported once, as a retirement, and not a second time as a
        // property change.
        $this->assertSame([], $diff['unretired']);
        $this->assertArrayNotHasKey('retired', $diff['changed'][0]['properties']);
        $this->assertFalse($diff['is_empty']);
    }

    public function test_reordering_the_form_is_a_change_and_adding_a_field_is_not_a_reorder(): void
    {
        $wage   = $this->field();
        $locker = $this->field(['key' => 'locker', 'label' => 'Locker number', 'type' => 'text']);

        [$alpha, $id] = $this->aContract([$wage, $locker]);

        // Order IS the order of the form, so swapping two questions is a real
        // change and must be publishable — and reported.
        $diff = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", ['fields' => [$locker, $wage]])
            ->assertCreated()->json('data.diff');

        $this->assertTrue($diff['reordered']);
        $this->assertFalse($diff['is_empty']);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['changed']);

        // Adding a field at the front shifts everything after it. That is not a
        // reorder of the questions that were already there, and reporting it as
        // one would put the flag on almost every publish.
        $diff = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'fields' => [$this->field(['key' => 'start', 'type' => 'date']), $locker, $wage],
            ])
            ->assertCreated()->json('data.diff');

        $this->assertSame(['start'], array_column($diff['added'], 'key'));
        $this->assertFalse($diff['reordered']);
    }

    public function test_a_publish_can_be_previewed_without_writing_anything(): void
    {
        [$alpha, $id] = $this->aContract();

        $body = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'preview' => true,
                'fields'  => [
                    $this->field(),
                    $this->field(['key' => 'notes', 'label' => 'Notes', 'type' => 'text']),
                ],
            ])->assertOk()->json('data');

        $this->assertTrue($body['preview']);
        $this->assertSame(2, $body['version']);
        $this->assertSame(['notes'], array_column($body['diff']['added'], 'key'));

        // Nothing was published. The point of a preview is that the person
        // decides afterwards.
        $this->assertSame(1, $this->inTenant($alpha['org'],
            fn () => ContractVersion::where('contract_id', $id)->count()));
    }

    public function test_a_preview_is_validated_exactly_as_a_publish_is(): void
    {
        [$alpha, $id] = $this->aContract();

        // Otherwise a preview would cheerfully show a diff for a field list the
        // publish button then refuses.
        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'preview' => true,
                'fields'  => [$this->field(['key' => 'renamed'])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields');
    }

    public function test_publishing_an_unchanged_field_list_is_refused(): void
    {
        [$alpha, $id] = $this->aContract();

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", ['fields' => [$this->field()]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields');
    }

    public function test_renaming_the_contract_does_not_publish_a_version(): void
    {
        [$alpha, $id] = $this->aContract();

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/contracts/{$id}", ['name' => 'Driver (UK)'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Driver (UK)')
            ->assertJsonPath('data.version', 1);

        // And a client that tries to smuggle a field list through the rename is
        // told where it goes, rather than having it silently dropped.
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/contracts/{$id}", [
                'name' => 'Driver', 'fields' => [$this->field(['key' => 'sneaky'])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields');
    }

    public function test_the_version_history_carries_each_publishs_own_diff(): void
    {
        [$alpha, $id] = $this->aContract();

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'notes'  => 'Added the locker.',
                'fields' => [$this->field(), $this->field(['key' => 'locker', 'type' => 'text'])],
            ])->assertCreated();

        $versions = $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/contracts/{$id}/versions")->assertOk()->json('data');

        // Newest first, because the question is almost always about the last
        // change.
        $this->assertSame([2, 1], array_column($versions, 'version'));
        $this->assertTrue($versions[0]['is_current']);
        $this->assertSame('Added the locker.', $versions[0]['notes']);
        $this->assertSame(['locker'], array_column($versions[0]['diff']['added'], 'key'));

        // Version 1 adds everything it has, which is the honest answer for a
        // first version rather than an empty diff.
        $this->assertSame(['wage'], array_column($versions[1]['diff']['added'], 'key'));
    }

    // ── signatures ───────────────────────────────────────────────

    public function test_a_signature_field_must_belong_to_one_side(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // ESIGN attribution: `either` would let the employer sign in the
        // employee's place, and a signature that might have been applied by the
        // other party is not evidence of anything.
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', [
                'name'   => 'A',
                'fields' => [$this->field([
                    'key' => 'sign', 'type' => 'signature', 'filled_by' => 'either',
                ])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.filled_by');

        // And it cannot be hidden from the person who signed it: sensitive means
        // excluded from exports, which contradicts retrievability.
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', [
                'name'   => 'B',
                'fields' => [$this->field([
                    'key' => 'sign', 'type' => 'signature', 'filled_by' => 'employee',
                    'sensitive' => true,
                ])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.sensitive');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', [
                'name'   => 'C',
                'fields' => [$this->field([
                    'key' => 'sign', 'label' => 'Your signature',
                    'type' => 'signature', 'filled_by' => 'employee',
                ])],
            ])->assertCreated();
    }

    public function test_a_signatures_hash_detects_a_changed_document(): void
    {
        [$alpha, $id] = $this->aContract([$this->field([
            'key' => 'sign', 'label' => 'Your signature',
            'type' => 'signature', 'filled_by' => 'employee',
        ])]);

        $document = "EMPLOYMENT CONTRACT\nHourly wage: 18.50\nSigned by: Sam Reed\n";

        $signature = $this->inTenant($alpha['org'], function () use ($alpha, $id, $document) {
            $version = Contract::find($id)->currentVersion;

            return ContractSignature::create([
                'contract_version_id' => $version->getKey(),
                'field_key'           => 'sign',
                'subject_user_id'     => $alpha['admin']->getKey(),
                'signer_user_id'      => $alpha['admin']->getKey(),
                'signer_label'        => 'Sam Reed <sam@alpha.test>',
                'signer_party'        => 'employee',
                'ip_address'          => '203.0.113.7',
                'user_agent'          => 'Mozilla/5.0',
                'content_hash'        => DocumentFingerprint::of($document),
                'hash_algorithm'      => DocumentFingerprint::ALGORITHM,
                'content_length'      => strlen($document),
                'consent_statement'   => 'I agree to sign this document electronically.',
                'consented_at'        => now(),
                'intent_statement'    => 'By typing my name I am signing this contract.',
                'affirmation'         => 'Sam Reed',
                'signed_at'           => now(),
            ]);
        });

        $this->assertTrue($signature->stillMatches($document));

        // One character. A wage of 18.50 becoming 8.50 is exactly the dispute
        // this record exists to settle.
        $this->assertFalse($signature->stillMatches(
            str_replace('18.50', '8.50', $document)
        ));

        // And every other kind of edit, including ones that look like nothing.
        $this->assertFalse($signature->stillMatches($document."\n"));
        $this->assertFalse($signature->stillMatches(rtrim($document)));
        $this->assertFalse($signature->stillMatches(str_replace('Sam Reed', 'Sam Read', $document)));
    }

    public function test_a_signature_record_cannot_be_edited_afterwards(): void
    {
        [$alpha, $id] = $this->aContract([$this->field([
            'key' => 'sign', 'label' => 'Sign', 'type' => 'signature', 'filled_by' => 'employee',
        ])]);

        $this->inTenant($alpha['org'], function () use ($alpha, $id) {
            $signature = ContractSignature::create([
                'contract_version_id' => Contract::find($id)->current_version_id,
                'field_key'           => 'sign',
                'subject_user_id'     => $alpha['admin']->getKey(),
                'signer_user_id'      => $alpha['admin']->getKey(),
                'signer_label'        => 'Admin <a@alpha.test>',
                'signer_party'        => 'employee',
                'content_hash'        => DocumentFingerprint::of('x'),
                'hash_algorithm'      => DocumentFingerprint::ALGORITHM,
                'content_length'      => 1,
                'consent_statement'   => 'I agree.',
                'consented_at'        => now(),
                'intent_statement'    => 'I am signing.',
                'affirmation'         => 'Admin',
                'signed_at'           => now(),
            ]);

            // Evidence that can be edited after the fact is indistinguishable
            // from evidence that was.
            $this->expectException(RuntimeException::class);
            $signature->forceFill(['content_hash' => DocumentFingerprint::of('y')])->save();
        });
    }

    public function test_a_record_written_by_a_future_algorithm_does_not_falsely_verify(): void
    {
        // An unknown algorithm answers "no", which is both the truthful and the
        // safe answer — this code cannot vouch for a record it does not
        // understand.
        $this->assertFalse(DocumentFingerprint::matches('anything', str_repeat('a', 64), 'sha3-future'));
    }

    // ── who may do this ──────────────────────────────────────────

    public function test_reading_needs_the_tab_and_writing_needs_more(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $reader = $this->userWithRole($alpha, 'Reader', ['hr.view']);

        $this->signedInAs($reader)->getJson('/api/v1/hr/contracts')->assertOk();

        $this->signedInAs($reader)
            ->postJson('/api/v1/hr/contracts', ['name' => 'A', 'fields' => [$this->field()]])
            ->assertForbidden();

        $outsider = $this->userWithRole($alpha, 'Technician', ['customers.view']);
        $this->signedInAs($outsider)->getJson('/api/v1/hr/contracts')->assertForbidden();
    }

    public function test_authoring_a_template_needs_organization_reach(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $branch = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.create', 'hr.edit'],
            onlyAt: $alpha['franchise'],
        );

        // `hr.create` is also what lets them invite a technician. A template
        // reaches every franchise in the business, so it needs the level that
        // covers them.
        $this->signedInAs($branch)
            ->postJson('/api/v1/hr/contracts', ['name' => 'A', 'fields' => [$this->field()]])
            ->assertForbidden();

        [, $id] = $this->aContractIn($alpha);

        $this->signedInAs($branch)
            ->postJson("/api/v1/hr/contracts/{$id}/versions", [
                'fields' => [$this->field(), $this->field(['key' => 'x', 'type' => 'text'])],
            ])
            ->assertForbidden();

        // Reading is not restricted the same way: somebody running an onboarding
        // has to see the template they are working from, and it holds no
        // personal data — only the questions.
        $this->signedInAs($branch)->getJson("/api/v1/hr/contracts/{$id}")->assertOk();
    }

    /** @return array{0: array, 1: string} */
    private function aContractIn(array $tenant): array
    {
        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/hr/contracts', ['name' => 'Driver', 'fields' => [$this->field()]])
            ->assertCreated()->json('data.id');

        return [$tenant, $id];
    }

    public function test_another_organizations_contract_is_not_found_rather_than_forbidden(): void
    {
        [, $id] = $this->aContract();
        $beta   = $this->provisionTenant('Beta', 'beta@example.test');

        // 404, not 403: a 403 would confirm the id is real somewhere on the
        // platform, which is the existence oracle the rest of the API avoids.
        $this->signedInAs($beta['admin'])->getJson("/api/v1/hr/contracts/{$id}")->assertNotFound();

        $this->signedInAs($beta['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/versions", ['fields' => [$this->field()]])
            ->assertNotFound();

        // And Beta's list is Beta's.
        $this->assertSame([], $this->signedInAs($beta['admin'])
            ->getJson('/api/v1/hr/contracts')->assertOk()->json('data'));
    }

    public function test_two_contracts_in_one_organization_cannot_share_a_name(): void
    {
        [$alpha] = $this->aContract();

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', ['name' => 'Driver', 'fields' => [$this->field()]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // Another organization is free to use it — the constraint is per tenant.
        $beta = $this->provisionTenant('Beta', 'beta@example.test');
        $this->signedInAs($beta['admin'])
            ->postJson('/api/v1/hr/contracts', ['name' => 'Driver', 'fields' => [$this->field()]])
            ->assertCreated();
    }
}
