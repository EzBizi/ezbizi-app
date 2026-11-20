<?php

namespace App\Support\CustomerExperience;

use App\Models\Complaint;
use App\Models\ComplaintNote;
use App\Models\Lead;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Tasks\ModuleBoard;
use Throwable;

/**
 * Reviews & Complaints on Task Board (Shoval and Eric, 2026-11-20: "a dedicated board" for Adeline and the
 * GMs). Complaints stays the truth; every complaint is a card on the tenant's "Reviews & Complaints" board,
 * one group per market, and a person's status, owner or note on the card comes back here.
 */
final class ComplaintBoardMirror
{
    public const KEY = 'cx-complaints';

    private const STATUS = ['new' => 'New', 'in_progress' => 'In progress', 'solved' => 'Solved', 'not_solvable' => 'Cannot be solved'];

    private const KIND = ['bad_review' => 'Google review', 'bad_rating' => 'Bad rating', 'call_complaint' => 'Call', 'bbb' => 'BBB',
        'attorney_general' => 'Attorney General', 'lawsuit' => 'Lawsuit', 'other' => 'Other'];

    /** Card for this complaint, made or brought up to date. A failure is reported, never blocks the complaint. */
    public static function sync(Complaint $c): ?Task
    {
        try {
            $mirror = app(ModuleBoard::class);
            $board = $mirror->board(self::KEY, 'Reviews & Complaints',
                ['New' => false, 'In progress' => false, 'Solved' => true, 'Cannot be solved' => true],
                ['Kind' => array_values(self::KIND), 'Client' => ['Client', 'Not a client'], 'Priority' => ['Priority', 'Normal'],
                    'Rating' => 'number', 'Link' => 'text']);

            return $mirror->upsert($board, 'complaint:'.$c->id, [
                'title' => self::title($c),
                'group' => $c->franchise?->name ?? 'No market',
                'status' => self::STATUS[$c->status] ?? 'New',
                'owners' => $c->owner_user_id ? [$c->owner_user_id] : [],
                'due_on' => $c->due_at?->format('Y-m-d'),
                'values' => array_filter([
                    'Kind' => self::KIND[$c->kind] ?? 'Other',
                    'Client' => ['client' => 'Client', 'not_client' => 'Not a client'][$c->client_status] ?? null,
                    'Priority' => $c->priority ? 'Priority' : 'Normal',
                    'Rating' => $c->rating,
                    'Link' => rtrim((string) config('app.url'), '/').'/app/#/customer_experience/complaints/'.$c->id,
                ], fn ($v) => $v !== null),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function note(ComplaintNote $n): void
    {
        try {
            $complaint = Complaint::query()->find($n->complaint_id);
            $task = $complaint ? self::sync($complaint) : null;
            $author = $n->user_id ? User::query()->find($n->user_id) : null;
            $task && $author && app(ModuleBoard::class)->note($task, $author, $n->body);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A person changed the card: status, owner or a note come back to the complaint. */
    public static function heard(Task $task, array $kinds, ?TaskUpdate $update): void
    {
        $complaint = Complaint::query()->find(substr((string) $task->external_ref, strlen('complaint:')));
        if ($complaint === null) {
            return;
        }
        if (in_array('status', $kinds, true)) {
            $status = array_search(TaskStatus::query()->find($task->status_id)?->name, self::STATUS, true);
            if ($status !== false && $status !== $complaint->status) {
                $complaint->status = $status;
                $complaint->solved_at = in_array($status, ['solved', 'not_solvable'], true) ? ($complaint->solved_at ?? now()) : null;
            }
        }
        if (in_array('owners', $kinds, true)) {
            $complaint->owner_user_id = $task->owners()->pluck('user_id')->first();
        }
        if ($complaint->isDirty()) {
            $complaint->saveQuietly();
        }
        if (in_array('update', $kinds, true) && $update !== null && trim((string) $update->body) !== '') {
            ComplaintNote::withoutEvents(fn () => ComplaintNote::query()->create(['organization_id' => $complaint->organization_id,
                'complaint_id' => $complaint->id, 'user_id' => $update->user_id ?? null, 'body' => $update->body, 'created_at' => now()]));
        }
    }

    /** "BBB: case 77", "Google review: Dana Whitfield · 2★": also the {{title}} automations show. */
    public static function title(Complaint $c): string
    {
        $who = $c->title;
        if ($who === null && $c->source_type === 'review') {
            $r = Review::query()->find($c->source_id);
            $who = trim(($r?->reviewer_name ?? 'Review').' · '.($r?->rating ?? '?').'★');
        } elseif ($who === null && $c->source_type === 'lead') {
            $who = Lead::query()->find($c->source_id)?->contact_name ?? 'Caller';
        }

        return mb_substr((self::KIND[$c->kind] ?? 'Complaint').': '.($who ?? 'Complaint'), 0, 250);
    }
}
