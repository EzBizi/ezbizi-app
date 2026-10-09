<?php

namespace App\Support\Mail;

use InvalidArgumentException;

/**
 * How a message physically leaves the building.
 *
 * ── One value today, and the shape that lets there be a second ───
 *
 * The owner's decision was verified-domain-on-our-transport and NOT
 * bring-your-own-SMTP, for two reasons worth keeping written down: holding sixty
 * customers' SMTP passwords is a permanent operational tax that never gets
 * cheaper, and a server that dials a host the tenant typed is an SSRF surface
 * pointed at our own network.
 *
 * So there is one tenant-facing value. It is an enum rather than a bare string
 * because the requirement is that a second one can be added "without reshaping
 * anything", and that is only true if every place that branches on transport is
 * findable. Adding `case Smtp` here makes the two `match` arms below fail to
 * compile until somebody has said what the new transport means, which is the
 * point — a string column would have let it be half-added.
 *
 * `Platform` is not a tenant choice and cannot be stored on a row. It is what
 * EzBizi's own identity carries, so that the platform link at the end of the
 * cascade is the same kind of thing as every link before it rather than a
 * special case with a null where the transport goes.
 */
enum MailTransport: string
{
    /**
     * EzBizi's own transport, sending as EzBizi.
     *
     * The credentials behind it are DEPLOYMENT CONFIG (§2.1) — host, port, user
     * and password in the environment, plain SMTP, which is what Google
     * Workspace, SES and Postmark all speak. Changing provider is a credential
     * change and not a code change, which is why nothing here names an SDK.
     */
    case Platform = 'platform';

    /**
     * Our transport, signed as a domain the tenant has proved they own.
     *
     * The tenant publishes DNS records; we send the message. They never hand us
     * a password, and we never dial a host they chose.
     */
    case VerifiedDomain = 'verified_domain';

    /**
     * The tenant's OWN mail server. EzBizi's transport is not involved at all.
     *
     * Added 2026-09-14 on Jose's "yes build it for god sake", and it is the
     * second value this file was shaped around — the docblock above predicted
     * the shape of the arrival correctly, including that the `match` arms below
     * would be what forced somebody to say what it means.
     *
     * ── Why this does not reopen the ruling that retired VerifiedDomain ──
     *
     * His ruling was "EZBIZI EMAIL IS ONLY FOR EZBIZI ACTIONS", and it is about
     * our TRANSPORT rather than about the From line. VerifiedDomain means the
     * tenant proves a domain and WE send their content through our SES, on our
     * reputation and our quota — which is the thing he retired, and it stays
     * retired. This means their server accepts, authenticates and sends, and
     * nothing of ours touches the message. The two are opposite on the only
     * axis the ruling names, which is why one can arrive as the other leaves.
     *
     * The credential that makes this work is the reason `credentials` on
     * sending_identities has been an encrypted column with nothing in it since
     * August. The host it names is a trust boundary, checked by
     * {@see \App\Support\Email\RemoteHostGuard} — the one guard for any host a
     * customer typed. It is NOT checked by TenantSmtpHost, which this line used
     * to name: I wrote that, it duplicated the guard above, and it was deleted
     * in 7e1d00ac hours after it shipped.
     */
    case Smtp = 'smtp';

    /** Does using this transport require the domain to have been proved? */
    public function requiresDomainProof(): bool
    {
        return match ($this) {
            // Nothing to prove: it is our own domain, and we are the ones asking.
            self::Platform       => false,
            self::VerifiedDomain => true,

            /*
             * Nothing for US to prove, and nobody here to prove it to. The
             * tenant's own server decides whether to accept the message, using
             * the password they gave it — that IS the authentication, and it is
             * stronger than a DNS record because it fails closed at the moment
             * of sending rather than at a check we ran some hours earlier.
             */
            self::Smtp => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Platform       => 'EzBizi',
            self::VerifiedDomain => 'Your own domain, sent by EzBizi',
            self::Smtp           => 'Your own mail server',
        };
    }

    /**
     * Refuses rather than defaulting.
     *
     * A stored value nothing recognises must not quietly become the first case:
     * that is how a row written by a future BYO-SMTP transport would come back
     * from an older deploy claiming to be a verified domain and send anyway.
     */
    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException(
            "Stored sending transport '{$value}' is not one this build knows about."
        );
    }
}
