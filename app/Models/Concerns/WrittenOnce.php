<?php

namespace App\Models\Concerns;

use App\Support\Database\WriteOnceBuilder;

/**
 * A model whose rows are written once.
 *
 * One method, and it is a trait rather than a line copied onto each model so
 * that the paragraph explaining what it does and does not cover has one home —
 * see WriteOnceBuilder, which is where that paragraph is.
 *
 * ── This does NOT replace the model's own event guards ───────────
 *
 * `ContractVersion` and `ContractSignature` each refuse `updating` and `deleting`
 * with a sentence written for that row: what it is, and why changing it would
 * destroy the thing it exists to be. Those refusals are what somebody reads when
 * they hit the guard through the ordinary path, and they say more about a
 * specific table than a builder ever could.
 *
 * The two layers answer different callers. The event answers the developer who
 * called `$version->save()`. The builder answers the one who called
 * `ContractVersion::query()->update()`, or `saveQuietly()`, and who would
 * otherwise have been answered by nothing at all.
 */
trait WrittenOnce
{
    /**
     * The parameter is left untyped to match Model::newEloquentBuilder. PHP
     * forbids narrowing an inherited parameter, so adding the type here would be
     * a fatal error rather than a tightening.
     */
    public function newEloquentBuilder($query): WriteOnceBuilder
    {
        return new WriteOnceBuilder($query);
    }
}
