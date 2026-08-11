<?php

namespace App\Support\Privacy;

/**
 * Makes a model's sensitive attributes masked when it is SERIALISED.
 *
 * ── Why the interface was not enough ─────────────────────────────
 *
 * `HoldsSensitiveValues` names which attributes are sensitive and
 * `SensitiveValues::read()` masks them — and its docblock claimed "every read
 * goes through read(), and it answers the mask unless the caller explicitly
 * asked". That was not true of the path a controller reaches for first.
 *
 * A model has to be turned into JSON to be answered with, and `toArray()` walks
 * the attributes directly. So on a row holding a sensitive value:
 *
 *     $model->toArray()                     -> the clear IBAN
 *     response()->json($model)              -> the clear IBAN
 *     $model->only(['notes'])               -> the clear IBAN
 *     ->with('answers') on a parent         -> the clear IBAN, in every child
 *
 * Two call sites in the whole application opted into the masking; every other
 * way of returning a model published the value. For tenant-defined contract
 * answers — the thing this mechanism exists to protect — serialisation IS the
 * delivery path, so the protection has to live there rather than beside it.
 *
 * ── Masked by default, for everybody ─────────────────────────────
 *
 * This does not consult permissions, and deliberately. Revealing is a separate,
 * audited act with its own call — `SensitiveValues::read()` — so that every
 * clear value in a response corresponds to a row in the log saying who asked
 * for it. A trait that quietly unmasked for anybody entitled would put the
 * clear value back into ordinary responses and leave the audit trail describing
 * a fraction of the disclosures.
 *
 * The result: the ONLY way a sensitive value leaves this application in the
 * clear is through the call that writes down that it did.
 */
trait MasksSensitiveValues
{
    /**
     * Laravel routes `toArray()`, `toJson()`, `jsonSerialize()` and every
     * `response()->json($model)` through here, so one override covers all of
     * them — including a model nested inside an eager-loaded relation, which is
     * the case a per-controller fix would have missed.
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        foreach ($this->sensitiveAttributes() as $attribute) {
            if (array_key_exists($attribute, $attributes) && $attributes[$attribute] !== null) {
                $attributes[$attribute] = SensitiveValues::MASK;
            }
        }

        return $attributes;
    }
}
