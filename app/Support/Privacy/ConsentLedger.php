<?php

namespace App\Support\Privacy;

use App\Models\User;

/**
 * Everything one person has agreed to, and what asking for it is worth (§9).
 *
 * The same shape as DataInventory and for the same reason: a derived listing,
 * no table of its own, no admin screen of its own, assembled by asking the
 * models that hold the evidence. See ConsentRecord for the decision this is
 * built on — what consent means in this product, and why a generic consents
 * table would have been the wrong answer confidently recorded.
 *
 * ── It says what it does NOT cover, in the response ──────────────
 *
 * Which is most of what the software does. An employee's record is held because
 * the employment requires it; a customer's address is held because the work
 * happens there. Neither is consented to, neither should be described as
 * consented to, and a ledger that listed only the consents would leave a reader
 * to assume everything else was one. §16 defers deciding which lawful basis
 * governs those under which US regime — this states plainly that the answer is
 * not "consent", which is the part that must not be got wrong now, because it is
 * the part that is expensive to unsay after somebody has been told otherwise.
 */
class ConsentLedger
{
    public function __construct(private readonly DeclaringModels $models) {}

    public function forPerson(User $subject): array
    {
        $consents = [];

        foreach ($this->models->recordingConsent() as $class) {
            foreach ($class::consentsGivenBy($subject) as $record) {
                $consents[] = $record->toArray();
            }
        }

        // Newest first: "what is true now" is what a screen opens on, and a
        // consent statement is revised over time so the most recent wording is
        // the one somebody is asking about.
        usort($consents, fn (array $a, array $b) => ($b['given_at'] ?? '') <=> ($a['given_at'] ?? ''));

        return [
            'person'   => ['id' => $subject->getKey()],
            'consents' => $consents,
            'not_consent' => $this->notConsent(),
        ];
    }

    /**
     * The processing that happens without asking, and why that is not a gap.
     *
     * Published beside the consents rather than left implicit. The failure mode
     * of a consent ledger is the reader concluding that anything absent from it
     * was never processed — or worse, that everything present in the product was
     * consented to and can therefore be withdrawn.
     *
     * @return string[]
     */
    private function notConsent(): array
    {
        return [
            'An employee\'s record — name, contact details, date of birth, the answers on '
                .'their onboarding form — is held because the employment and the contract '
                .'require it, not because anybody agreed to it separately. Withdrawing '
                .'something would not stop it, so it is not offered as a consent.',
            'A customer\'s details are held to do the work they asked for and to invoice it. '
                .'The same applies.',
            'The audit trail records what everybody did, and that is a requirement of the '
                .'system (§2.2) rather than a choice anybody makes.',
            'Which US state privacy regimes apply, and therefore what a person may ask to '
                .'be stopped, is a legal question deferred by §16 pending counsel. What is '
                .'listed above is what this software has actually asked and recorded.',
        ];
    }
}
