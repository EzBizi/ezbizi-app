<?php

namespace App\Support\Leads;

use App\Http\Controllers\Api\LeadsController;
use App\Models\Lead;
use App\Models\User;
use App\Support\Notifications\LeadNotificationText;

/**
 * Leads matching a name, email, ZIP or phone number, for another module's
 * search box (Customers, 2026-10-16: a dispatcher searched for James Quinn and
 * found nothing because he was only an LS Call lead).
 *
 * The reader's own leads only, by the rules the Leads list uses: the
 * leads.view grant, each source's own tick (Lead::sourceVisibleTo), the
 * branches they can reach (LeadsController::scopeToReader), and not a lead
 * whose account was disconnected. Job applicants are not customers and stay out.
 */
final class LeadSearch
{
    /**
     * @return list<array{id: string, name: ?string, phone: ?string, email: ?string, source: string, source_label: string, status: string, created_at: ?string}>
     */
    public static function forUser(User $user, string $term, int $limit = 10): array
    {
        $term = trim($term);
        if ($term === '' || ! $user->hasPermission('leads.view')) {
            return [];
        }
        $digits = Lead::digits($term);

        $query = LeadsController::scopeToReader(Lead::query(), $user)
            ->sourceVisibleTo($user)->sourceStillConnected()
            ->where('leads.status', '!=', Lead::STATUS_JOB_APPLICANT)
            ->where('leads.is_test', false)
            ->where(function ($q) use ($term, $digits) {
                $q->where('leads.contact_name', 'like', '%'.$term.'%')
                    ->orWhere('leads.email', 'like', '%'.$term.'%')
                    ->orWhere('leads.postal_code', $term);
                // Four digits at least: a short number matches half the list.
                if ($digits !== null && strlen($digits) >= 4) {
                    $q->orWhere('leads.phone_digits', 'like', '%'.$digits.'%');
                }
            })
            ->orderByDesc('leads.received_at')
            ->limit(max(1, min($limit, 50)));

        return $query->get()->map(fn (Lead $l) => [
            'id' => (string) $l->id,
            'name' => $l->contact_name,
            'phone' => $l->phone,
            'email' => $l->email,
            'source' => $l->source,
            'source_label' => LeadNotificationText::label((string) $l->source, $l->lead_type),
            'status' => $l->status,
            'created_at' => $l->received_at?->toIso8601String(),
        ])->values()->all();
    }
}
