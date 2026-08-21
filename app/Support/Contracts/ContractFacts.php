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
 * knows. So these resolve out of the record itself.
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
    public const ORGANIZATION_NAME    = 'organization.name';
    public const ORGANIZATION_ADDRESS = 'organization.address';
    public const CONTRACT_NAME        = 'contract.name';
    public const CONTRACT_VERSION     = 'contract.version';
    public const DOCUMENT_DATE        = 'document.date';
    public const DOCUMENT_DAY         = 'document.day';
    public const DOCUMENT_MONTH       = 'document.month';
    public const DOCUMENT_YEAR        = 'document.year';

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
        self::ORGANIZATION_ADDRESS => [
            'label' => 'Business address',
            'is'    => 'the address of the business this agreement is with, as set for the organization',
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
        self::DOCUMENT_DAY => [
            'label' => 'Day of this document',
            'is'    => 'the day-of-month of the paperwork date, as a number — the same day as '
                .'document.date, never the day it was signed',
        ],
        self::DOCUMENT_MONTH => [
            'label' => 'Month of this document',
            'is'    => 'the month name of the paperwork date in English — the same day as '
                .'document.date, never the day it was signed',
        ],
        self::DOCUMENT_YEAR => [
            'label' => 'Year of this document',
            'is'    => 'the four-digit year of the paperwork date — the same day as '
                .'document.date, never the day it was signed',
        ],
    ];

    /** English month names, so a document does not change with the server locale. */
    private const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
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
     * The facts, ready to merge.
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
        string $organizationAddress = '',
    ): array {
        [$day, $month, $year] = self::dateParts($date);

        return [
            self::ORGANIZATION_NAME    => new MergeValue(self::labelFor(self::ORGANIZATION_NAME), self::present($organizationName)),
            self::ORGANIZATION_ADDRESS => new MergeValue(self::labelFor(self::ORGANIZATION_ADDRESS), self::present($organizationAddress)),
            self::CONTRACT_NAME        => new MergeValue(self::labelFor(self::CONTRACT_NAME), self::present($contractName)),
            self::CONTRACT_VERSION     => new MergeValue(self::labelFor(self::CONTRACT_VERSION), (string) $version),
            self::DOCUMENT_DATE        => new MergeValue(self::labelFor(self::DOCUMENT_DATE), self::present($date)),
            self::DOCUMENT_DAY         => new MergeValue(self::labelFor(self::DOCUMENT_DAY), $day),
            self::DOCUMENT_MONTH       => new MergeValue(self::labelFor(self::DOCUMENT_MONTH), $month),
            self::DOCUMENT_YEAR        => new MergeValue(self::labelFor(self::DOCUMENT_YEAR), $year),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} day, month name, year
     */
    private static function dateParts(string $date): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return [null, null, null];
        }

        $month = (int) $m[2];
        $day   = (int) $m[3];

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return [null, null, null];
        }

        return [(string) $day, self::MONTHS[$month], $m[1]];
    }

    private static function present(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
