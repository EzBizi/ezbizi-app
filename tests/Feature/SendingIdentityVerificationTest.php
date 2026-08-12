<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SendingIdentity;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Mail\DnsRecord;
use App\Support\Mail\DnsVerifier;
use App\Support\Mail\DomainVerification;
use App\Support\Mail\MailTransport;
use App\Support\Mail\SendingState;
use App\Support\Mail\UnconfiguredDnsVerifier;
use App\Support\Mail\VerificationOutcome;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tests\TestCase;

/**
 * Proving a domain, and refusing to pretend (the state machine and the seam).
 *
 * ── The test this file exists for ────────────────────────────────
 *
 * test_an_installation_with_no_verifier_never_reaches_verified. Everything else
 * here is ordinary state-machine coverage; that one is the whole point of the
 * design. There is no DNS provider connected to this installation, and the
 * cheapest thing to have written was a check that returns true. It would have
 * gone green, tenants would have seen "Verified" beside an address they had
 * proved nothing about, and MailIdentity::canSend() would have correctly let it
 * send.
 */
class SendingIdentityVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $provisioned = app(ProvisionOrganization::class)(
            organizationName: 'A1-Group',
            administrator: ['name' => 'Owner', 'email' => 'owner@a1group.test', 'password' => 'a-real-password'],
            companyName:   'Everdoors',
            franchiseName: 'New Jersey',
        );

        $this->org     = $provisioned['organization'];
        $this->company = $provisioned['company'];

        // No provider, which is this installation's honest state.
        config([
            'mail.verification.dns_verifier'  => null,
            'mail.verification.ownership_host' => '_ezbizi-verify',
            'mail.verification.spf_include'   => null,
            'mail.verification.dkim_selector' => null,
            'mail.verification.dkim_host'     => null,
        ]);
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->org, $fn);
    }

    private function newIdentity(string $address = 'info@everdoors.com', ?Model $scope = null): SendingIdentity
    {
        $scope ??= $this->company;

        $identity = new SendingIdentity([
            'organization_id' => $this->org->id,
            'scope_type'      => SendingIdentity::SCOPE_COMPANY,
            'scope_id'        => $scope->getKey(),
            'from_address'    => $address,
            'from_name'       => 'Everdoors',
            'transport'       => MailTransport::VerifiedDomain->value,
        ]);

        $identity->forceFill(['state' => SendingState::Unverified->value])->save();

        return $identity;
    }

    // ── where an identity starts ─────────────────────────────────

    public function test_a_new_identity_is_unverified_and_says_what_to_do(): void
    {
        $this->inTenant(function () {
            $identity = (new DomainVerification)->prepare($this->newIdentity());

            $this->assertSame(SendingState::Unverified, $identity->sendingState());
            $this->assertFalse($identity->toMailIdentity()->canSend());

            // R8: a sentence with a next action in it, not a state name.
            $this->assertStringContainsString('everdoors.com', $identity->state_reason);
            $this->assertStringContainsString('publish', strtolower($identity->state_reason));
        });
    }

    public function test_the_records_name_the_domain_and_carry_the_token(): void
    {
        $this->inTenant(function () {
            $identity = (new DomainVerification)->prepare($this->newIdentity());
            $records  = (new DomainVerification)->recordsFor($identity);

            // CONTROL: an empty list would satisfy every assertion below by
            // never running one.
            $this->assertNotEmpty($records, 'No records were generated, so there is nothing to publish.');

            $ownership = $records[0];

            $this->assertSame(DnsRecord::TYPE_TXT, $ownership->type);
            $this->assertSame('_ezbizi-verify.everdoors.com', $ownership->name);
            $this->assertStringContainsString($identity->verification_token, $ownership->value);

            // Split into fields rather than one pasteable line: the person
            // acting on it is typing into a form with three boxes.
            $this->assertNotSame('', $ownership->purpose);
        });
    }

    public function test_no_spf_or_dkim_record_is_invented_when_this_installation_cannot_name_one(): void
    {
        $this->inTenant(function () {
            $records = (new DomainVerification)->recordsFor(
                (new DomainVerification)->prepare($this->newIdentity())
            );

            // One record, and it is the self-contained one. An SPF include
            // naming a host that does not send the tenant's mail makes their
            // deliverability worse, not better.
            $this->assertCount(1, $records);

            $missing = (new DomainVerification)->missingDeploymentPieces();
            $this->assertContains('MAIL_SPF_INCLUDE', $missing);
            $this->assertContains('MAIL_DNS_VERIFIER', $missing);
        });
    }

    public function test_spf_and_dkim_appear_once_deployment_config_names_them(): void
    {
        config([
            'mail.verification.spf_include'   => 'mail.ezbizi.com',
            'mail.verification.dkim_selector' => 'ez1',
            'mail.verification.dkim_host'     => 'dkim.ezbizi.com',
        ]);

        $this->inTenant(function () {
            $records = (new DomainVerification)->recordsFor(
                (new DomainVerification)->prepare($this->newIdentity())
            );

            $this->assertCount(3, $records);

            $names = array_map(fn (DnsRecord $r) => $r->name, $records);
            $this->assertContains('everdoors.com', $names);
            $this->assertContains('ez1._domainkey.everdoors.com', $names);

            $this->assertSame([], array_intersect(
                ['MAIL_SPF_INCLUDE', 'MAIL_DKIM_SELECTOR + MAIL_DKIM_HOST'],
                (new DomainVerification)->missingDeploymentPieces(),
            ));
        });
    }

    public function test_a_selector_without_a_host_produces_no_dkim_record_at_all(): void
    {
        // A CNAME pointing at nothing is worse than no record: it looks
        // published and it fails.
        config(['mail.verification.dkim_selector' => 'ez1', 'mail.verification.dkim_host' => null]);

        $this->inTenant(function () {
            $records = (new DomainVerification)->recordsFor(
                (new DomainVerification)->prepare($this->newIdentity())
            );

            $this->assertCount(1, $records);
        });
    }

    public function test_two_identities_on_one_domain_publish_the_same_record(): void
    {
        $this->inTenant(function () {
            $verification = new DomainVerification;

            $second = Company::create([
                'organization_id' => $this->org->id,
                'name'            => 'Everdoors Trade',
            ]);

            $one = $verification->prepare($this->newIdentity('info@everdoors.com'));
            $two = $verification->prepare($this->newIdentity('support@everdoors.com', $second));

            // One TXT record for the domain, not one per address. Verification
            // is about the domain; the address is where it happens to be used.
            $this->assertSame($one->verification_token, $two->verification_token);
        });
    }

    public function test_a_different_domain_gets_a_different_record(): void
    {
        $this->inTenant(function () {
            $verification = new DomainVerification;

            $second = Company::create([
                'organization_id' => $this->org->id,
                'name'            => 'A-1 Chimney',
            ]);

            $one = $verification->prepare($this->newIdentity('info@everdoors.com'));
            $two = $verification->prepare($this->newIdentity('info@a-1chimney.com', $second));

            $this->assertNotSame($one->verification_token, $two->verification_token);
        });
    }

    // ── the seam, and the answer that must never be yes ──────────

    public function test_an_installation_with_no_verifier_never_reaches_verified(): void
    {
        $this->inTenant(function () {
            $verification = new DomainVerification;
            $identity     = $verification->check($verification->prepare($this->newIdentity()));

            // Not verified, and not blamed on the tenant either: their records
            // may well be perfect, and nothing read them.
            $this->assertSame(SendingState::Pending, $identity->sendingState());
            $this->assertFalse($identity->toMailIdentity()->canSend());
            $this->assertNull($identity->verified_at);

            // It DID look, in the sense of running the machinery — the check is
            // recorded, so a screen can say when it last tried.
            $this->assertNotNull($identity->last_checked_at);

            $this->assertStringContainsString(
                'no domain-verification service',
                strtolower($identity->state_reason),
            );
        });
    }

    public function test_the_default_verifier_is_the_one_that_refuses(): void
    {
        $this->assertInstanceOf(UnconfiguredDnsVerifier::class, (new DomainVerification)->verifier());
    }

    public function test_a_verifier_that_finds_the_records_verifies_the_identity(): void
    {
        $this->inTenant(function () {
            $verifier     = new RecordingVerifier(VerificationOutcome::verified('Both records are published.'));
            $verification = new DomainVerification($verifier);

            $identity = $verification->check($verification->prepare($this->newIdentity()));

            $this->assertSame(SendingState::Verified, $identity->sendingState());
            $this->assertTrue($identity->toMailIdentity()->canSend());
            $this->assertNotNull($identity->verified_at);
            $this->assertNull($identity->toMailIdentity()->refusal());

            // CONTROL: the verifier was actually consulted, and about the
            // domain rather than the address, and it was handed the records.
            $this->assertSame('everdoors.com', $verifier->askedAbout);
            $this->assertNotEmpty($verifier->wasGiven);
        });
    }

    public function test_a_verifier_that_does_not_find_them_fails_with_the_reason_it_gave(): void
    {
        $this->inTenant(function () {
            $verification = new DomainVerification(
                new RecordingVerifier(VerificationOutcome::rejected('No TXT record at _ezbizi-verify.everdoors.com.'))
            );

            $identity = $verification->check($verification->prepare($this->newIdentity()));

            // Failed, not pending: somebody looked and the answer was no.
            $this->assertSame(SendingState::Failed, $identity->sendingState());
            $this->assertFalse($identity->toMailIdentity()->canSend());
            $this->assertStringContainsString('_ezbizi-verify.everdoors.com', $identity->state_reason);
        });
    }

    public function test_a_domain_that_stops_resolving_loses_its_verification(): void
    {
        $this->inTenant(function () {
            $verified = new DomainVerification(new RecordingVerifier(VerificationOutcome::verified('Published.')));
            $identity = $verified->check($verified->prepare($this->newIdentity()));
            $this->assertSame(SendingState::Verified, $identity->sendingState());

            $gone = new DomainVerification(new RecordingVerifier(VerificationOutcome::rejected('The records are gone.')));
            $identity = $gone->check($identity);

            // A re-check is allowed to take verification away. A tenant who
            // moved DNS hosts and dropped the records must stop sending as that
            // domain, not carry on because they once could.
            $this->assertSame(SendingState::Failed, $identity->sendingState());
            $this->assertNull($identity->verified_at);
            $this->assertFalse($identity->toMailIdentity()->canSend());
        });
    }

    public function test_a_misconfigured_verifier_is_refused_loudly_rather_than_downgraded(): void
    {
        config(['mail.verification.dns_verifier' => \stdClass::class]);

        // A silent fallback to the refusing default would leave an operator
        // believing verification is switched on while every tenant sits pending
        // forever with nothing anywhere saying why.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/MAIL_DNS_VERIFIER/');

        (new DomainVerification)->verifier();
    }

    // ── what an edit does to an existing proof ───────────────────

    public function test_changing_the_domain_throws_the_proof_away(): void
    {
        $this->inTenant(function () {
            $verification = new DomainVerification(new RecordingVerifier(VerificationOutcome::verified('Published.')));
            $identity     = $verification->check($verification->prepare($this->newIdentity('info@everdoors.com')));

            $identity->fill(['from_address' => 'info@irs.gov'])->save();

            $discarded = (new DomainVerification)->reconsider($identity, 'everdoors.com', MailTransport::VerifiedDomain->value);

            $this->assertTrue($discarded);
            $this->assertSame(SendingState::Unverified, $identity->sendingState());
            $this->assertFalse($identity->toMailIdentity()->canSend());
        });
    }

    public function test_changing_only_the_local_part_keeps_the_proof(): void
    {
        $this->inTenant(function () {
            $verification = new DomainVerification(new RecordingVerifier(VerificationOutcome::verified('Published.')));
            $identity     = $verification->check($verification->prepare($this->newIdentity('info@everdoors.com')));

            $identity->fill(['from_address' => 'hello@everdoors.com'])->save();

            $discarded = (new DomainVerification)->reconsider($identity, 'everdoors.com', MailTransport::VerifiedDomain->value);

            // The DOMAIN was proved and it has not moved. Making them prove it
            // again to rename a mailbox would be verification theatre.
            $this->assertFalse($discarded);
            $this->assertSame(SendingState::Verified, $identity->sendingState());
            $this->assertTrue($identity->toMailIdentity()->canSend());
        });
    }

    // ── the refusals are sentences (R8) ──────────────────────────

    public function test_every_refusal_is_a_sentence_and_names_nothing_internal(): void
    {
        foreach ([SendingState::Unverified, SendingState::Pending, SendingState::Failed] as $state) {
            $refusal = $state->refusal('info@everdoors.com');

            $this->assertNotNull($refusal);
            $this->assertStringEndsWith('.', $refusal);
            $this->assertGreaterThan(12, str_word_count($refusal), 'A refusal has to say what to do.');

            // No class names, no namespaces, no permission keys, no state
            // constants. R8: "a refusal is a sentence saying what to do, never a
            // permission key or a class name."
            $this->assertStringNotContainsString('\\', $refusal);
            $this->assertStringNotContainsString('::', $refusal);
            $this->assertStringNotContainsString($state->value, $refusal);
            $this->assertDoesNotMatchRegularExpression('/\b[a-z_]+\.[a-z_]+\b(?<!\.com)/', $refusal);
        }

        $this->assertNull(SendingState::Verified->refusal('info@everdoors.com'));
    }
}

/**
 * A DnsVerifier that answers whatever the test told it to, and remembers what it
 * was asked.
 *
 * The remembering is the point. Without it, a test asserting "the identity ended
 * up verified" would pass just as well against code that never consulted the
 * verifier at all.
 */
class RecordingVerifier implements DnsVerifier
{
    public ?string $askedAbout = null;

    /** @var DnsRecord[] */
    public array $wasGiven = [];

    public function __construct(private readonly VerificationOutcome $outcome) {}

    public function check(string $domain, array $expected): VerificationOutcome
    {
        $this->askedAbout = $domain;
        $this->wasGiven   = $expected;

        return $this->outcome;
    }

    public function describe(): string
    {
        return 'A verifier built for a test, which answers without reading DNS.';
    }
}
