<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SendingIdentity;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Mail\DnsRecord;
use App\Support\Mail\DomainVerification;
use App\Support\Mail\MailIdentity;
use App\Support\Mail\MailTransport;
use App\Support\Mail\SendingIdentityResolver;
use App\Support\Mail\SendingState;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tenant's own sending address, over the API (§6, §2.1 category 5).
 *
 * Shaped after SettingsController on purpose — same scope resolution, same
 * permission, same reach rule, same "the row that comes back tells you where the
 * value came from and whether you may change it". The two screens sit beside
 * each other and behave identically because the underlying rule is identical:
 * a franchise administrator may set their own, and may never touch the company's.
 *
 * ── What this endpoint cannot do, however it is called ───────────
 *
 * It cannot mark an identity verified. Three separate things stop it: the
 * validated input names four keys and none of them is a state, `$fillable` on
 * the model names none of them either, and `update()` puts the state columns
 * back as it found them at the one line where a request becomes a row. Every
 * transition goes through DomainVerification instead, which reaches a real
 * DnsVerifier or refuses. `verify` below asks for a CHECK; it does not assert an
 * outcome.
 *
 * The third of those was added after a mutation run: with the first two deleted
 * the test suite stayed green, because a create happens to be settled again a
 * few lines later and the test only exercised a create. See the comment on that
 * line, and SendingIdentitiesApiTest's second-write case.
 *
 * It cannot return a transport credential either. The response is an explicit
 * allowlist of fields rather than the model — and the model masks `credentials`
 * through MasksSensitiveValues as well, so the two would both have to be wrong
 * on the same day.
 */
class SendingIdentitiesController extends Controller
{
    /**
     * The same permission the rest of the settings screen uses.
     *
     * A sending address is an organization setting in every sense that matters
     * (§2.1 category 5), and a second permission for it would mean an
     * administrator who can change the tax rate discovering they cannot change
     * the address their own mail comes from.
     */
    private const PERMISSION = 'organizations.edit';

    /**
     * The sentence an address that CANNOT SEND carries, and only that one.
     *
     * Written for the administrator, in the shape SettingDefinition demands of
     * a setting that is not yet in effect: it says what it is waiting for
     * rather than only that it is waiting. It is careful about WHICH mail,
     * because "EzBizi cannot send email" would be false -- password resets and
     * invitations go out today, from EzBizi, and always will.
     *
     * It names inspection reports because that is what actually reaches
     * asTenant today (ActionRunner:452). If a second module starts sending as
     * the tenant, this sentence is the thing that goes stale, and it goes
     * stale quietly -- the same way its predecessor did.
     */
    private const CANNOT_SEND_YET =
        'this address cannot send yet. Mail EzBizi sends on your behalf -- an inspection '
        .'report today -- goes out from it only once its DNS records are published and '
        .'verified, and is refused until then rather than sent from somewhere else. '
        .'Signing-in, password resets and joining messages come from EzBizi and always '
        .'will, so this changes nothing about those. Publishing the records and having '
        .'them spread takes days; the form takes a minute.';

    /**
     * The events this feature would raise if anything could declare them.
     *
     * Probed against the module registry on every response rather than
     * hardcoded as absent — see {@see automationSurface()}.
     */
    private const WOULD_EMIT = ['sending_identity.verified', 'sending_identity.failed'];

    public function __construct(
        private readonly SendingIdentityResolver $resolver,
        private readonly DomainVerification $verification,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/mail/sending-identity?scope_type=franchise&scope_id={uuid}
     *
     * The effective identity, where it came from, and — when this scope holds
     * its own — the records to publish. See {@see payload()} for the shape and
     * for why it is flat rather than effective-and-here side by side.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);

