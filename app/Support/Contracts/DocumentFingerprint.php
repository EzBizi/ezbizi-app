<?php

namespace App\Support\Contracts;

/**
 * The tamper-evident half of a signature (EZBIZI-HR-PLAN.md, §ESIGN/UETA).
 *
 * A signature record stores a fingerprint of the EXACT content the signer was
 * shown. Afterwards, anybody holding a copy of the document can run it through
 * here and compare.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHAT A MATCH PROVES, AND WHAT IT DOES NOT
 *
 *  This sentence is the one somebody will rely on in a dispute. It is
 *  deliberately narrow, because an overstatement here is worse than
 *  having nothing at all: it would invite a claim the record cannot
 *  support, and being caught overstating it discredits the parts that
 *  are true.
 *
 *  IT PROVES: the document in your hand is byte-for-byte identical to
 *  the one whose fingerprint was recorded at the moment of signing.
 *  Change a single character — a digit in a wage, a date, a clause —
 *  and the fingerprint no longer matches. There is no known way to
 *  produce a different document with the same SHA-256 fingerprint.
 *
 *  IT DOES NOT PROVE:
 *
 *  · That the RECORDED fingerprint is itself authentic. It is a row in
 *    this application's database. Anyone who can write to that database
 *    could replace the document and the fingerprint together, and this
 *    comparison would pass. What stands behind the row is the audit log
 *    and the backups, not the mathematics.
 *
 *  · WHO signed. That rests entirely on the account authentication at
 *    the time and on the attribution captured beside the fingerprint —
 *    the signer, the address they came from, what they were asked to
 *    affirm. The hash is indifferent to who computed it.
 *
 *  · WHEN it was signed. The timestamp is this server's clock, recorded
 *    by this server. There is no trusted third-party timestamp, so the
 *    record cannot establish the time against a party who disputes it.
 *
 *  · Anything to an outside party on its own. This is not a digital
 *    signature in the cryptographic sense: there is no private key and
 *    nothing is countersigned, so the record cannot be verified by
 *    anybody who does not trust this system's own records.
 *
 *  Two upgrades would each close one of those gaps without changing
 *  what is stored: a keyed MAC (a server-held secret in the hash) makes
 *  the row unforgeable by a database writer alone, and an external
 *  timestamp authority makes the time independently checkable. Neither
 *  is built. `hash_algorithm` is stored per row so the first can be
 *  introduced without invalidating anything already signed.
 * ═══════════════════════════════════════════════════════════════════
 */
final class DocumentFingerprint
{
    /**
     * Recorded per signature rather than assumed, so today's records stay
     * verifiable on the day this changes.
     */
    public const ALGORITHM = 'sha256';

    /**
     * What `matches()` will verify against.
     *
     * Only algorithms for which producing a second document with the same
     * fingerprint is computationally infeasible. Adding one to this list is a
     * claim about cryptography, not a configuration change.
     */
    public const VERIFIABLE = ['sha256', 'sha384', 'sha512'];

    /**
     * The fingerprint of the exact rendered content.
     *
     * Takes the content as bytes and hashes it as-is: no trimming, no
     * normalising, no canonical form. That is the point — "the exact rendered
     * content" means the bytes the person was shown, and a hash of a tidied-up
     * version would be a hash of a document nobody ever saw.
     */
    public static function of(string $renderedContent): string
    {
        return hash(self::ALGORITHM, $renderedContent);
    }

    /**
     * Does this content still match what was signed?
     *
     * `hash_equals` rather than `===` out of habit rather than necessity — there
     * is no secret here to leak through timing, and the day a keyed MAC arrives
     * (see the class docblock) the comparison is already the right one.
     *
     * An unknown algorithm returns false rather than throwing: this is called to
     * answer "is this document intact", and a record written by a future version
     * of the software is one this code cannot vouch for. "No" is the truthful
     * answer, and it is the safe one.
     */
    public static function matches(string $renderedContent, string $recordedHash, string $algorithm): bool
    {
        /*
         * An ALLOW-LIST, not "anything PHP can compute".
         *
         * This was `in_array($algorithm, hash_algos())`, which admits crc32b,
         * adler32, md5 and every other non-cryptographic checksum in the
         * binary. `hash_algorithm` is a free 20-character fillable column, so
         * anybody who could write the row could pick one — and a forged
         * verification was demonstrated in well under a second: with adler32,
         * the real document and a scrambled string of the same bytes both
         * returned true against the same recorded hash.
         *
         * That voided the entire claim this class exists to make. `sha256` was
         * declared as a constant and then never enforced on write or on read,
         * which is the gap between a default and a rule.
         *
         * The list is plural on purpose — an algorithm being retired later
         * should not make every historical signature unverifiable — but
         * everything in it has to be one whose collisions are infeasible to
         * construct, because that infeasibility IS the tamper-evidence.
         */
        if (! in_array($algorithm, self::VERIFIABLE, strict: true)) {
            return false;
        }

        return hash_equals($recordedHash, hash($algorithm, $renderedContent));
    }
}
