<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Mailbox;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Email\CannotReachHost;
use App\Support\Email\MailboxAccess;
use App\Support\Email\MailboxProvider;
use App\Support\Email\MailboxReaders;
use App\Support\Email\ReadState;
use App\Support\Email\RemoteHostGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connecting shared inboxes, and deciding which roles may open them.
 *
 * ── This is the MANAGEMENT surface, not the reading one ──────────
 *
 * Everything here is `email.create`/`email.edit`/`email.delete` plus §4 reach.
 * It does NOT require a grant for the mailbox being managed, and
 * {@see MailboxAccess} sets out why at length: somebody has to be able to
 * connect info@ before anybody can be given it, and requiring a grant to do so
 * would be a dead end on the first day. Reading is
 * {@see MailboxMessagesController}, which asks for all three.
 *
 * ── The credential goes in and never comes out ───────────────────
 *
 * `credentials` is absent from Mailbox's `$fillable`, so no request body can
 * reach it however a future edit shapes the input; the only writer is
 * `Mailbox::connect()`, called from {@see connect} below. On the way back it is
 * masked twice over — the model's MasksSensitiveValues covers every
 * serialisation path including an eager-loaded relation, and {@see row} is an
 * explicit allowlist of fields that never names it. Two independent things would
 * both have to be wrong on the same day.
 *
 ── Who may open which inbox is decided ELSEWHERE ───────────────
 *
 * The nested menu the owner described —
 *
 *     Email
 *       * Email 1
 *       * Email 2
 *
 * — is drawn by the roles editor, and both halves of it are written there:
 * `email.*` comes from the module registry through the roles catalogue, and
 * this organization's own inboxes come from {@see \App\Models\MailboxGrant}
 * through App\Support\Access\InboxAccess. That separation is the whole point
 * of MailboxGrant — the static half is platform data every tenant shares, the
 * per-inbox half is this organization's own — and composing them on one screen
 * is what makes a single menu out of two different kinds of thing.
 *
 * This controller does not write grants. It used to, and the two endpoints were
 * removed; see the note in place of them for why one table with two writers is
 * the failure rather than the tidiness problem.
 */
class MailboxesController extends Controller
{
    public function __construct(
        private readonly MailboxAccess $access,
        private readonly MailboxReaders $readers,
        private readonly ReadState $readState,
        private readonly RemoteHostGuard $guard,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/email/mailboxes — every inbox this person may see, and their state.
     *
     * Two lists in one response rather than two endpoints, because the screen
     * shows one table: an administrator who may manage an inbox but has not been
     * granted it still needs to see it, so the row carries `may_read` and
     * `may_manage` per mailbox instead of the caller having to infer either.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
         * THE TAB IS THE GATE, and this endpoint was not asking for it.
         *
         * Somebody holding only `jobs.view` got a 200 and an empty list. Two
         * things wrong with that, and the second is the one this module argues
         * about everywhere else: it contradicts the rule that a module is
         * reached through the tab permission the organization granted; and an
         * empty list is indistinguishable from "no inboxes are connected", so
         * the person cannot tell a refusal from an empty business.
         *
         * The refusal is the same sentence every other module gives, so being
         * told you cannot open Email reads like being told you cannot open
         * Customers (R8).
         *
         * The per-inbox grant is a SEPARATE question asked further down. This is
         * only "may you open the module at all" — a role can hold the tab and
         * still be granted no inbox, and then an empty list is the truth.
         */
        abort_unless(
            $user->hasPermission('email.view'),
            Response::HTTP_FORBIDDEN,
            Refusal::permission('open Email')->message(),
        );

        $manageable = $this->access->manageable($user);
        $readable   = $this->access->readable($user);

        /*
         * The union, keyed by id. Somebody may READ an inbox they cannot manage
         * (the ordinary case for anybody who is not an administrator) and manage
         * one they cannot read (an administrator who has not granted their own
         * role). Listing only one of the two sets makes half the module
         * invisible to one of those two people.
         */
        $mailboxes = $manageable->concat($readable)->unique(fn (Mailbox $m) => $m->getKey());

        return response()->json([
            'data' => $mailboxes
                ->sortBy('address')
                ->values()
                ->map(fn (Mailbox $m) => $this->row($m, $user))
                ->all(),

            'meta' => [
                /*
                 * ── `usable` is published, not inferred ──────────────
                 *
                 * The connect form used to decide whether to ask for a password
                 * by reading `needs_host`, on the reasoning that a provider
                 * needing a server address must want credentials typed in. That
                 * guess broke in the direction that costs most.
                 *
                 * `imap` was removed from PHP core in 8.4 and this box runs
                 * 8.4.24, so ImapMailboxReader cannot open a socket here at all.
                 * But IMAP has `needs_host: true`, so it was given a live
                 * password form — asking somebody for the password to their real
                 * mailbox, and storing it, for a connection that could never be
                 * attempted. Gmail is equally unbuilt on this installation and
                 * was correctly marked so, purely because it happens not to need
                 * a host. The screen was right by accident about one and wrong
                 * about the other.
                 *
                 * So the reader answers for itself. `notReadyBecause()` is the
                 * installation's own sentence, and a client shows the provider
                 * as unavailable rather than deducing it from a property that
                 * means something else.
                 */
                'providers' => array_map(fn (MailboxProvider $p) => [
                    'value'    => $p->value,
                    'label'    => $p->label(),
                    'needs_host' => $p->needsHost(),
                    'usable'   => $this->readers->forProvider($p)->notReadyBecause() === null,
                    'unusable_because' => $this->readers->forProvider($p)->notReadyBecause(),
                    // The IMAP warning, published by the server so every client
                    // shows the same words. See MailboxProvider::caution.
                    'caution'  => $p->caution(),
                ], MailboxProvider::all()),

                'imap_ports' => RemoteHostGuard::IMAP_PORTS,
                'smtp_ports' => RemoteHostGuard::SMTP_PORTS,
            ],
        ]);
    }

