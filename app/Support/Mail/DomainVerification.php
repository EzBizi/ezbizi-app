<?php

namespace App\Support\Mail;

use App\Models\SendingIdentity;
use RuntimeException;

/**
 * Proving a tenant owns the domain they want to send as — the state machine.
 *
 * ── The whole machine, in one place ──────────────────────────────
 *
 *   (new identity)  ──prepare()───────────────►  unverified
 *   unverified      ──check(), nobody looked ─►  pending
 *   unverified      ──check(), records found ─►  verified
 *   unverified      ──check(), records absent─►  failed
 *   pending         ──check()────────────────►  verified | failed | pending
 *   failed          ──check()────────────────►  verified | failed | pending
 *   verified        ──check(), records gone ──►  failed
 *   any             ──reconsider(), domain or transport changed ─► unverified
 *
 * Every arrow runs through this class. Nothing else writes `state`,
 * `state_reason`, `verified_at`, `last_checked_at` or `verification_token`, and
 * none of them is fillable on the model — so there is no API request, however
 * shaped, that can declare an identity verified. That is the single most
 * important property of this feature: a verification that returns true because
 * nobody checked is worse than having no verification at all, because
 * {@see MailIdentity::canSend()} correctly believes it.
 *
 * ── The seam ─────────────────────────────────────────────────────
 *
 * Real DNS is read by a {@see DnsVerifier}, named by
 * `config('mail.verification.dns_verifier')`, and there is none today. The
 * default {@see UnconfiguredDnsVerifier} answers `unavailable` to everything, so
 * `check()` never reaches Verified on this installation. It leaves the identity
 * `pending` with a sentence saying the hold-up is at EzBizi's end, which is
 * true, rather than `failed`, which would send a tenant to their DNS host to
 * hunt for a fault that is not there.
 *
 * ── The records, and what this installation cannot yet describe ──
 *
 * The ownership TXT is self-contained: it proves control of the zone and needs
 * no name of ours in it, so it is generated always. SPF and DKIM both have to
 * name OUR infrastructure — the host a CNAME points at, the domain an SPF
 * include names — and this installation has no such infrastructure configured.
 * Those records are therefore NOT generated, rather than generated with a
 * plausible-looking guess: a tenant who publishes an SPF include for a host that
 * does not send their mail has made their deliverability worse, not better.
 * {@see missingDeploymentPieces()} names what is absent so a staff screen can
 * say so out loud.
 */
class DomainVerification
{
    /** What the ownership record's value starts with, so it is recognisable in a zone file. */
    private const OWNERSHIP_PREFIX = 'ezbizi-domain-verification=';

    public function __construct(
        /**
         * Injected only by tests and by a future provider integration.
         *
         * Null in production, and then {@see verifier()} reads deployment
         * config. A nullable constructor argument rather than a container
         * binding because the binding would have to live in a service provider,
         * and the provider files are not this change's to edit — the config key
         * is the documented, greppable seam either way.
         */
        private readonly ?DnsVerifier $dns = null,
    ) {}

    // ── the transitions ──────────────────────────────────────────

    /**
     * A brand new identity, or one whose domain has just changed.
     *
     * Mints the token (or adopts a sibling's — see below) and states plainly
     * that nothing has been proved yet. Deliberately does NOT check: creating an
     * address and asking DNS about it in the same request would report a failure
     * before the tenant has had a chance to publish anything.
     */
    public function prepare(SendingIdentity $identity): SendingIdentity
    {
        $identity->forceFill([
            'state'              => SendingState::Unverified->value,
            'state_reason'       => 'Publish the DNS records below with whoever hosts '
                .$identity->domain().', then check them here. Until this domain is proved, '
                .'mail goes out from the EzBizi address instead.',
            'verification_token' => $this->tokenFor($identity),
            'verified_at'        => null,
        ])->save();

        return $identity;
    }

    /**
     * The address or the transport changed — is the old proof still good?
     *
     * Changing the local part of an already-proved domain (info@ to hello@)
     * keeps the verification, because what was proved is the DOMAIN and that has
     * not moved. Changing the domain, or the transport, throws it away: the
     * records are different and the old proof says nothing about the new claim.
     *
     * Returns true when the proof was discarded, so a caller can tell the person
     * their address has stopped sending.
     */
    public function reconsider(SendingIdentity $identity, string $previousDomain, string $previousTransport): bool
    {
        if ($identity->domain() === $previousDomain && $identity->transport === $previousTransport) {
            return false;
        }

        $this->prepare($identity);

        return true;
    }

    /**
     * Ask DNS, and record what came back.
     *
     * The only path to `verified`, and it goes through the seam every time.
     */
    public function check(SendingIdentity $identity): SendingIdentity
    {
        $records = $this->recordsFor($identity);
        $outcome = $this->verifier()->check($identity->domain(), $records);

        $identity->forceFill([
            'state'           => $this->stateFrom($outcome)->value,
            'state_reason'    => $outcome->explanation,
            'verified_at'     => $outcome->verified ? now() : null,
            'last_checked_at' => now(),
        ])->save();

        return $identity;
    }

    /**
     * Which state an outcome lands in.
     *
     * The `unavailable` row is the one that matters: it is NOT a failure of the
     * tenant's, so it holds at `pending` — waiting — rather than accusing them
     * of records they may well have published perfectly.
     */
    private function stateFrom(VerificationOutcome $outcome): SendingState
    {
        return match (true) {
            $outcome->verified  => SendingState::Verified,
            ! $outcome->checked => SendingState::Pending,
            default             => SendingState::Failed,
        };
    }