        return response()->json($this->payload($request, $scope));
    }

    /**
     * PATCH /api/v1/mail/sending-identity — set, or replace, the address here.
     *
     * ── PATCH, and the scope in the query ────────────────────────
     *
     * There is at most one address per scope and the caller names the scope
     * rather than a record id, so PUT would read more naturally. It is PATCH
     * because the client's ApiClient puts a query string on `patch` and
     * `delete` and not on `put` and `post` — and that file belongs to another
     * piece of work. This also lands it on the same pair of verbs `/settings`
     * uses for the same job at the same scopes, which is the better outcome
     * anyway: the two screens are cousins and now look like it.
     *
     * ── Why `transport` is optional here ─────────────────────────
     *
     * Because there is exactly one thing a tenant may choose, so making them
     * send it would be asking for a value with no alternative. It is still
     * VALIDATED when sent, so the day there are two the endpoint already
     * refuses a third — and the default is named rather than implied.
     */
    public function update(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);
        $this->authorizeWrite($request, $scope, 'change the sending address');

        $input = $request->validate([
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name'    => ['required', 'string', 'max:255'],
            'reply_to'     => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'transport'    => [
                'sometimes',
                'in:'.implode(',', array_map(
                    fn (MailTransport $t) => $t->value,
                    MailTransport::tenantChoices()
                )),
            ],
        ]);

        $input['transport'] ??= MailTransport::VerifiedDomain->value;

        DB::transaction(function () use ($scope, $input) {
            $existing = $this->resolver->rowAt($scope);

            $before = $existing === null ? null : [
                'from_address' => $existing->from_address,
                'domain'       => $existing->domain(),
                'transport'    => $existing->transport,
                'state'        => $existing->getAttribute('state'),
            ];

            $identity = $existing ?? new SendingIdentity([
                'organization_id' => $this->organizationIdOf($scope),
                'scope_type'      => $this->resolver->scopeTypeOf($scope),
                'scope_id'        => $scope->getKey(),
            ]);

            $identity->fill($input);

            /*
             * The verification state is put back exactly as it was, whatever the
             * request contained. A new row starts Unverified.
             *
             * ── Why this line exists when two other things already stop it ──
             *
             * A request cannot reach these columns today: `$input` is what
             * `validate()` returned and lists four keys, and SendingIdentity's
             * `$fillable` names none of them. Both are real, and neither is
             * visible from this line — one is fifteen lines up in a rule array
             * and the other is in another file.
             *
             * That mattered, and it was measured. Widening `$fillable` left the
             * suite green; so did switching `fill($input)` to
             * `fill($request->all())`; so did doing BOTH, because prepare()
             * below happens to settle the state again on the create path. On
             * the UPDATE path it does not — reconsider() deliberately leaves a
             * still-valid proof alone — so with both of those guards gone, a
             * second PUT carrying `"state": "verified"` would have been stored
             * and nothing would have failed.
             *
             * So the invariant is stated here, at the one line where a request's
             * values become a row, instead of being an emergent property of two
             * distant allowlists and the order of the calls below it.
             */
            $identity->forceFill($existing === null
                ? [
                    'state'              => SendingState::Unverified->value,
                    'state_reason'       => null,
                    'verification_token' => null,
                    'verified_at'        => null,
                    'last_checked_at'    => null,
                ]
                : [
                    'state'              => $existing->getOriginal('state'),
                    'state_reason'       => $existing->getOriginal('state_reason'),
                    'verification_token' => $existing->getOriginal('verification_token'),
                    'verified_at'        => $existing->getOriginal('verified_at'),
                    'last_checked_at'    => $existing->getOriginal('last_checked_at'),
                ]);

            $identity->save();

            /*
             * Whether the proof survives the edit is DomainVerification's call,
             * not this controller's.
             *
             * Changing info@ to hello@ on a domain that is already proved keeps
             * the verification — the DOMAIN was proved and it has not moved.
             * Changing the domain, or the transport, throws it away. Deciding
             * that here would be a second home for the rule, and the copy that
             * gets it wrong is always the one in the controller.
             */
            if ($before === null) {
                $this->verification->prepare($identity);
            } else {
                $this->verification->reconsider($identity, $before['domain'], $before['transport']);
            }

            $this->resolver->flush();

            $this->audit->log(
                action: 'sending_identity.updated',
                target: $scope,
                changes: [
                    'before' => $before,
                    'after'  => [
                        'from_address' => $identity->from_address,
                        'domain'       => $identity->domain(),
                        'transport'    => $identity->transport,
                        'state'        => $identity->getAttribute('state'),
                    ],
                ],
            );
        });

        return response()->json($this->payload($request, $scope));
    }

    /**
     * DELETE /api/v1/mail/sending-identity — clear it, and inherit again.
     *
     * Distinct from setting the parent's current address here, which would
     * freeze today's answer as a choice and stop following the parent. The same
     * distinction the settings reset makes, for the same reason.
     */
    public function destroy(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);
        $this->authorizeWrite($request, $scope, 'clear the sending address');

        DB::transaction(function () use ($scope) {
            $existing = $this->resolver->rowAt($scope);

            if ($existing !== null) {
                $address = $existing->from_address;
                $existing->delete();
                $this->resolver->flush();

                $this->audit->log('sending_identity.reset', $scope, ['from_address' => $address]);
            }
        });

        return response()->json($this->payload($request, $scope));
    }

    /**
     * POST /api/v1/mail/sending-identity/check — ask DNS, record the answer.
     *
     * Named `check` rather than `verify` because that is what it does. It asks
     * for a look and writes down what came back; it does not assert an outcome,
     * and there is no request body that could. On this installation the answer
     * is "nobody looked", the address stays waiting, and the response says so
     * in a sentence — the honest result, and not a failure.
     *
     * The scope arrives in the BODY here rather than the query, because the
     * client's ApiClient puts no query string on `post`. `resolveScope` reads
     * both without caring which, so this is one validated pair either way.
     */
    public function check(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);
        $this->authorizeWrite($request, $scope, 'check the sending address');

        $identity = $this->resolver->rowAt($scope);

        if ($identity === null) {
            return response()->json([
                'message' => 'There is no sending address set at this level, so there is '
                    .'nothing to check. Set one first — or leave it, and mail keeps going out '
                    .'under the address inherited from above.',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->verification->check($identity);
        $this->resolver->flush();

        $this->audit->log('sending_identity.checked', $scope, [
            'from_address' => $identity->from_address,
            'state'        => $identity->getAttribute('state'),
        ]);

        return response()->json($this->payload($request, $scope));
    }

    // ── the response ─────────────────────────────────────────────

    /**
     * One flat object, answered by every endpoint here.
     *
     * A write returns the new state of the thing it wrote, so the panel can put
     * the answer straight back into itself instead of refetching the screen.
     * Same reasoning as SettingsController::row, and the same failure avoided:
     * the settings screen used to blank itself to a spinner after every change.
     *
     * ── Flat, not effective-and-here side by side ────────────────
     *
     * The first draft answered `{effective: {…}, here: {…}}`, which is the more
     * accurate model and the wrong shape for the screen that reads it. Somebody
     * looking at Connecticut is asking "what does my mail come from", and the
     * answer is one address; whether Connecticut CHOSE it is what
     * `source.inherited` says. Handing the client two addresses makes it pick
     * which is the real one, and the day it picks differently from the cascade
     * the screen is confidently wrong.
     *
     * So the top level is the EFFECTIVE identity. What belongs to this scope
     * alone is the DNS records inside `verification`, which are sent only when
     * this scope holds its own address — see {@see verificationOf()}.
     */
    private function payload(Request $request, Model $scope): array
    {
        $effective = $this->resolver->for($scope);
        $here      = $this->resolver->rowAt($scope);
        $source    = $this->resolver->sourceOf($scope);
        $refusal   = $this->refusalFor($request->user(), $scope, 'change the sending address');

        return [
            'data' => [
                // What mail from this scope actually goes out as, wherever it
                // came from. Never null: the cascade always ends somewhere.
                'from_address' => $effective->fromAddress,
                'from_name'    => $effective->fromName,
                'reply_to'     => $effective->replyTo,

                /*
                 * Where that came from, or null when it is the platform's.
                 *
                 * Null is a different fact from `inherited: false`, and the
                 * panel draws it differently: nothing is set anywhere in this
                 * business, versus somebody in it chose this.
                 */
                'source' => $source === null ? null : [
                    'type'      => $this->resolver->scopeTypeOf($source),
                    'id'        => $source->getKey(),
                    'name'      => $source->name,
                    'inherited' => ! $source->is($scope),
                ],

                'verification' => $this->verificationOf($effective, $here),

                /*
                 * How it physically leaves, in words.
                 *
                 * A label and not a picker, because there is exactly one thing
                 * a tenant may choose and a list of one is a control that asks
                 * a question with no second answer. When bring-your-own-SMTP
                 * arrives this grows a `choices` key and the screen grows a
                 * picker; until then it is a statement.
                 */
                'transport' => [
                    'value' => $effective->transport->value,
                    'label' => $effective->transport->label(),
                ],

                /*
                 * Whether this address is inert, ASKED rather than asserted.
                 *
                 * The same admission SettingDefinition::notYetInEffect makes,
                 * in the same words the settings rows use, because it is the
                 * same lie being avoided: a control that saves, audits,
                 * inherits and changes nothing is worse than one that is
                 * missing, since the screen presents it as working.
                 *
                 * IT USED TO BE A CONSTANT, and the reasoning under it was
                 * "what has no caller is MailDispatcher::asTenant, because the
                 * module that will call it is Marketing's Newsletter and that
                 * is a declared placeholder". Inspection landed 5efe1c55 --
                 * an `inspection.report.email` action whose ActionRunner:452
                 * calls asTenant, the first real caller -- and that premise
                 * stopped being true, which turned this line into the
                 * INVERSION of the standard the paragraph above sets: a
                 * control that works, presented as inert. Routed here by the
                 * deploy desk, verified against the callers rather than
                 * relayed.
                 *
                 * So it is asked per identity now. `canSend()` is the single
                 * question the transport already answers -- verified domain,
                 * or a transport with nothing to prove -- and it is the same
                 * question `asTenant` throws on, so this line and the send
                 * cannot come to disagree.
                 *
                 * Null for the platform fallback as well, and deliberately:
                 * nothing of theirs is inert when they have set nothing. The
                 * panel already says "Nothing set".
                 */
                'not_yet_in_effect' => $effective->canSend() ? null : self::CANNOT_SEND_YET,

                'automation' => $this->automationSurface(),

                'may_edit'        => $refusal === null,
                'may_edit_reason' => $refusal,
            ],
            'meta' => [
                'scope_type' => $this->resolver->scopeTypeOf($scope),
                'scope_id'   => $scope->getKey(),
            ],
        ];
    }

    /**
     * Whether the address in force is proved, and what to do if it is not.
     *
     * Null when the platform is answering: there is nothing for a tenant to
     * verify about EzBizi's own domain, and a block reading "Verified" against
     * an address they have never heard of invites somebody to go hunting for
     * the DNS records behind it.
     *
     * ── Why the records are only sent at their own scope ─────────
     *
     * `$here`, not the row the cascade landed on. Connecticut inheriting A-1
     * Chimney's address gets the STATE of that address — it is what decides
     * whether their mail goes anywhere — and no records, because the records
     * belong to A-1 Chimney and are not Connecticut's to publish or to check.
     * The panel keys its "Check now" button off a non-empty list, so this is
     * also what stops that button appearing where the check would 404.
     */
    private function verificationOf(MailIdentity $effective, ?SendingIdentity $here): ?array
    {
        if ($effective->isPlatform()) {
            return null;
        }

        $own = $here !== null;

        return [
            'state' => $effective->state->value,
            'label' => $effective->state->label(),

            // What the last look actually reported — the system's own sentence
            // and not one composed here. Null before anything has looked.
            'detail' => $effective->stateReason,

            // What to do about it (R8). Null when it can send, which is the one
            // state with nothing to ask of anybody.
            'next_step' => $own
                ? $effective->refusal()
                : $this->inheritedNextStep($effective),

            /*
             * No `found` key on any record, deliberately.
             *
             * The panel prints "Found" or "Not there yet" per record when the
             * server says so, and this server cannot say: what comes back from
             * a DnsVerifier is one verdict for the domain rather than a verdict
             * per record, and no verifier is connected in any case. Leaving the
             * key out leaves that line undrawn. Sending `false` would tell a
             * tenant their record is missing when nothing had looked at it.
             */
            'records' => $own
                ? array_map(fn (DnsRecord $r) => $r->toArray(), $this->verification->recordsFor($here))
                : [],
        ];
    }

    /**
     * The next step when the address in force belongs to somebody above you.
     *
     * The generic refusal says to open the sending address and start
     * verification, and here that is the wrong instruction twice over: the
     * records are not theirs to publish, and setting one at this level would
     * change which address is used rather than fix the one that is stuck.
     */
    private function inheritedNextStep(MailIdentity $effective): ?string
    {
        if ($effective->canSend()) {
            return null;
        }

        $whose = $effective->sourceName ?? 'a level above this one';

        return sprintf(
            'This address belongs to %s, so the DNS records for it are published and '
            .'checked there rather than here. Somebody with authority over %s can finish '
            .'it. Until then mail from here goes out from the EzBizi address instead.',
            $whose,
            $whose,
        );
    }

    /**
     * What automations can run on — asked of the registry, not listed here.
     *
     * ── The house rule, and why the answer is empty today ────────
     *
     * Every feature wires into automations, and a module with nothing to emit
     * must say so explicitly with a reason rather than stay silent. This one
     * emits nothing, and not for want of thinking about it: the two events
     * worth having are a domain finishing verification and a check failing
     * after it had passed. A trigger has to be DECLARED on a ModuleDefinition
     * before EventEmitter will accept it — see EventEmitter::assertRegistered,
     * which throws on an undeclared key precisely so a rule cannot be built for
     * an event that can never arrive. Those declarations live in
     * ModuleServiceProvider, which this change does not own. Marketing's own
     * docblock already records that "Bounced" and "Failed to send" belong
     * wherever the tenant mail path is built; these two belong beside them.
     *
     * So the answer is DERIVED rather than written down. The moment somebody
     * declares `sending_identity.verified` or `sending_identity.failed` on a
     * module, this starts listing it and the note stops being printed, with
     * nothing here edited. A hardcoded empty list beside a hardcoded apology
     * would still be apologising a year after the triggers existed.
     */
    private function automationSurface(): array
    {
        $emits = [];

        foreach (self::WOULD_EMIT as $key) {
            if (($trigger = app(ModuleRegistry::class)->findTrigger($key)) !== null) {
                $emits[] = ['key' => $key, 'label' => $trigger->label];
            }
        }

        return [
            'emits' => $emits,
            'note'  => $emits === []
                ? 'No automation can run on a sending address yet. The two worth having are '
                    .'a domain finishing verification and a check failing after it had '
                    .'passed, and neither is offered until the events behind them are '
                    .'declared — a rule built on an event nothing raises would save, switch '
                    .'on and then wait for ever.'
                : null,
        ];
    }

    // ── scope, permission and reach ──────────────────────────────

    /**
     * Resolve the scope from the request.
     *
     * Tenant-scoped, so a scope_id belonging to another organization is simply
     * not found — the caller cannot reach across the boundary by guessing an id.
     */
    private function resolveScope(Request $request): Model
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'in:organization,company,franchise'],
            'scope_id'   => ['required', 'uuid'],
        ]);

        $model = match ($validated['scope_type']) {
            'organization' => $this->tenant->get()?->getKey() === $validated['scope_id']
                ? $this->tenant->get()
                : null,
            'company'   => Company::find($validated['scope_id']),
            'franchise' => Franchise::find($validated['scope_id']),
        };

        abort_if($model === null, Response::HTTP_NOT_FOUND, 'Scope not found.');

        return $model;
    }

    private function organizationIdOf(Model $scope): string
    {
        return $scope instanceof Organization
            ? $scope->getKey()
            : $scope->organization_id;
    }

    private function authorizeWrite(Request $request, Model $scope, string $act): void
    {
        $refusal = $this->refusalFor($request->user(), $scope, $act);

        abort_if($refusal !== null, Response::HTTP_FORBIDDEN, (string) $refusal);
    }

    /**
     * Why this person may not write here, or null when they may.
     *
     * Two checks, both required, exactly as on the settings screen: does the
     * role grant the permission (what), and does the person's assignment reach
     * this scope (where). The owner's rule in their words — "a franchise user
     * should not be able to modify parent preferences, a company user should not
     * be able to modify org preferences" — is the SECOND check, and it is the one
     * that stops a Connecticut manager rewriting A-1 Chimney's sending address.
     *
     * Asked once and used twice: the row's `may_edit_reason`, and every write.
     * They used to be computed separately on the settings screen and drifted,
     * so the client drew a live control for a scope the server then refused.
     */
    private function refusalFor(User $user, Model $scope, string $act): ?string
    {
        if (! $user->hasPermission(self::PERMISSION)) {
            return Refusal::permission($act)->message();
        }

        if ($this->reaches($user, $scope)) {
            return null;
        }

        // Organization-wide: refused by POSITION rather than by place. There is
        // no other organization they could have done it on instead.
        if ($scope instanceof Organization) {
            return Refusal::abovePosition($act)->message();
        }

        return Refusal::noReach(
            $user,
            $scope instanceof Company ? Refusal::AT_COMPANY : Refusal::AT_FRANCHISE,
            $act,
            // Safe to name: the caller sent this scope's id, having picked it out
            // of a tree the API already showed them.
            $scope->name,
        )->message();
    }

    /**
     * Authority over a scope, and never upward out of it (§4).
     *
     * The three cases are User's own, deliberately — deriving reach here would
     * be a second home for §4, and the settings screen's history records what
     * happens when one of those homes walks the tree the wrong way round.
     */
    private function reaches(User $user, Model $scope): bool
    {
        if ($scope instanceof Franchise) {
            return $user->canActOnFranchise($scope);
        }

        if ($scope instanceof Company) {
            return $user->canActOnCompany($scope);
        }

        // Organization scope is an assignment covering the organization itself,
        // not "reaches every company that happens to exist today" — that is a
        // set which changes when somebody adds a branch, and this is not.
        return $user->assignments->contains(
            fn ($a) => $a->assigned_organization_id === $user->organization_id
        );
    }
}
