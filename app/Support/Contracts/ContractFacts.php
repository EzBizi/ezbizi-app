<?php

namespace App\Support\Contracts;

/**
 * The handful of things a contract body may always say, whatever the template
 * asks.
 *
 * A body needs to name the business, the agreement and the day, and none of
 * those is a question anybody should be made to declare as a field: the business
 * already has a name, the template already has one, and asking an employee to
 * type today's date into a form is asking them to maintain a value the system
 * knows. So these four resolve out of the record itself.
 *
 * ── The dot is the whole reason these are safe ───────────────────
 *
 * A field key is `^[a-z][a-z0-9_]*$` (FieldSet::checkOne) — no dots, ever. So a
 * dotted key cannot be declared by a tenant and cannot collide with one, now or
 * after any number of versions. That is what lets the bare namespace stay bare:
 * `{{first_name}}` is the person and `{{organization.name}}` is the business,
 * and no template can ever make either ambiguous.
 *
 * It would have been possible instead to add these to CommonCore::RESERVED and
 * refuse them as field keys. That was rejected: a tenant may already have a
 * published field called `contract_name`, and FieldSet refuses to publish a
 * version that drops a key — so reserving one retrospectively would leave that
 * contract unpublishable forever, with a refusal about a rule that did not exist
 * when they made it.
 *
 * ── Why the SIGNING date is not among them ───────────────────────
 *
 * It is the obvious fifth, and it cannot exist. DocumentRenderer is
 * deterministic by contract: the same onboarding renders the same bytes every
 * time, which is what lets the signing endpoint refuse a signature whose
 * fingerprint no longer matches. A document containing the moment of signing
 * would change between the preview the signer read and the render taken to store
 * it, so no signature could ever be accepted — the same circularity that keeps
 * the signatures themselves out of the document (see DocumentRenderer).
 *
 * The moment of signing belongs to the signature record, which stores it per
 * signatory with the person, the address and the fingerprint. `document.date` is
 * a different fact and is named as one: the day the paperwork was drawn up.
 */
final class ContractFacts
{
    public const ORGANIZATION_NAME = 'organization.name';
    public const CONTRACT_NAME     = 'contract.name';
    public const CONTRACT_VERSION  = 'contract.version';
    public const DOCUMENT_DATE     = 'document.date';

    /**
     * key => what to call it, and what it IS.
     *
     * The same two strings CommonCore carries, for the same reason: `label` goes
     * on the chip in the tag picker, `is` completes the sentence explaining what
     * the tag will print. One entry, so they cannot drift apart.
     *
     * @var array<string, array{label: string, is: string}>
     */
    public const ALL = [
        self::ORGANIZATION_NAME => [
            'label' => 'Business name',
            'is'    => 'the name of the business this agreement is with',
        ],
        self::CONTRACT_NAME => [
            'label' => 'Contract name',
            'is'    => 'what this template is called',
        ],
        self::CONTRACT_VERSION => [
            'label' => 'Version',
            'is'    => 'which version of the template produced the document, as a number',
        ],
        self::DOCUMENT_DATE => [
            'label' => 'Date of this document',
            'is'    => 'the day the paperwork was drawn up, written as YYYY-MM-DD — not the day '
                .'it was signed, which is recorded against each signature instead',
        ],
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function claims(string $key): bool
    {
        return array_key_exists($key, self::ALL);
    }

    public static function labelFor(string $key): string
    {
        return self::ALL[$key]['label'];
    }

    public static function describe(string $key): string
    {
        return self::ALL[$key]['is'];
    }

    /**
     * The four, ready to merge.
     *
     * Every caller passes values it already has rather than this class reading
     * anything, for the reason DocumentRenderer states about the common core: a
     * class that does no queries is one whose output depends only on its
     * arguments, and determinism is the property the whole signing flow rests on.
     *
     * `$date` is a string in YYYY-MM-DD and not a Carbon, deliberately. Handing
     * a date object to a renderer invites it to format one, and the format is the
     * decision — the same written form every date ANSWER uses (OnboardingForm
     * stores and prints `Y-m-d`), because 03/04 is two different days depending
     * on who is reading it and a contract is exactly the wrong place to find out.
     *
     * @return array<string, MergeValue>
     */
    public static function values(
        string $organizationName,
        string $contractName,
        int $version,
        string $date,
    ): array {
        return [
            self::ORGANIZATION_NAME => new MergeValue(self::labelFor(self::ORGANIZATION_NAME), $organizationName),
            self::CONTRACT_NAME     => new MergeValue(self::labelFor(self::CONTRACT_NAME), $contractName),
            self::CONTRACT_VERSION  => new MergeValue(self::labelFor(self::CONTRACT_VERSION), (string) $version),
            self::DOCUMENT_DATE     => new MergeValue(self::labelFor(self::DOCUMENT_DATE), $date),
        ];
    }
}
