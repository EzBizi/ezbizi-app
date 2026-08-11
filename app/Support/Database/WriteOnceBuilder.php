<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * The query builder for a table whose rows are written once and never touched
 * again.
 *
 * ── The hole this closes, and the one it does not ────────────────
 *
 * `ContractVersion` and `ContractSignature` both refuse edits with
 * `static::updating()`, and both docblocks describe that as the guarantee. Model
 * events are a weaker thing than they read as: they fire for a MODEL INSTANCE
 * being saved, and three ordinary ways of writing the same row never involve one.
 *
 *     ContractVersion::query()->update(['fields' => …])   no event
 *     $contract->versions()->update([…])                  no event
 *     $signature->saveQuietly()                           events suppressed
 *     $signature->deleteQuietly()                         events suppressed
 *
 * The middle two are the ones that matter: a published version's field list and
 * a signature's `content_hash` could each be rewritten in one statement, and a
 * tampered document would then verify as the one that was signed — which is the
 * single claim the whole evidentiary record is for.
 *
 * Every path above goes through an Eloquent builder, including the quiet ones:
 * `saveQuietly()` suppresses the event and still calls `performUpdate()`, which
 * calls `update()` here. So refusing at the builder covers all four, and covers
 * them for a caller who never knew the guard existed.
 *
 * ── What is left, stated rather than implied ─────────────────────
 *
 * `DB::table('contract_signatures')->update([…])` — the query builder underneath,
 * reached without Eloquent — is not covered and cannot be from PHP. Neither is
 * `mysql` at a prompt.
 *
 * The proper answer to both is a BEFORE UPDATE trigger, and it was tried: this
 * deployment runs MySQL 8.0 with binary logging on and `log_bin_trust_function_
 * creators` off, and the application's database user has no SUPER privilege, so
 * `CREATE TRIGGER` fails with error 1419. Granting SUPER to the account the web
 * process authenticates as would be a considerably worse trade than the hole it
 * closes, so the boundary is where it is and this paragraph is the honest
 * statement of it: Eloquent cannot rewrite these rows, and raw SQL can.
 */
class WriteOnceBuilder extends Builder
{
    /** @param  array<string, mixed>  $values */
    public function update(array $values): int
    {
        throw new RuntimeException($this->refusal('updated'));
    }

    public function delete(): mixed
    {
        throw new RuntimeException($this->refusal('deleted'));
    }

    public function forceDelete(): mixed
    {
        throw new RuntimeException($this->refusal('deleted'));
    }

    /** @param  array<string, mixed>  $extra */
    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new RuntimeException($this->refusal('updated'));
    }

    /** @param  array<string, mixed>  $extra */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new RuntimeException($this->refusal('updated'));
    }

    /**
     * `upsert` is an update wearing an insert's name — that is the whole point of
     * it — so it is refused rather than allowed through as a create.
     *
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new RuntimeException($this->refusal('updated'));
    }

    /**
     * Points at the model rather than repeating its reasoning.
     *
     * Each model states in full why ITS rows cannot change — a version because a
     * record would otherwise misdescribe the questions it was produced from, a
     * signature because evidence that can be edited is indistinguishable from
     * evidence that was. Copying either sentence here would leave two versions of
     * one argument to keep in step, and this message is read by a developer who
     * can go and look.
     */
    private function refusal(string $verb): string
    {
        return sprintf(
            '%s rows cannot be %s, and that includes through a query builder or a quiet '
            .'save — which is why this is refused here rather than only in the model\'s '
            .'own event. See %s for what the row is and why it is written once.',
            class_basename($this->getModel()),
            $verb,
            $this->getModel()::class,
        );
    }
}
