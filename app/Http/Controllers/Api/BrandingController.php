<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Organization;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Branding\Branding;
use App\Support\Files\FileStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Logos for the organization and its companies (plan §12).
 *
 * §12 puts branding in configuration rather than code: "colors, logo and
 * typography stored per organization, not compiled constants."
 *
 * ── Served through here, never as a public file ──────────────────
 *
 * The same reasoning as avatars. A logo at a guessable path is a way to
 * enumerate the platform's customers — every organization that exists, by id,
 * without an account. Behind an endpoint it is one tenant check.
 *
 * ── Franchises deliberately have none ────────────────────────────
 *
 * A franchise is a geography (§3), not a brand. "Connecticut" has no logo; the
 * company operating there does. A company with none falls back to the
 * organization's at render time — inherited, not copied, so changing the
 * organization's changes every company that never set one.
 */
class BrandingController extends Controller
{
    /**
     * A 512x512 or 1024x256 PNG has no business being larger than this.
     *
     * The cropper produces the file, so anything approaching the limit is
     * either a photograph pushed through it or something that did not come from
     * the app at all.
     */
    private const MAX_KILOBYTES = 1024;

    /**
     * Storage goes through FileStore, not `Storage::disk('local')`.
     *
     * This controller was one of the two places that named the disk by hand —
     * four times, and read files back with `->path()`, which exists only on the
     * local driver. FILESYSTEM_DISK therefore moved nothing, which is the
     * opposite of what §11 promises. See FileStore, including what somebody has
     * to do to the existing `logos/` tree before changing that value.
     */
    public function __construct(
        private AuditLogger $audit,
        private FileStore $files,
    ) {}

    public function uploadOrganization(Request $request): array
    {
        $organization = $request->user()->organization;

        // Refusal's sentence, not a permission key. "Missing permission:
        // organizations.edit." is a wire name on a screen (R8) — it names a
        // thing that appears nowhere in the product, and the remedy it implies
        // is for the reader to go and find it.
        abort_unless(
            $request->user()->hasPermission('organizations.edit'),
            403,
            Refusal::permission('change the organization’s logo')->message(),
        );

        return $this->store($request, $organization, 'organization.logo_changed');
    }

    public function uploadCompany(Request $request, Company $company): array
    {
        $this->authorize('update', $company);

        return $this->store($request, $company, 'company.logo_changed');
    }

    public function destroyOrganization(Request $request): array
    {
        abort_unless(
            $request->user()->hasPermission('organizations.edit'),
            403,
            Refusal::permission('remove the organization’s logo')->message(),
        );

        return $this->clear($request->user()->organization, 'organization.logo_removed');
    }

    public function destroyCompany(Request $request, Company $company): array
    {
        $this->authorize('update', $company);

        return $this->clear($company, 'company.logo_removed');
    }

    /**
     * The organization's mark.
     *
     * No permission beyond being signed in: everybody in the tenant sees it on
     * every screen, so gating it would only break the UI for the people it is
     * drawn for. The tenant boundary is the whole check.
     */
    public function organization(Request $request)
    {
        return $this->serve($request->user()->organization);
    }

    /**
     * A company's mark.
     *
     * ── The tenant check is HERE, not inherited ──────────────────
     *
     * This used to say the tenant scope had already refused another
     * organization's company because binding ran inside it. That was not true:
     * the resolved pipeline put SubstituteBindings ahead of SetTenantContext, so
     * `{company}` was looked up with no tenant in context — and TenantScope adds
     * no predicate when there is none. bootstrap/app.php now orders tenancy
     * before binding, and TenantScope refuses rather than returning everything,
     * so the claim is finally true. It is still not a reason to make this the
     * only bound-model read in the API with nothing of its own: §5.1 puts
     * authorization alongside the scope in the boundary, not behind it.
     *
     * 404 rather than 403, matching the avatar endpoint: another tenant's
     * company is not merely forbidden, it is not there — and saying "forbidden"
     * confirms the id exists.
     *
     * No PERMISSION beyond membership, for the same reason `organization()` has
     * none: the scope picker draws a mark beside every company the caller can
     * reach, and reaching a franchise does not imply `companies.view`. Gating
     * this on the policy would blank those logos for everybody below an
     * administrator — and would also put a logo behind the platform module flag,
     * which is a switch for a feature, not for the chrome around it.
     *
     * Falling back to the organization's mark rather than 404ing keeps a company
     * that has not set one looking like part of the same business.
     */
    public function company(Request $request, Company $company)
    {
        abort_unless(
            $company->organization_id === $request->user()->organization_id,
            404,
        );

        return $company->logo_path === null
            ? $this->serve($request->user()->organization)
            : $this->serve($company);
    }

    /**
     * The colour this business publishes under, read and written.
     *
     * ── Why a colour lives beside the logos ──
     *
     * It is the same fact about the same subject with the same inheritance: a
     * company's if it set one, else the organization's, resolved at read time so
     * changing the organization's changes every company that never did. A logo
     * in one scheme and a button in another describes two businesses.
     *
     * ── What reads it ──
     *
     * Web Management's chat and contact-form widgets, which render on the
     * CUSTOMER's own website. Before this existed their only possible default
     * was a colour EzBizi chose, which made every customer's contact form look
     * like an EzBizi advert until somebody noticed.
     *
     * ── Why writing needs a permission and reading does not ──
     *
     * Reading matches `organization()` and `company()` directly above: everybody
     * in the tenant sees the mark on every screen, so gating it would only break
     * the chrome for the people it is drawn for. Writing changes what strangers
     * see on the company's public website, which is the same weight as replacing
     * the logo and takes the same permission.
     */
    public function colour(Request $request)
    {
        $organization = $request->user()->organization;

        return ['data' => $this->colourState($organization)];
    }

