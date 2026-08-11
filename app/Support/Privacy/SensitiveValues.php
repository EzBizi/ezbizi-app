<?php

namespace App\Support\Privacy;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Masked by default, revealed on request, and every reveal recorded
 * (EZBIZI-HR-PLAN.md, "Sensitive fields"; §9, §10).
 *
 * Three of the flag's four behaviours live here. The fourth — encryption at
 * rest — is the model's own `encrypted` cast; see HoldsSensitiveValues for why
 * it cannot be applied from out here.
 *
 * ── The default is masked, and the default is what protects you ──
 *
 * Every read goes through `read()`, and it answers the mask unless the caller
 * explicitly asked for the value AND the record allows it. There is no method
 * that returns the clear value without auditing, which is deliberate: a
 * convenience accessor would be used by the second caller who found the audit
 * inconvenient, and the audit is the whole point. "Who looked at my bank
 * details" is a question that gets asked, and it can only be answered by the
 * system that was asked to show them.
 *
 * ── Why the mask is a fixed length ───────────────────────────────
 *
 * `••••••••` for everything, not `••••1234` and not one dot per character.
 *
 * A last-four mask is genuinely useful for confirming an account number, and it
 * is also four digits of somebody's bank account handed to everybody who can
 * open the screen. That is a policy question a tenant should get to answer, and
 * until they can, the safe default is the one that gives nothing away.
 *
 * A length-preserving mask is worse than it looks: it publishes how long the
 * value is, which for a national insurance number or a routing code narrows the
 * guess considerably and for a password says more than that.
 */
class SensitiveValues
{
    /**
     * One mask string for the whole application.
     *
     * AutomationsController masks webhook secrets with this too, so a client
     * that learns to recognise a masked value learns it once. Any client that
     * echoes a masked value back on save must be refused rather than believed —
     * see the automations screen, which was written that way after saving
     * `••••••••` over a working secret.
     */
    public const MASK = '••••••••';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * One attribute, as this caller is entitled to see it.
     *
     * One at a time, and there is deliberately no "reveal everything on this
     * record" call. A bulk reveal would write one audit entry covering an
     * unknown number of disclosures, and the log would stop being able to answer
     * which field somebody actually looked at — which is the only question it is
     * being kept for.
     *
     * @param  bool  $reveal  the caller ASKED for the clear value. Absent, the
     *                        mask is returned even to somebody who could have
     *                        seen it — so an ordinary screen load never
     *                        generates a reveal event, and the events that do
     *                        exist all mean somebody deliberately looked.
     * @return array{value: mixed, sensitive: bool, revealed: bool}
     *
     * @throws AuthorizationException when a reveal is asked for and refused
     */
    public function read(
        HoldsSensitiveValues $record,
        string $attribute,
        ?User $actor,
        bool $reveal = false,
    ): array {
        if (! $this->isSensitive($record, $attribute)) {
            return [
                'value'     => $record->getAttribute($attribute),
                'sensitive' => false,
                'revealed'  => false,
            ];
        }

        if (! $reveal) {
            return ['value' => self::MASK, 'sensitive' => true, 'revealed' => false];
        }

        if ($actor === null || ! $record->mayReveal($actor)) {
            throw new AuthorizationException(
                'You may not see this value in the clear. It is marked sensitive, which means '
                .'it is shown masked unless somebody has been given permission to reveal it — '
                .'and every reveal is recorded against the person who asked.'
            );
        }

        /*
         * Written BEFORE the value is returned, not after.
         *
         * If the audit write fails, nobody sees the value: the exception
         * propagates and the response is a 500 rather than a disclosure with no
         * record of it. That is the right way round for the one event whose
         * absence is the failure — an unaudited reveal is indistinguishable from
         * no reveal, which is exactly what somebody misusing this would want.
         */
        $this->audit->log('sensitive_value.revealed', $record, metadata: [
            // The FIELD, never the value. An audit log is read by more people
            // than a bank account number should be, and copying the value into
            // an append-only table that erasure cannot reach would put it
            // somewhere strictly worse than where it started.
            'field' => $attribute,
        ], actor: $actor);

        return [
            'value'     => $record->getAttribute($attribute),
            'sensitive' => true,
            'revealed'  => true,
        ];
    }

    /**
     * Is this attribute sensitive on this row?
     *
     * Takes a plain Model so callers that iterate mixed rows — the exporter, for
     * one — do not each have to write the instanceof.
     */
    public function isSensitive(Model $record, string $attribute): bool
    {
        return $record instanceof HoldsSensitiveValues
            && in_array($attribute, $record->sensitiveAttributes(), true);
    }
}
