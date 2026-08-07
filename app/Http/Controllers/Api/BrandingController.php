<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Branding\Branding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    public function __construct(private AuditLogger $audit) {}

    public function uploadOrganization(Request $request): array
    {
        $organization = $request->user()->organization;

        abort_unless(
            $request->user()->hasPermission('organizations.edit'),
            403,
            'Missing permission: organizations.edit.',
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
        abort_unless($request->user()->hasPermission('organizations.edit'), 403);

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

    public function company(Request $request, Company $company)
    {
        // The tenant scope already refuses another organization's company, and
        // `findOrFail` binding runs inside it. Falling back to the
        // organization's mark rather than 404ing keeps a company that has not
        // set one looking like part of the same business.
        return $company->logo_path === null
            ? $this->serve($request->user()->organization)
            : $this->serve($company);
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
            "A logo must be exactly ".Branding::expected()." pixels. "
            ."That one is {$size[0]}x{$size[1]} — use the cropper, which produces "
            ."the right size from any image.",
        );

        $previous = $subject->logo_path;

        $path = $file->store("logos/{$request->user()->organization_id}", 'local');

        // Written together. A path without a shape is a state nothing can lay
        // out, so neither is ever set alone.
        $subject->forceFill(['logo_path' => $path, 'logo_aspect' => $aspect])->save();

        // Removed only after the new one is safely stored, so a failed write
        // never leaves a business with no mark and no way back.
        if ($previous !== null && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        $this->audit->log($action, $subject);

        return ['logo' => true];
    }

    private function clear(Model $subject, string $action): array
    {
        if ($subject->logo_path !== null) {
            Storage::disk('local')->delete($subject->logo_path);
            $subject->forceFill(['logo_path' => null, 'logo_aspect' => null])->save();

            $this->audit->log($action, $subject);
        }

        return ['logo' => false];
    }

    private function serve(Organization|Company $subject)
    {
        abort_if($subject->logo_path === null, 404);
        abort_unless(Storage::disk('local')->exists($subject->logo_path), 404);

        return response()->file(Storage::disk('local')->path($subject->logo_path));
    }
}
