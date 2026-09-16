<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\WhatsAppNumber;
use App\Support\Social\WhatsAppCloud;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * The approved WhatsApp templates an automation may send.
 *
 * ── Why this is gated on Automation and not on a WhatsApp permission ──
 *
 * There is no WhatsApp permission, and inventing one for a read-only list
 * would be a permission nobody grants - `ModuleAvailability` runs before the
 * role, so a new grant that no role holds locks the picker for everyone
 * including the person who added it.
 *
 * The only caller is the automation builder, and the gate that matters is the
 * one the builder already applies: may this person author a rule. The list
 * itself is EzBizi's own approved templates on EzBizi's own number - the same
 * few strings for every tenant - so it carries nothing one organization should
 * be kept from seeing.
 *
 * ── The cache is about Meta, not about speed ─────────────────────
 *
 * Opening the action picker would otherwise call Graph every time, and Meta
 * rate-limits template reads per WABA across ALL tenants at once, because it
 * is one shared number. Five minutes is short enough that a newly approved
 * template appears while somebody is still waiting for it.
 */
class WhatsAppTemplatesController extends Controller
{
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly WhatsAppCloud $cloud) {}

    /**
     * GET /api/v1/automations/whatsapp-templates
     */
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', Automation::class);

        /*
         * The tenant's number, which today is EzBizi's one shared line mapped
         * to whichever organization the placeholder row names. Read through the
         * tenant scope on purpose: an organization with no row gets the empty
         * answer below rather than another tenant's WABA.
         */
        $number = WhatsAppNumber::query()->first();

        if ($number === null) {
            // Not an error and deliberately not a 404. "No WhatsApp number is
            // attached here" is a true and useful answer for a builder that
            // wants to grey out the action, and a 404 would read as a broken
            // endpoint to the one screen that calls it.
            return response()->json([
                'data' => [],
                'because' => 'No WhatsApp number is attached to this organization, so there is '
                    .'nothing to send from.',
            ]);
        }

        try {
            $templates = Cache::remember(
                'whatsapp.templates.'.$number->waba_id,
                self::CACHE_SECONDS,
                fn () => $this->cloud->templates((string) $number->waba_id),
            );
        } catch (RuntimeException $e) {
            // 409 rather than 500: Meta refusing or being unreachable is a state
            // of the world, not a fault in this request, and a 500 would put it
            // in the error tracker every time Meta has a bad minute.
            return response()->json(['message' => $e->getMessage()], Status::HTTP_CONFLICT);
        }

        if ($templates === []) {
            /*
             * The second empty answer, and it needed saying.
             *
             * "No number attached" and "no template approved yet" both come
             * back as `data: []`, and until this existed they were told apart
             * only by whether `because` was present - so a builder rendering
             * `because` verbatim showed nothing at all for the commoner of the
             * two. A WABA with nothing approved is the NORMAL day-one state:
             * approval takes Meta hours, and in between the picker would read
             * as "this feature is broken" rather than "come back shortly".
             */
            return response()->json([
                'data' => [],
                'because' => 'No WhatsApp template has been approved yet. Meta reviews each one '
                    .'before it can be sent, so this list fills in once that is done.',
            ]);
        }

        return response()->json(['data' => $templates]);
    }
}
