<?php

namespace App\Support\Hr;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The invitation is in no state to be acted on.
 *
 * Its message is written to be READ — by somebody on the Invitations tab, and
 * by somebody reading why an automation rule did nothing. Those are the same
 * sentence because they are the same fact, which is the whole reason there is
 * one implementation of these acts rather than two.
 *
 * NOT a permission refusal. Whether the caller may act at all is the policy's
 * question and App\Support\Access\Refusal carries that sentence; a rule has no
 * actor to ask it of. This one is about the invitation: already accepted,
 * already spent, nothing left to withdraw.
 *
 * Same shape as Fleet's CannotAssignVehicle, deliberately — Automations
 * renders the message verbatim into a run log, and two modules inventing two
 * ways to say "I could not, and here is why" would read as two kinds of
 * failure to whoever is looking at that log.
 *
 * Both named in prose rather than with {@see}, because Pint turns a
 * fully-qualified reference into a real `use` line and HR would then import a
 * Fleet class it never calls.
 */
final class CannotActOnInvitation extends RuntimeException
{
    /**
     * Which input this is about, for a caller that answers with a field map.
     *
     * Null means the invitation itself, and the caller supplies its own name
     * for that — this endpoint has always said `invitation`, that one
     * `invitation_id`. Two of these refusals are about the CONTRACT rather
     * than the invitation, and moving them onto the invitation's key was a
     * wire change a test caught: a screen that puts the message under the
     * field it concerns would have hung "this template has no signature field"
     * off the wrong control.
     *
     * A rule ignores it. It is here because the same sentence has to reach two
     * surfaces that address it differently, which is the whole reason this
     * class exists rather than two.
     */
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
