<?php

namespace App\Support\Privacy;

use App\Models\User;

/**
 * A record carrying values that are masked by default (EZBIZI-HR-PLAN.md,
 * "Sensitive fields"; §9).
 *
 * ── Why the record answers, rather than a list somewhere ─────────
 *
 * The whole difficulty of this feature is that the app cannot know what is
 * sensitive. A tenant defines the fields — "no 2 contracts require the same
 * stuff" — so "Bank account number" is not a column this software has ever
 * heard of. Sensitivity is a fact carried by the DATA, and the only thing that
 * can answer it is the row.
 *
 * That is why `sensitiveAttributes()` is an instance method and not a static
 * list or a `protected array $sensitive` on the class. For a contract answer,
 * one row of the same table is sensitive and the next is not, because the field
 * definitions behind them differ. A per-class declaration cannot express that,
 * and a mechanism that could only express it would be no use to the one feature
 * it was built for.
 *
 * ── Encryption is the model's job, and it is unconditional ───────
 *
 * §2 of the four behaviours is "unreadable in a database dump", and the
 * mechanism for it already exists: Laravel's `encrypted` cast, which is what
 * TOTP secrets use. It is NOT applied by this interface, for a reason worth
 * stating: a cast is per-column and per-class, and cannot be turned on for one
 * row and off for the next. So a table holding tenant-defined answers must cast
 * its value column `encrypted` for EVERY row, sensitive or not, and use the
 * sensitivity flag to drive the other three behaviours.
 *
 * Encrypting the non-sensitive ones too costs a little CPU and buys the thing
 * that matters: nobody can mark a field sensitive AFTER answers exist and
 * discover the old ones are sitting in the clear.
 *
 * A per-column holder — a fixed column that is always sensitive — casts that one
 * column and returns its name from `sensitiveAttributes()` unconditionally. Both
 * shapes work; the interface does not care which.
 */
interface HoldsSensitiveValues
{
    /*
     * IMPLEMENTING THIS IS NOT ENOUGH ON ITS OWN. Use the
     * `MasksSensitiveValues` trait beside it.
     *
     * The interface names which attributes are sensitive; the trait is what
     * makes `toArray()`, `toJson()` and `response()->json($model)` answer the
     * mask. Without it a model implementing this published the clear value
     * through every ordinary serialisation path while `SensitiveValues::read()`
     * — the only thing that masked — was called from two places in the whole
     * application.
     */

    /**
     * The attributes on THIS row that must not be read in the clear by default.
     *
     * @return string[]
     */
    public function sensitiveAttributes(): array;

    /**
     * May this person see them?
     *
     * Asked of the record rather than decided by SensitiveValues, for the same
     * reason attachments authorize against their subject: the record is the only
     * thing that knows both which permission governs it and whether the person
     * asking is the person the data is ABOUT — and "may I see my own bank
     * details" has to be answerable yes without an administrator's permission.
     *
     * A `true` here does not make the value visible on its own. The caller still
     * has to ASK to reveal it, and the reveal is still written to the audit log.
     */
    public function mayReveal(User $actor): bool;
}