    /**
     * POST /api/v1/email/mailboxes — add an address.
     *
     * It arrives DISCONNECTED. Adding the address and handing over the key are
     * two acts, and separating them is what lets the second one be a different
     * shape per provider — a Google mailbox is finished by somebody signing in,
     * an IMAP one by typing a password.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if(
            ! $user->hasPermission('email.create'),
            Response::HTTP_FORBIDDEN,
            Refusal::permission('add a shared inbox')->message(),
        );

        $input = $request->validate([
            'scope_type'   => ['required', 'in:organization,company,franchise'],
            'scope_id'     => ['required', 'uuid'],
            'address'      => ['required', 'email:rfc', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'provider'     => ['required', 'in:'.implode(',', array_map(
                fn (MailboxProvider $p) => $p->value,
                MailboxProvider::all(),
            ))],
            'mail_server'  => ['sometimes', 'array'],
        ]);

        $scope = $this->resolveScope($input['scope_type'], $input['scope_id']);

        $this->assertReaches($user, $scope, $input['scope_type'], 'add a shared inbox here');

        $provider = MailboxProvider::from($input['provider']);
        $server   = $this->mailServerFor($provider, $input['mail_server'] ?? []);

        $mailbox = DB::transaction(function () use ($input, $scope, $provider, $server) {
            $mailbox = Mailbox::create([
                'organization_id' => $this->organizationIdOf($scope),
                'scope_type'      => $input['scope_type'],
                'scope_id'        => $scope->getKey(),
                'address'         => strtolower($input['address']),
                'display_name'    => $input['display_name'],
                'provider'        => $provider->value,
                'mail_server'     => $server,
            ]);

            $this->audit->log('mailbox.added', $mailbox, [
                'address'  => $mailbox->address,
                'provider' => $mailbox->provider,
                'scope'    => $input['scope_type'],
            ]);

            return $mailbox;
        });

        return response()->json(['data' => $this->row($mailbox, $request->user())], Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/email/mailboxes/{mailbox} — rename it, or correct the server details.
     *
     * The ADDRESS is not editable. Changing it would silently repoint every
     * grant and every read mark at a different mailbox: four people who were
     * given info@ would find themselves reading payroll@ with nothing on screen
     * having changed. Disconnecting and adding the other address is two clicks
     * and leaves an audit trail that says what happened.
     */
    public function update(Request $request, Mailbox $mailbox): JsonResponse
    {
        $this->assertMayManage($request->user(), $mailbox, 'change this inbox');

        $input = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:255'],
            'mail_server'  => ['sometimes', 'array'],
        ]);

        if (array_key_exists('mail_server', $input)) {
            $input['mail_server'] = $this->mailServerFor(
                $mailbox->providerEnum() ?? MailboxProvider::Imap,
                $input['mail_server'],
            );

            /*
             * THE SAME GUARD `connect()` RUNS, because this writes the same
             * column and nothing else was checking it.
             *
             * It was missing, and the hole was reachable in two requests:
             * connect to a real host so the guard passes and the credential is
             * stored, then PATCH the host to 169.254.169.254 — accepted, stored,
             * and still reading "connected".
             *
             * `ImapMailboxReader::assertUsable` re-guards before it dials, so
             * nothing follows that address today. That is containment, not a
             * reason to leave it: it is exactly the state `connect()`'s own
             * docblock says must never exist — a credential attached to a host
             * we would refuse to dial — and the next thing to trust the stored
             * value, a health check or a send path, is a live SSRF with nothing
             * between it and the metadata endpoint.
             *
             * FILLED FIRST AND JUDGED THROUGH THE MODEL, which is the point.
             * `fill()` does not persist, and asking `host()`/`smtpHost()` means
             * the value the guard judges is parsed by the same code that will
             * later dial it. A guard reading the raw array would be a second
             * parser, and a second parser is a second answer waiting to differ
             * from the first.
             */
            $mailbox->fill($input);

            try {
                if (($host = $mailbox->host()) !== null) {
                    $this->guard->approve($host['host'], $host['port'], RemoteHostGuard::IMAP_PORTS);
                }

                if (($smtp = $mailbox->smtpHost()) !== null) {
                    $this->guard->approve($smtp['host'], $smtp['port'], RemoteHostGuard::SMTP_PORTS);
                }
            } catch (CannotReachHost $e) {
                return response()->json(['message' => $e->getMessage()], $e->status());
            }
        }

        DB::transaction(function () use ($mailbox, $input) {
            $mailbox->fill($input);

            $this->audit->logChange('mailbox.updated', $mailbox);

            $mailbox->save();
        });

        return response()->json(['data' => $this->row($mailbox->fresh(), $request->user())]);
    }

    /**
     * POST /api/v1/email/mailboxes/{mailbox}/connect — hand over the key.
     *
     * ── Why the secret is accepted here and nowhere else ─────────
     *
     * One endpoint, one field, one writer. `credentials` is not fillable, so
     * `update` above cannot write it whatever it is sent; this route names it
     * explicitly and passes it to `Mailbox::connect()`, which is the only method
     * in the application that assigns to that column.
     *
     * The value is NOT logged, and that is not left to the redactor: the audit
     * entry below records that a credential was set and never what it was. The
     * redactor exists as a second line for the day somebody adds a field.
     *
     * ── What this endpoint is NOT ────────────────────────────────
     *
     * It is not an OAuth callback. A Google mailbox is finished by somebody
     * consenting in a browser and the resulting refresh token arriving here, and
     * the browser flow that produces it is not built — see GmailMailboxReader
     * for exactly what is missing and why it is an account somebody has to
     * create rather than code. Accepting a token directly is what lets the
     * mailbox be connected the day that flow exists, without this endpoint
     * changing.
     */
    public function connect(Request $request, Mailbox $mailbox): JsonResponse
    {
        $this->assertMayManage($request->user(), $mailbox, 'connect this inbox');

        $input = $request->validate([
            'credential' => ['required', 'string', 'max:4096'],
        ]);

        /*
         * The host is judged BEFORE the credential is stored.
         *
         * Order matters: storing first and checking second would leave a
         * password for somebody's real mailbox sitting in our database attached
         * to a host we then refused to dial — a secret held for no reason, which
         * is the one kind of secret worth nothing and costing everything.
         *
         * ── Caught here, and answered as a 422 ──────────────────
         *
         * CannotReachHost is a RuntimeException, so letting it escape would
         * produce a 500: the sentence the guard wrote — the one telling an
         * administrator their mail server name points somewhere it should not —
         * would be swallowed and replaced with "Server Error". A refused host is
         * a fact about the VALUE they typed, which is a 422 naming it, and never
         * a 403: nobody is being denied anything.
         */
        try {
            if (($host = $mailbox->host()) !== null) {
                $this->guard->approve($host['host'], $host['port'], RemoteHostGuard::IMAP_PORTS);
            }

            if (($smtp = $mailbox->smtpHost()) !== null) {
                $this->guard->approve($smtp['host'], $smtp['port'], RemoteHostGuard::SMTP_PORTS);
            }
        } catch (CannotReachHost $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        DB::transaction(function () use ($mailbox, $input) {
            $mailbox->connect($input['credential']);
            $mailbox->save();

            // What happened, never what was handed over.
            $this->audit->log('mailbox.connected', $mailbox, [
                'address'  => $mailbox->address,
                'provider' => $mailbox->provider,
            ]);
        });

        return response()->json(['data' => $this->row($mailbox->fresh(), $request->user())]);
    }

    /**
     * DELETE /api/v1/email/mailboxes/{mailbox} — disconnect it.
     *
     * The row goes, and the foreign keys take the grants and the read marks with
     * it. Nothing about the mail itself is affected: the address goes on
     * receiving, and EzBizi simply stops being able to look at it. The response
     * says so, because "delete mailbox" reads to a nervous administrator like it
     * might delete their email.
     */
    public function destroy(Request $request, Mailbox $mailbox): JsonResponse
    {
        $this->assertMayManage($request->user(), $mailbox, 'disconnect this inbox');

        $address = $mailbox->address;

        DB::transaction(function () use ($mailbox, $address) {
            $this->audit->log('mailbox.disconnected', $mailbox, ['address' => $address]);

            $mailbox->delete();
        });

        return response()->json([
            'message' => sprintf(
                '%s is no longer connected to EzBizi. Nothing has happened to the mail itself '
                .'— the address still receives everything it did, and it can be read wherever '
                .'it was read before. Access that colleagues had through EzBizi has gone with '
                .'it, and would need giving again if you reconnect.',
                $address,
            ),
        ]);
    }

    /*
     * ── PER-INBOX ACCESS IS WRITTEN BY THE ROLES EDITOR ──────────
     *
     * There were two endpoints here — `GET /mailboxes/grants` and
     * `PUT /mailboxes/grants/{role}` — and they were removed rather than kept,
     * because RolesController already writes {@see MailboxGrant} through
     * App\Support\Access\InboxAccess: a role is saved with its permissions and
     * its inboxes in one request, which is what the screen actually does.
     *
     * TWO WRITE PATHS TO ONE TABLE IS THE PROBLEM, not the duplication. The two
     * did not agree: that one refuses an inbox granted to a role that cannot
     * open the Email tab, and it preserves grants the caller cannot see rather
     * than silently revoking them when a smaller role saves an empty list. Mine
     * did neither. Whichever is right, having both means the rule depends on
     * which endpoint a client happens to call — and the one that gets it wrong
     * is always the second one somebody wrote.
     *
     * What is left here is the READ side of the same fact, per mailbox rather
     * than per role: {@see row} carries `may_read` and `may_read_reason`, which
     * is what the mailbox screen needs. The question "which roles hold this
     * inbox" belongs to the roles editor and is answered there.
     */


    // ── the response ─────────────────────────────────────────────

    /**
     * One mailbox, as an explicit allowlist of fields.
     *
     * Never `$mailbox->toArray()`. The model masks `credentials` anyway, and
     * that is the second lock rather than the first: an allowlist also keeps
     * `mail_server` from publishing whatever a future edit puts in it, and it is
     * what a test can assert an exact key list against.
     */
    private function row(Mailbox $mailbox, User $user): array
    {
        $reader     = $this->readers->for($mailbox);
        $readRefusal = $this->access->readRefusal($user, $mailbox, 'open this inbox');

        return [
            'id'           => $mailbox->getKey(),
            'address'      => $mailbox->address,
            'display_name' => $mailbox->display_name,
            'provider'     => $mailbox->provider,
            'provider_label' => $mailbox->providerEnum()?->label(),

            /*
             * The scope as a LEVEL and an ID, and deliberately without a name.
             *
             * A status screen does need to say which part of the business an
             * inbox belongs to — two companies in this organization both have an
             * info@, which is the case that decides it. The name is not sent
             * because the client already holds it: every mailbox in this list is
             * at a level the caller reaches (the list is the union of readable
             * and manageable, both filtered by reach), so `/me` already names it,
             * and a second copy here would be a copy that can go stale.
             *
             * It would also be the WRONG name. §8 lets an organization rename the
             * franchise level — "Branch", "Territory" — and lets four companies
             * each run a "Connecticut", so the label a person should read is a
             * path built from their own vocabulary rather than a string this
             * controller could compose.
             */
            'scope_type'   => $mailbox->scope_type,
            'scope_id'     => $mailbox->scope_id,

            /*
             * ── THE THREE FIELDS THE STATUS SCREEN IS FOR ────────
             *
             * `status` is the state, `status_reason` is the sentence that names
             * the cause and says what to do, and `last_reached_at` is the one
             * that makes the other two worth reading: "broken" means something
             * different when it last worked four minutes ago than when it last
             * worked in March, and until this column existed that difference
             * could not be shown at all.
             *
             * The sentence is written by App\Support\Email\RecordingMailboxReader
             * from App\Support\Email\MailboxFault — plain words, never a driver
             * message or an exception class (R8). Published verbatim: this
             * controller does not reword it, and no client should either.
             *
             * ── Null vs absent, which a client must not confuse ──
             *
             * The key is ALWAYS present. `null` means "EzBizi has never
             * successfully opened this", which is a real, sayable state; a
             * missing key would mean "this server is older than your screen",
             * which is a different thing entirely and must not be drawn as
             * "never worked" on an inbox somebody is reading mail in.
             */
            'status'        => $mailbox->status,
            'status_reason' => $mailbox->status_reason,
            'last_reached_at' => $mailbox->last_reached_at?->toIso8601String(),

            /*
             * Whether this can actually be opened, asked of the READER.
             *
             * Not derived from `status`, which is what the last attempt found.
             * The reader knows a third thing the row cannot: whether this
             * installation has anything behind the provider at all. A mailbox
             * with a perfectly good credential on an installation with no Google
             * registration is unusable, and saying "connected" would send
             * somebody hunting for a fault at their end.
             */
            'can_be_opened' => $reader->isConfigured($mailbox),
            'unavailable_reason' => $reader->unavailableBecause($mailbox),

            // The host, never the password. `mail_server` holds only these.
            'mail_server' => $mailbox->mail_server,

            'may_read'        => $readRefusal === null,
            'may_read_reason' => $readRefusal,
            'may_manage'      => $this->access->mayManage($user, $mailbox),

            'read_state_shared' => $this->readState->isShared($mailbox),
            'read_state_note'   => $this->readState->explain($mailbox),
        ];
    }

    // ── scope, permission and reach ──────────────────────────────

    private function resolveScope(string $type, string $id): Model
    {
        $model = match ($type) {
            'organization' => $this->tenant->get()?->getKey() === $id ? $this->tenant->get() : null,
            'company'      => Company::find($id),
            'franchise'    => Franchise::find($id),
        };

        // Tenant-scoped lookups, so an id from another organization is simply
        // absent — the caller cannot discover what exists elsewhere by guessing.
        abort_if($model === null, Response::HTTP_NOT_FOUND, 'That part of the business was not found.');

        return $model;
    }

    private function organizationIdOf(Model $scope): string
    {
        return $scope instanceof \App\Models\Organization ? $scope->getKey() : $scope->organization_id;
    }

    /**
     * Reach over the level a NEW mailbox is being hung off.
     *
     * Separate from MailboxAccess::reaches, which asks about an existing record.
     * Same three questions of the same User methods; the difference is only that
     * there is no row yet to ask about.
     */
    private function assertReaches(User $user, Model $scope, string $type, string $act): void
    {
        $reaches = match ($type) {
            'organization' => $user->canActOnOrganization($user->organization),
            'company'      => $user->canActOnCompany($scope),
            'franchise'    => $user->canActOnFranchise($scope),
        };

        if ($reaches) {
            return;
        }

        abort(Response::HTTP_FORBIDDEN, $type === 'organization'
            ? Refusal::abovePosition($act)->message()
            : Refusal::noReach(
                $user,
                $type === 'company' ? Refusal::AT_COMPANY : Refusal::AT_FRANCHISE,
                $act,
                (string) $scope->name,
            )->message());
    }

    private function assertMayManage(User $user, Mailbox $mailbox, string $act): void
    {
        $refusal = $this->access->manageRefusal($user, $mailbox, $act);

        abort_if($refusal !== null, Response::HTTP_FORBIDDEN, $refusal);
    }

    /**
     * The mail-server block, narrowed to the keys this application understands.
     *
     * An allowlist rather than storing whatever arrives, for two reasons. The
     * host and port are the SSRF surface and they are read back out by
     * `Mailbox::host()` — an unexpected key sitting beside them is one a future
     * edit might start reading. And a `mail_server` a client can fill freely is
     * a place to smuggle a credential past the masking, since only `credentials`
     * is masked.
     *
     * Nulled entirely for a provider with no host, so a Gmail mailbox cannot
     * carry an IMAP server nobody would ever dial.
     *
     * @param  array<string, mixed>  $input
     */
    private function mailServerFor(MailboxProvider $provider, array $input): ?array
    {
        if (! $provider->needsHost()) {
            return null;
        }

        $out = [];

        foreach (['imap_host', 'smtp_host', 'imap_username', 'smtp_username'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $out[$key] = trim($input[$key]);
            }
        }

        foreach (['imap_port', 'smtp_port'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $out[$key] = (int) $input[$key];
            }
        }

        foreach (['imap_encryption', 'smtp_encryption'] as $key) {
            // Two spellings, and nothing else. `none` is deliberately absent:
            // an unencrypted mail connection sends the password across the
            // network in the clear, and offering it as a choice is offering
            // somebody the option of being wrong.
            if (isset($input[$key]) && in_array($input[$key], ['tls', 'starttls'], true)) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }
}
