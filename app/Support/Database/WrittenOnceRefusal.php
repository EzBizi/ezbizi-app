<?php

namespace App\Support\Database;

use RuntimeException;

/**
 * A write refused by [WriteOnceBuilder] because the row is written once.
 *
 * ── Why this has a type at all ───────────────────────────────────
 *
 * It was a bare `RuntimeException`, and one caller has to tell a DECLARED
 * refusal apart from any other failure: `Anonymiser::refusalRaisedBy`, which
 * turns a refusal into a line in the purge report — "this row was kept, and
 * here is why" — and lets everything else propagate.
 *
 * Catching `RuntimeException` there was too wide, and reachably so. The purge
 * writes `onboarding_answers.value` through an `encrypted` cast, and Laravel's
 * `EncryptException`, `DecryptException` and `JsonEncodingException` all extend
 * `RuntimeException`. A decryption failure while erasing somebody would have
 * been filed as a considered refusal, the walk would have carried on, and the
 * surrounding transaction would have COMMITTED — so the person's answers
 * survive an erasure request and the published reason they survived is a
 * framework message about a cipher. A privacy report that describes a fault as
 * a decision is the exact defect the report exists to prevent.
 *
 * Narrowing the catch was the alternative, and it needs a type to narrow TO.
 * So the guard says what it is, and everything that is not a guard is a fault
 * that reaches the top and rolls the transaction back.
 *
 * ── Not a message anybody but a developer reads ──────────────────
 *
 * `WriteOnceBuilder::refusal()` writes for whoever is holding the stack trace:
 * it names the model class and points at it. That is deliberate and R8 does not
 * apply, because this never reaches an HTTP response — the only caller that
 * converts one into text is the purge command, which platform staff run from a
 * console.
 */
final class WrittenOnceRefusal extends RuntimeException
{
}
