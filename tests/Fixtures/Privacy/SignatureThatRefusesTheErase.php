<?php

namespace Tests\Fixtures\Privacy;

use App\Models\ContractSignature;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\PersonalDataEntry;

/**
 * `contract_signatures`, with one column declared `erase` instead of `keep`.
 *
 * ── The case nothing in app/Models produces ──────────────────────
 *
 * Anonymiser::eraseColumns reports a refused write rather than throwing, and
 * that catch had no test: removing it left all thirty privacy tests green,
 * because the only refusal the suite ever provoked was an Attachment on the DROP
 * path. A refusal on the erase path is not hypothetical, though — it is one
 * changed declaration away, which is exactly what this fixture is.
 *
 * `ContractSignature` uses `WrittenOnce`, so the refusal comes from the real
 * guard rather than from anything invented here: the Anonymiser writes with
 * `saveQuietly()`, `saveQuietly()` still goes through the Eloquent builder, and
 * WriteOnceBuilder::update() refuses it with the sentence a developer reads.
 * That is the shape of the bug this covers — somebody flips a `keep` to an
 * `erase` on an evidentiary table, the inventory starts publishing a promise,
 * and the purge cannot keep it.
 *
 * Everything else is inherited, deliberately: the same table, the same
 * `personalDataAbout` query, the same guards. Only the disposition differs, so
 * the test is measuring the Anonymiser and not a second model.
 */
class SignatureThatRefusesTheErase extends ContractSignature
{
    /**
     * Inherited from a parent that does not set one, so Eloquent would derive
     * `signature_that_refuses_the_erases` from the class name.
     */
    protected $table = 'contract_signatures';

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('signer_label', PersonalDataEntry::IDENTITY,
                'Who signed. Declared erasable here, which the real model argues at length '
                .'that it must never be.',
                Erasure::erase('Former staff')),
        ];
    }
}
