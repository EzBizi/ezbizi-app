<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Social\MetaApp;
use App\Support\Social\MetaSignedRequest;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * The two callbacks Meta requires an app to answer.
 *
 * ── Deauthorize ─────────────────────────────────────────────────
 *
 * Somebody removed EzBizi from their Facebook settings. Their tokens are dead
 * from that moment, and without this the connection sits in the catalogue
 * saying "connected" until the first publish fails — which, once publishing
 * exists, means a post that silently never goes out. Marking it revoked is what
 * lets the UI say the true thing.
 *
 * ── Data deletion ───────────────────────────────────────────────
 *
 * Meta requires either this or a public instructions page, and calls this one
 * to check it works. The answer must be JSON carrying a URL a person can visit
 * to see the status, and a confirmation code. Deleting the connection deletes
 * the token and everything Meta gave us — EzBizi keeps no copy of a remote
 * feed, so there is nothing else of theirs to erase. What remains is the audit
 * log, which records that a connection existed and expires on its own schedule.
 *
 * ── Why these are not tenant-scoped ─────────────────────────────
 *
 * Every other route in this application runs inside one organization. These
 * cannot: Meta does not know what an organization is, and the person removing
 * the app may have authorised Pages for several. So the lookup is by
 * `meta_user_id` across all of them, deliberately outside the tenant guard, and
 * the signature is what makes that safe.
 *
 * ── Why no auth middleware ──────────────────────────────────────
 *
 * Meta calls these, so there is no session and no token to check.
 * [MetaSignedRequest] IS the authentication: an HMAC over the payload keyed
 * with the app secret. A request that does not verify is answered 400 and
 * nothing is touched.
 */
class MetaCallbacksController extends Controller
{
    /** Meta removed us. Mark what that person authorised as revoked. */
    public function deauthorize(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        if ($userId === null) {
            return $this->refused();
        }

        $revoked = 0;

        foreach ($this->connectionsFor($userId) as $connection) {
            $connection->forceFill([
                'status'        => SocialConnection::STATUS_REVOKED,
                'status_reason' => 'The account was disconnected from Meta\'s own settings.',
            ])->save();

            $revoked++;
        }

        // 200 whatever the count. Meta is being told "we heard you", not asked
        // to care how many rows we hold — and a non-200 makes Meta retry, then
        // flag the app for a callback that does not work.
        return response()->json(['revoked' => $revoked]);
    }

    /** Meta wants that person's data gone. Delete the connections. */
    public function dataDeletion(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        if ($userId === null) {
            return $this->refused();
        }

        // Short, and not derived from the user id: this is quoted back to Meta
        // and shown to a person, so it must not be something that identifies
        // them to anybody who sees it.
        $code = strtoupper(Str::random(12));

        DB::transaction(function () use ($userId) {
            foreach ($this->connectionsFor($userId) as $connection) {
                $connection->delete();
            }
        });

        return response()->json([
            'url'               => 'https://www.ezbizi.com/delete-account/',
            'confirmation_code' => $code,
        ]);
    }

    /**
     * Meta connections authorised by this person.
     *
     * @return \Illuminate\Support\Collection<int, SocialConnection>
     */
    private function connectionsFor(string $userId)
    {
        $meta = [
            SocialProvider::FacebookPage->value,
            SocialProvider::InstagramProfessional->value,
            SocialProvider::Threads->value,
        ];

        return SocialConnection::query()
            ->withoutGlobalScopes()
            ->whereIn('provider', $meta)
            ->where('meta_user_id', $userId)
            ->get();
    }

    private function userId(Request $request): ?string
    {
        // Threads is a separate app with its own secret, so a signature could be
        // keyed with either. Try both rather than assume which product the
        // person was removing.
        foreach ([MetaApp::secret(), MetaApp::threadsSecret()] as $secret) {
            if ($secret === '') {
                continue;
            }

            $id = MetaSignedRequest::userId($request->input('signed_request'), $secret);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function refused(): JsonResponse
    {
        return response()->json(
            ['error' => 'That request did not carry a valid signed_request.'],
            Status::HTTP_BAD_REQUEST,
        );
    }
}
