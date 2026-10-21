<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SendingIdentity;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Email\RemoteHostGuard;
use App\Support\Mail\MailTransport;
use App\Support\Mail\SendingIdentityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mail server a business sends its OWN mail through.
 *
 * Authorised by Jose on 2026-09-14 — asked as "reports can no longer be emailed,
 * and the same is true of Automations' email action", answered "yes build it for
 * god sake". This is the way back in for both.
 *
 * ── Not the screen that was deleted ──────────────────────────────
 *
 * The sending-identity panel and its four routes went the same day on his "GET
 * RID OF IT". That screen asked a business to prove a DOMAIN so EzBizi's SES
 * would sign as them — his ruling retired the whole idea, and none of it is
 * back. This asks for a mail server instead: the business's own transport, our
 * SES not in the path at all, which is the opposite end of the only axis that
 * ruling is about.
 *
 * ── One endpoint, one field, one writer ──────────────────────────
 *
 * `credentials` is absent from $fillable, so no request body can reach it
 * through a generic update. {@see SendingIdentity::connectServer} is the only
 * method in the application that assigns to that column, and `connect` below is
 * the only thing that calls it. The same shape MailboxesController uses for the
 * same kind of secret, deliberately rather than a second dialect of it.
 *
 * The password is never read back. {@see SendingIdentity::mayReveal} answers
 * false for everybody, as Mailbox's does: a value that cannot be revealed cannot
 * be revealed to the wrong person, and nothing in the product needs it on a
 * screen. What `show` returns is enough to recognise the connection — host,
 * port, user name — and never enough to use it.
 *
 * ── The host is a trust boundary and this is not where it is checked ─
 *
 * Deliberately NOT validated against the private ranges here. The check belongs
 * where the socket is opened, because a host that passes validation at 9am and
 * resolves somewhere else at noon is the whole DNS-rebinding problem — see
 * {@see RemoteHostGuard}, which is the one guard for any host
 * a customer typed and which resolves once and returns the address it judged.
 * Checking here as well would be a second answer that can drift from the first,
 * which is exactly the mistake that produced TenantSmtpHost and got it deleted.
 */
class SendingServersController extends Controller
{
    private const PERMISSION = 'organizations.edit';

    /** Submission ports, matching RemoteHostGuard::SMTP_PORTS. 25 is deliberately absent. */
    private const PORTS = [587, 465];

    public function __construct(
        private readonly SendingIdentityResolver $resolver,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/mail/sending-server?scope_type=company&scope_id={uuid}
     *
     * What is connected AT this scope, and what this scope would send as after
     * the cascade. Two different questions, and a screen that cannot tell them
     * apart makes an administrator edit the wrong level — the settings screen
     * learned that the hard way and this returns both for the same reason.
     */
    public function show(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);

        /*
         * Read fresh, because the resolver's cache has NO invalidation for
         * writes made outside this controller — and there is now exactly such a
         * write: SendingIdentity::noteAttempt(), which the sending path will
         * call on every send. A resolver that has already answered rowAt() for
         * this scope keeps answering with the row as it was BEFORE the attempt,
         * so `never_tried` would go on saying true for a server that has been
         * tried — the sentence this column exists to stop being a lie,
         * reintroduced one layer up.
         *
         * A no-op on a normal web request, where the container is new and the
         * cache is empty. It earns its place wherever one resolver instance
         * serves reads across a write: a queue worker, and this file's tests.
         */
        $this->resolver->flush();

        $row = $this->resolver->rowAt($scope);
        $user = $request->user();

        /*
         * Wrapped in `data`, as every other controller here is and as
         * ApiResponse.data expects — it reads body['data'] and nothing else. An
         * unwrapped payload passes a server test asserting
         * assertJsonPath('connected_here.host', ...) and renders NOTHING on the
         * client, which is the two-halves-passing-separately failure the deploy
         * desk named. Caught by the widget test, not by mine.
         */
        return response()->json(['data' => [
            'connected_here' => $this->describe($row),
            'in_effect' => $this->describeEffective($scope),
            'may_edit' => $this->refusalFor($user, $scope, 'change the mail server') === null,
            'may_edit_reason' => $this->refusalFor($user, $scope, 'change the mail server'),
            'ports' => self::PORTS,
        ]]);
    }

