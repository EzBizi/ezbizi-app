<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\EmailFilter;
use App\Models\Franchise;
use App\Models\Mailbox;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Email filters (Jose, 2026-10-28): anybody with the Inbox makes their own;
 * `email.filters.share` lets somebody make one for everyone at a place they
 * reach -- the organization, a company or a branch. Nothing is hard-coded: the
 * permission is granted through roles like every other.
 */
class EmailFiltersController extends Controller
{
    public const SHARE_PERMISSION = 'email.filters.share';

    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/v1/email/filters -- mine, and the shared ones at places I reach. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'filters' => self::visibleTo($user)->map(fn (EmailFilter $f) => $this->row($f, $user))->values(),
            'may_share' => $user->hasPermission(self::SHARE_PERMISSION),
            'actions' => EmailFilter::ACTIONS,
        ]]);
    }

    /** POST /api/v1/email/filters  { name, from_contains?, subject_contains?, action, scope_type?, scope_id? } */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'from_contains' => ['nullable', 'string', 'max:255', 'required_without:subject_contains'],
            'subject_contains' => ['nullable', 'string', 'max:255'],
            'action' => ['required', Rule::in(EmailFilter::ACTIONS)],
            'scope_type' => ['nullable', Rule::in(['organization', 'company', 'franchise'])],
            'scope_id' => ['nullable', 'required_with:scope_type', 'uuid'],
        ]);

        $shared = ($input['scope_type'] ?? null) !== null;

        if ($shared) {
            $this->assertMayShareAt($user, $input['scope_type'], $input['scope_id']);
        }

        $filter = EmailFilter::query()->create([
            'user_id' => $shared ? null : $user->id,
            'scope_type' => $shared ? $input['scope_type'] : null,
            'scope_id' => $shared ? $input['scope_id'] : null,
            'name' => $input['name'],
            'from_contains' => self::clean($input['from_contains'] ?? null),
            'subject_contains' => self::clean($input['subject_contains'] ?? null),
            'action' => $input['action'],
            'created_by' => $user->id,
        ]);

        $this->audit->log('email_filter.created', $filter, metadata: ['shared' => $shared, 'name' => $filter->name]);

        return response()->json(['data' => $this->row($filter, $user)], 201);
    }

    /** DELETE /api/v1/email/filters/{filter} */
    public function destroy(Request $request, EmailFilter $filter): JsonResponse
    {
        $user = $request->user();

        if ($filter->isShared()) {
            $this->assertMayShareAt($user, $filter->scope_type, $filter->scope_id);
        } else {
            abort_unless($filter->user_id === $user->id, 404);
        }

        $this->audit->log('email_filter.deleted', $filter, metadata: ['shared' => $filter->isShared(), 'name' => $filter->name]);
        $filter->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * The filters that apply to this person's view of one mailbox: theirs,
     * and shared ones whose place contains the mailbox.
     *
     * @param  Collection<int, EmailFilter>  $filters  from {@see visibleTo}
     * @return Collection<int, EmailFilter>
     */
    public static function forMailbox(Collection $filters, Mailbox $mailbox): Collection
    {
        static $franchiseCompany = [];

        return $filters->filter(function (EmailFilter $f) use ($mailbox, &$franchiseCompany) {
            if (! $f->isShared()) {
                return true;
            }

            $companyOfMailbox = match ($mailbox->scope_type) {
                Mailbox::SCOPE_COMPANY => $mailbox->scope_id,
                Mailbox::SCOPE_FRANCHISE => $franchiseCompany[$mailbox->scope_id] ??= Franchise::query()->whereKey($mailbox->scope_id)->value('company_id'),
                default => null,
            };

            return match ($f->scope_type) {
                'organization' => $f->scope_id === $mailbox->organization_id,
                'company' => $companyOfMailbox !== null && $f->scope_id === $companyOfMailbox,
                'franchise' => $mailbox->scope_type === Mailbox::SCOPE_FRANCHISE && $f->scope_id === $mailbox->scope_id,
                default => false,
            };
        })->values();
    }

    /** @return Collection<int, EmailFilter> */
    public static function visibleTo(User $user): Collection
    {
        return EmailFilter::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereNull('user_id'))
            ->orderBy('name')
            ->get()
            ->filter(fn (EmailFilter $f) => ! $f->isShared() || self::reaches($user, $f->scope_type, $f->scope_id))
            ->values();
    }

    private function assertMayShareAt(User $user, string $type, string $id): void
    {
        abort_unless(
            $user->hasPermission(self::SHARE_PERMISSION),
            403,
            Refusal::permission('make an email filter for everyone')->message(),
        );

        abort_unless(self::reaches($user, $type, $id), 403, 'That part of the business is outside what you are assigned to.');
    }

    private static function reaches(User $user, string $type, string $id): bool
    {
        return match ($type) {
            'organization' => $id === $user->organization_id && $user->organization !== null && $user->canActOnOrganization($user->organization),
            'company' => ($c = Company::query()->find($id)) !== null && $user->canActOnCompany($c),
            'franchise' => ($f = Franchise::query()->find($id)) !== null && $user->canActOnFranchise($f),
            default => false,
        };
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function row(EmailFilter $f, User $user): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'from_contains' => $f->from_contains,
            'subject_contains' => $f->subject_contains,
            'action' => $f->action,
            'shared' => $f->isShared(),
            'scope_type' => $f->scope_type,
            'scope_id' => $f->scope_id,
            'may_delete' => $f->isShared() ? $user->hasPermission(self::SHARE_PERMISSION) : $f->user_id === $user->id,
        ];
    }
}