    /** PUT /branding/colour — null clears it and returns the business to no stated colour. */
    public function setColour(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('organizations.edit'),
            403,
            Refusal::permission('change the organization’s colour')->message(),
        );

        $input = $request->validate([
            /*
             * `#rrggbb` only, and the same shape the widget config accepts. A
             * looser form would have to be parsed to be trusted, and this value
             * is interpolated into a stylesheet on a website we do not serve.
             */
            'colour' => ['present', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $organization = $request->user()->organization;
        $organization->brand_colour = $input['colour'] === null
            ? null
            : strtolower($input['colour']);
        $organization->save();

        return ['data' => $this->colourState($organization)];
    }

    /**
     * Everything a screen needs to draw the colour, computed in one place.
     *
     * ── Why the derived text colour is here and not in the client ──
     *
     * The picker's preview is a button in the brand colour with its automatic
     * text on it. If the client derived that itself, there would be two
     * definitions of readable — and the day the threshold moved, the preview
     * and the customer's actual website would disagree, with a customer the
     * first to notice. Same string, computed once, sent to both.
     *
     * ── The three values, and why `effective` is not enough on its own ──
     *
     * `effective` answers "has this business told us a colour", and null is a
     * real answer rather than a missing one. `rendered` answers the different
     * question a visitor's eye asks — what colour is actually on the page —
     * which for a business that has told us nothing is the fallback. A screen
     * needs both: the first decides whether to show the not-set state, the
     * second is what it draws either way.
     *
     * @return array<string, string|null>
     */
    private function colourState(Organization $organization): array
    {
        $effective = Branding::colour(null, $organization);
        $rendered = $effective ?? Branding::FALLBACK_COLOUR;

        return [
            'organization' => $organization->brand_colour,
            'effective' => $effective,
            'on_effective' => $effective === null ? null : Branding::readableOn($effective),
            'rendered' => $rendered,
            'on_rendered' => Branding::readableOn($rendered),
        ];
    }

    /** The shapes and sizes the cropper must produce. */
    public function shapes(): array
    {
        // Served rather than duplicated in the client. The cropper's output has
        // to match the validator's expectation exactly, and two hardcoded lists
        // of numbers that must agree eventually will not.
        return ['shapes' => Branding::SHAPES, 'format' => Branding::MIME];
    }

    private function store(Request $request, Model $subject, string $action): array
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:'.Branding::MIME, 'max:'.self::MAX_KILOBYTES],
        ]);

        $file = $request->file('logo');
        $size = @getimagesize($file->getRealPath());

        abort_if($size === false, 422, 'That file is not a readable image.');

        // EXACT dimensions, one of two. Not a maximum and not an aspect ratio:
        // uniformity is the whole reason the shapes exist, and "close enough"
        // produces a row of logos that each sit slightly differently.
        //
        // Checked here as well as in the cropper because the cropper is a
        // convenience, not a boundary — this endpoint is reachable without it.
        $aspect = Branding::shapeOf($size[0], $size[1]);

        abort_if(
            $aspect === null,
            422,
            'A logo must be exactly '.Branding::expected().' pixels. '
            ."That one is {$size[0]}x{$size[1]} — use the cropper, which produces "
            .'the right size from any image.',
        );

        $previous = $subject->logo_path;

        $path = $this->files->put(
            $file,
            $this->files->directoryFor('logos', $request->user()->organization_id),
        );

        // Written together. A path without a shape is a state nothing can lay
        // out, so neither is ever set alone.
        $subject->forceFill(['logo_path' => $path, 'logo_aspect' => $aspect])->save();

        // Removed only after the new one is safely stored, so a failed write
        // never leaves a business with no mark and no way back.
        if ($previous !== null && $previous !== $path) {
            $this->files->delete($previous);
        }

        $this->audit->log($action, $subject);

        return ['logo' => true];
    }

    private function clear(Model $subject, string $action): array
    {
        if ($subject->logo_path !== null) {
            $this->files->delete($subject->logo_path);
            $subject->forceFill(['logo_path' => null, 'logo_aspect' => null])->save();

            $this->audit->log($action, $subject);
        }

        return ['logo' => false];
    }

    /**
     * `inline`, and it is the safe half of that decision.
     *
     * The upload validator has already established that these bytes are a PNG of
     * one of two exact sizes — `getimagesize` read them, not a header somebody
     * sent — so rendering them in the page is what they are for. An arbitrary
     * customer upload gets `attachment` instead; see AttachmentsController.
     */
    private function serve(Organization|Company $subject)
    {
        abort_if($subject->logo_path === null, 404);

        return $this->files->serve(
            $subject->logo_path,
            mime: Branding::contentType(),
            disposition: 'inline',
        );
    }
}