    /**
     * PUT /api/v1/mail/sending-server — hand over the server and its password.
     *
     * Creates the identity at this scope if there is none. `from_address` is
     * required because a message has to come FROM something and the mail server
     * does not imply it: a business can send info@ mail through a provider whose
     * account is named something else entirely.
     */
    public function connect(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);

        $this->authorizeWrite($request, $scope, 'change the mail server');

        $validated = $request->validate([
            'from_address' => ['required', 'email:rfc'],
            'from_name' => ['required', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email:rfc'],

            // Trimmed of nothing and checked for nothing beyond shape: the guard
            // at the socket is what decides whether it may be dialled.
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', Rule::in(self::PORTS)],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:512'],
            'encryption' => ['required', Rule::in(['tls', 'ssl'])],
        ]);

        $row = $this->resolver->rowAt($scope) ?? new SendingIdentity([
            'organization_id' => $this->organizationIdOf($scope),
            'scope_type' => $this->scopeTypeOf($scope),
            'scope_id' => $scope->getKey(),
        ]);

        $row->fill([
            'from_address' => $validated['from_address'],
            'from_name' => $validated['from_name'],
            'reply_to' => $validated['reply_to'] ?? null,
            'transport' => MailTransport::Smtp->value,
        ]);

        $row->connectServer([
            'host' => $validated['host'],
            'port' => $validated['port'],
            'username' => $validated['username'],
            'password' => $validated['password'],
            'encryption' => $validated['encryption'],
        ]);

        $row->save();

        $this->resolver->flush();

        /*
         * The password is NOT in here and that is not left to the redactor. The
         * entry records THAT a server was connected and which one — host, port,
         * user name — because "who pointed our sending at that machine" is the
         * question an audit log exists to answer. The redactor stays as a second
         * line for the day somebody adds a field.
         */
        $this->audit->log('sending_server.connected', $scope, [
            'from_address' => $validated['from_address'],
            'host' => $validated['host'],
            'port' => $validated['port'],
            'username' => $validated['username'],
        ]);