    // ── the records ──────────────────────────────────────────────

    /**
     * What the tenant has to publish.
     *
     * @return DnsRecord[]
     */
    public function recordsFor(SendingIdentity $identity): array
    {
        $domain = $identity->domain();
        $token  = $identity->verification_token;

        if ($token === null || $token === '') {
            // Nothing to publish yet, and no record invented to fill the gap.
            // prepare() is what mints the token, and an identity that has not
            // been through it has not been offered to DNS at all.
            return [];
        }

        $records = [
            new DnsRecord(
                type: DnsRecord::TYPE_TXT,
                name: $this->ownershipHost().'.'.$domain,
                value: self::OWNERSHIP_PREFIX.$token,
                purpose: 'Proves to EzBizi that you control '.$domain.'. Add it as a TXT '
                    .'record; it does not affect your existing mail.',
            ),
        ];

        if (($include = $this->spfInclude()) !== null) {
            $records[] = new DnsRecord(
                type: DnsRecord::TYPE_TXT,
                name: $domain,
                value: 'v=spf1 include:'.$include.' ~all',
                purpose: 'Tells receiving mail servers that EzBizi may send for '.$domain.'. '
                    .'If you already have an SPF record, add "include:'.$include.'" to the '
                    .'one you have rather than adding a second — two SPF records is the same '
                    .'to a mail server as none.',
            );
        }

        if (($dkim = $this->dkim()) !== null) {
            [$selector, $host] = $dkim;

            $records[] = new DnsRecord(
                type: DnsRecord::TYPE_CNAME,
                name: $selector.'._domainkey.'.$domain,
                value: $selector.'.'.$host,
                purpose: 'Lets EzBizi sign your mail as '.$domain.', so it is not treated as '
                    .'forged. A CNAME rather than a key, so EzBizi can rotate the key '
                    .'without you touching DNS again.',
            );
        }

        return $records;
    }

    /**
     * What this installation cannot describe, by name.
     *
     * Not a tenant's problem and not shown to them. It is the honest answer to
     * "why does this identity only have one record", and it belongs on the staff
     * health screen beside the transport.
     *
     * @return string[] environment variable names
     */
    public function missingDeploymentPieces(): array
    {
        $missing = [];

        if ($this->spfInclude() === null) {
            $missing[] = 'MAIL_SPF_INCLUDE';
        }

        if ($this->dkim() === null) {
            $missing[] = 'MAIL_DKIM_SELECTOR + MAIL_DKIM_HOST';
        }

        if (blank(config('mail.verification.dns_verifier'))) {
            $missing[] = 'MAIL_DNS_VERIFIER';
        }

        return $missing;
    }

    // ── the seam ─────────────────────────────────────────────────

    /**
     * The DnsVerifier this installation uses.
     *
     * Throws on a misconfigured class rather than quietly using the default.
     * A silent downgrade is the worst of both: an operator who has set
     * MAIL_DNS_VERIFIER believes verification is switched on, every tenant sits
     * at `pending` forever, and nothing anywhere says why.
     */
    public function verifier(): DnsVerifier
    {
        if ($this->dns !== null) {
            return $this->dns;
        }

        $configured = config('mail.verification.dns_verifier');

        if (blank($configured)) {
            return new UnconfiguredDnsVerifier;
        }

        $verifier = app($configured);

        if (! $verifier instanceof DnsVerifier) {
            throw new RuntimeException(
                "MAIL_DNS_VERIFIER names {$configured}, which cannot verify a domain. "
                .'Point it at a class implementing App\Support\Mail\DnsVerifier, or unset it '
                .'— unset, no domain is proved and every tenant sends from the EzBizi address.'
            );
        }

        return $verifier;
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * The token for this identity's domain.
     *
     * Reused across identities on the SAME domain in the same organization, so a
     * business sending as both info@everdoors.com and support@everdoors.com
     * publishes one TXT record and not two. Verification is about the domain;
     * the address is only where it happens to be used.
     */
    private function tokenFor(SendingIdentity $identity): string
    {
        $sibling = SendingIdentity::query()
            ->where('organization_id', $identity->organization_id)
            ->whereKeyNot($identity->getKey())
            ->whereNotNull('verification_token')
            ->get()
            ->first(fn (SendingIdentity $other) => $other->domain() === $identity->domain());

        return $sibling?->verification_token ?? bin2hex(random_bytes(16));
    }

    /** The label the ownership record sits at, e.g. `_ezbizi-verify`. */
    private function ownershipHost(): string
    {
        return (string) (config('mail.verification.ownership_host') ?: '_ezbizi-verify');
    }

    private function spfInclude(): ?string
    {
        $value = config('mail.verification.spf_include');

        return blank($value) ? null : (string) $value;
    }

    /** @return array{0: string, 1: string}|null selector and host, or nothing */
    private function dkim(): ?array
    {
        $selector = config('mail.verification.dkim_selector');
        $host     = config('mail.verification.dkim_host');

        // Both or neither. A selector with no host produces a CNAME pointing at
        // nothing, which is worse than no record: it looks published and fails.
        if (blank($selector) || blank($host)) {
            return null;
        }

        return [(string) $selector, (string) $host];
    }
}
