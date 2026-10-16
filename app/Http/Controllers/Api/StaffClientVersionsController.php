<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformMinimumClientVersion;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The control-plane screen for "which client builds may still talk to us".
 *
 * ── Why this is an endpoint and not a console command ────────────
 *
 * The house rule is that the app configures itself. A minimum client version is
 * raised in exactly the circumstances where a console command is worst: a
 * breaking change has gone out, customers are already hitting it, and the person
 * who has to act may be on a phone. `php artisan` needs a shell on the
 * production box, which is one person on one machine, and it leaves no audit
 * row unless somebody remembers to write one.
 *
 * ── Why it is staff-only and can never be a tenant setting ───────
 *
 * Sits inside the existing v1/staff group behind auth:sanctum + the `staff`
 * middleware, which is the gate that actually distinguishes a PlatformStaff
 * token from a customer's (see StaffOnly — Sanctum alone does not).
 *
 * An organization must never be able to set this for itself. It is not a
 * preference about how they like their software; it is a statement about which
 * builds this API can still serve correctly, and the API is one installation
 * shared by every customer. A tenant who could lower their own minimum would be
 * choosing to keep a client alive that the server has stopped being able to talk
 * to, and the resulting failures would arrive as bug reports about the server.
 */
class StaffClientVersionsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/staff/client-versions
     *
     * Every platform, always — including the ones nobody has configured.
     *
     * A list that showed only the configured rows would answer "which minimums
     * exist" when the question being asked is "what is the state of each
     * platform", and the unconfigured ones are the interesting half: they are
     * the platforms where a stale client is currently sailing through. The
     * screen needs to show six rows with four of them saying "no minimum set".
     */
    public function index(): JsonResponse
    {
        $rows = PlatformMinimumClientVersion::all()->keyBy('platform');

        $data = collect(PlatformMinimumClientVersion::PLATFORMS)
            ->map(function (string $platform) use ($rows) {
                $row = $rows->get($platform);

                return [
                    'platform' => $platform,
                    'minimum_version' => $row?->minimum_version,

                    // No row, or a row with no minimum, both mean the same thing
                    // to the gate — nobody is refused — so they must read the
                    // same on the screen. Surfacing them differently would
                    // invite somebody to conclude that one of them blocks.
                    'is_enforced' => $row?->minimum_version !== null,

                    'download_url' => $row?->download_url,
                    'note' => $row?->note,
                    'changed_by' => $row?->changed_by,
                    'changed_at' => $row?->updated_at?->toIso8601String(),

                    // Web has no installer, so the screen should not offer a
                    // field for one. Stated by the server rather than hardcoded
                    // in the client, so the two cannot come to disagree.
                    'takes_download_url' => $platform !== PlatformMinimumClientVersion::WEB,
                ];
            })
            ->values();

        return response()->json(['data' => $data]);
    }

    /**
     * PUT /api/v1/staff/client-versions/{platform}
     *
     * PUT rather than PATCH, deliberately. This replaces the whole of one
     * platform's configuration: an omitted download_url or note is CLEARED, not
     * left alone. The alternative — PATCH semantics where absent means unchanged
     * — makes "remove the download link" impossible to express, and makes the
     * result of a save depend on which fields the client happened to include.
     * A form that submits all three fields every time is the simple thing to
     * build, and this is the verb that matches it.
     *
     * Sending minimum_version: null is the UNDO. It lifts the gate for that
     * platform in one request. That path exists because raising a minimum is the
     * kind of change whose blast radius is only visible afterwards, and the
     * recovery must not require a deploy.
     */
    public function update(Request $request, string $platform): JsonResponse
    {
        $platform = strtolower(trim($platform));

        /*
         * `present` on minimum_version, `nullable` on the rest.
         *
         * The one field that decides whether anybody is refused has to be stated
         * out loud on every save. Under PUT an omitted field is a cleared field,
         * and "I forgot to include it" and "I meant to lift the gate" would
         * otherwise be the same request — with a silent outage-shaped difference
         * between them. Sending it explicitly as null is still how you lift it;
         * this only refuses to guess.
         */
        $input = $request->validate([
            'minimum_version' => ['present', 'nullable', 'string', 'max:64'],
            'download_url' => ['nullable', 'string', 'max:2048', 'url'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Validated by hand rather than through the route parameter, so the
        // message can name the six and the caller does not have to guess at a
        // bare 404.
        if (! in_array($platform, PlatformMinimumClientVersion::PLATFORMS, strict: true)) {
            throw ValidationException::withMessages([
                'platform' => "'{$platform}' is not a platform EzBizi ships a client for. "
                    .'The platforms are: '.implode(', ', PlatformMinimumClientVersion::PLATFORMS).'.',
            ]);
        }

        $minimumVersion = $this->normalisedVersion($input['minimum_version'] ?? null);
        $downloadUrl = $this->normalisedDownloadUrl($platform, $input['download_url'] ?? null);

        $staff = $request->user();
        $label = "{$staff->name} <{$staff->email}>";

        $row = PlatformMinimumClientVersion::query()->where('platform', $platform)->first();

        $before = [
            'minimum_version' => $row?->minimum_version,
            'download_url' => $row?->download_url,
            'note' => $row?->note,
        ];

        $row = PlatformMinimumClientVersion::updateOrCreate(
            ['platform' => $platform],
            [
                'minimum_version' => $minimumVersion,
                'download_url' => $downloadUrl,
                'note' => $this->blankToNull($input['note'] ?? null),
                'changed_by' => $label,
            ],
        );

        /*
         * Always recorded, including for the Lead Developer whose ordinary
         * business actions are exempt.
         *
         * This is a sibling of staff.module_state_changed: one of the very few
         * actions that changes what EVERY customer experiences at the same
         * moment, and the only one that can make a customer's software refuse to
         * start. `changed_by` on the row is overwritten by the next save, so
         * without this row an incident is unreconstructable — nothing would say
         * what the minimum used to be, when it was raised, or who raised it,
         * which are the first three questions asked afterwards.
         */
        $this->audit->logStaff(
            action: 'staff.minimum_client_version_changed',
            staffId: $staff->getKey(),
            staffLabel: $label,
            target: $row,
            metadata: [
                'platform' => $platform,
                'before' => $before,
                'after' => [
                    'minimum_version' => $row->minimum_version,
                    'download_url' => $row->download_url,
                    'note' => $row->note,
                ],
            ],
        );

        return response()->json(['data' => [
            'platform' => $row->platform,
            'minimum_version' => $row->minimum_version,
            'is_enforced' => $row->minimum_version !== null,
            'download_url' => $row->download_url,
            'note' => $row->note,
            'changed_by' => $row->changed_by,
            'changed_at' => $row->updated_at?->toIso8601String(),
        ]]);
    }

    /**
     * A minimum this application can actually compare, or nothing at all.
     *
     * Refused here rather than stored and shrugged at later. The gate treats an
     * unreadable minimum as "no minimum" and lets everybody through, which is
     * the right behaviour for the request in flight and the wrong thing to
     * discover a fortnight after somebody typed "v0.0.30" into this field and
     * went home believing the gate was up. The one moment a typo can be caught
     * by the person who made it is now.
     */
    private function normalisedVersion(?string $version): ?string
    {
        $version = $this->blankToNull($version);

        if ($version === null) {
            return null;
        }

        if (PlatformMinimumClientVersion::parse($version) === null) {
            throw ValidationException::withMessages([
                'minimum_version' => "'{$version}' is not a version this can compare. Versions are "
                    .'numbers separated by dots, the way client/VERSION writes them — for example '
                    .'0.0.0030. Leave it empty to lift the minimum for this platform.',
            ]);
        }

        return $version;
    }

    /**
     * The download link, refused unless it is https — and never stored for web.
     *
     * https because this address is handed to customers as the place to fetch an
     * executable. An installer pulled over plain http can be replaced in flight
     * by anything sitting between the customer and us, and the refusal message
     * that carries the link is shown at the exact moment somebody is anxious and
     * inclined to click.
     *
     * Web is cleared rather than refused: a browser client has nothing to
     * download, and the refusal it shows tells the person to reload. Silently
     * accepting a link that would never be shown would leave a value on the
     * screen that does nothing, which is worse than not having the field.
     */
    private function normalisedDownloadUrl(string $platform, ?string $url): ?string
    {
        $url = $this->blankToNull($url);

        if ($url === null || $platform === PlatformMinimumClientVersion::WEB) {
            return null;
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw ValidationException::withMessages([
                'download_url' => 'The download link must start with https://. This address is '
                    .'shown to customers as the place to fetch an installer, and anything fetched '
                    .'over plain http can be swapped for something else on the way.',
            ]);
        }

        return $url;
    }

    /**
     * An empty box and an absent field mean the same thing.
     *
     * A form that submits "" for a cleared input would otherwise store an empty
     * string, which is not null, which the gate would then hand to the parser —
     * and the difference between "no minimum" and "a minimum nobody can read"
     * would exist in the database while being invisible on the screen.
     */
    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