        return response()->json(['data' => ['connected_here' => $this->describe($row->fresh())]]);
    }

    /**
     * DELETE /api/v1/mail/sending-server — stop sending through it.
     *
     * Deletes the row outright rather than blanking the credential. A row with
     * an address and no password is a state nothing in the product can act on:
     * it cannot send, and it is not "inherit from the parent" either, so it
     * would sit between the two answers the cascade knows how to give.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);

        $this->authorizeWrite($request, $scope, 'remove the mail server');

        $row = $this->resolver->rowAt($scope);

        abort_if($row === null, Response::HTTP_NOT_FOUND, 'Nothing is connected at this level.');

        app(\App\Support\Integrations\DisconnectCode::class)->require($request);

        $address = $row->from_address;

        $row->delete();

        $this->resolver->flush();

        $this->audit->log('sending_server.disconnected', $scope, ['from_address' => $address]);

        return response()->json(['data' => [
            'connected_here' => null,

            // What they fall back to, said at the moment of removal rather than
            // discovered later: removing a branch's server does not stop its
            // mail, it hands it to whatever the company above has set.
            'in_effect' => $this->describeEffective($scope),
        ]]);
    }

    // ── shapes ───────────────────────────────────────────────────

    /** @return array<string,mixed>|null — never the password, at any level */
    private function describe(?SendingIdentity $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $server = $row->credentials ?? [];

        return [
            'from_address' => $row->from_address,
            'from_name' => $row->from_name,
            'reply_to' => $row->reply_to,
            'host' => $server['host'] ?? null,
            'port' => $server['port'] ?? null,
            'username' => $server['username'] ?? null,
            'encryption' => $server['encryption'] ?? null,

            // Enough to say "a password is saved" and nothing about what it is.
            // The screen needs to distinguish "connected" from "half filled in";
            // it never needs the value.
            'has_password' => ($server['password'] ?? '') !== '',

            /*
             * DERIVED from last_attempted_at, not asserted. UI/UX's rule: a
             * state is available when the server can distinguish it. Without the
             * column, "nobody has tried" and "somebody tried and it failed" are
             * the same thing from here, and the screen was not entitled to say
             * either.
             */
            'never_tried' => $row->neverTried(),
        ];
    }

    /** @return array<string,mixed> what this scope sends as after the cascade */
    private function describeEffective(Model $scope): array
    {
        $identity = $this->resolver->for($scope);
        $source = $this->resolver->sourceOf($scope);

        return [
            'from_address' => $identity->fromAddress,
            'from_name' => $identity->fromName,
            'transport' => $identity->transport->value,
            'transport_label' => $identity->transport->label(),

            // Null when the platform answered — which is a different fact from
            // inheriting a value somebody in this organization chose, and the
            // screen must not round one into the other.
            'source_name' => $source?->name,
            'inherited' => $source !== null && $source->getKey() !== $scope->getKey(),
        ];
    }

    // ── scope, permission and reach ──────────────────────────────

    /**
     * Tenant-scoped, so a scope_id belonging to another organization is simply
     * not found — the caller cannot reach across the boundary by guessing an id.
     */
    private function resolveScope(Request $request): Model
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'in:organization,company,franchise'],
            'scope_id' => ['required', 'uuid'],
        ]);

        $model = match ($validated['scope_type']) {
            'organization' => $this->tenant->get()?->getKey() === $validated['scope_id']
                ? $this->tenant->get()
                : null,
            'company' => Company::find($validated['scope_id']),
            'franchise' => Franchise::find($validated['scope_id']),
        };

        abort_if($model === null, Response::HTTP_NOT_FOUND, 'Scope not found.');

        return $model;
    }

    private function scopeTypeOf(Model $scope): string
    {
        return match (true) {
            $scope instanceof Organization => SendingIdentity::SCOPE_ORGANIZATION,
            $scope instanceof Company => SendingIdentity::SCOPE_COMPANY,
            default => SendingIdentity::SCOPE_FRANCHISE,
        };
    }

    private function organizationIdOf(Model $scope): string
    {
        return $scope instanceof Organization ? $scope->getKey() : $scope->organization_id;
    }

    private function authorizeWrite(Request $request, Model $scope, string $act): void
    {
        $refusal = $this->refusalFor($request->user(), $scope, $act);

        abort_if($refusal !== null, Response::HTTP_FORBIDDEN, (string) $refusal);
    }

    /**
     * Why this person may not write here, or null when they may.
     *
     * Two checks, both required, exactly as on the settings screen: does the role
     * grant the permission (what), and does the person's assignment reach this
     * scope (where). Jose's rule in his words — "a franchise user should not be
     * able to modify parent preferences" — is the SECOND check, and it is the one
     * that stops a Connecticut manager repointing A-1 Chimney's mail server.
     *
     * Asked once and used twice: `may_edit_reason` on the screen, and every
     * write. Computed separately they drift, and the client draws a live control
     * for a scope the server then refuses.
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
            $scope->name,
        )->message();
    }

    private function reaches(User $user, Model $scope): bool
    {
        if ($scope instanceof Franchise) {
            return $user->canActOnFranchise($scope);
        }

        if ($scope instanceof Company) {
            return $user->canActOnCompany($scope);
        }

        /*
         * The model's own method, NOT the hand-rolled version the deleted
         * controller used. That one read
         * `$a->assigned_organization_id === $user->organization_id`, which is
         * weaker in two ways worth naming: it never checked `is_active`, so a
         * deactivated administrator still reached; and a COMPANY-scoped
         * assignment carries an organization_id too, so it matched assignments
         * that do not cover the organization at all.
         *
         * canActOnOrganization checks is_active, the organization, AND
         * `scopeType() === SCOPE_ORGANIZATION`. Copying the old controller's
         * plumbing was right; copying its private re-derivation of a method that
         * already existed would have been the same mistake as TenantSmtpHost.
         */
        return $user->canActOnOrganization($scope);
    }
}
