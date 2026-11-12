<?php

namespace App\Support\Tasks;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\ApiKey;
use App\Support\Auth\FactorAttempts;
use App\Support\Auth\TotpEnrolment;
use App\Support\Auth\TwoFactorPolicy;
use App\Support\Files\FileStore;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sensitive tasks (Jose, 2026-11-12: "a safety filter in our task board which
 * required the authenticator to see"), for monday items such as "Logins" and
 * "Bank & CC" that hold credentials and card numbers.
 *
 * A sensitive task keeps its title readable (so it can be found), and seals
 * everything else at rest: free-text column values, update bodies, Activity
 * from/to, file names (attachments.sealed_name) and file bytes (encrypted on
 * disk). Option ids (status, dropdown, tags) are not content and stay plain,
 * so a column's option clean-up still reads them.
 *
 * Reads are locked unless this person, on this session (token), entered a
 * current authenticator code in the last UNLOCK_SECONDS; every reveal is
 * audited (`task.revealed`). API keys (MCP) and the AI's own tokens never see
 * a sensitive task at all: TaskPolicy answers 404, and lists leave it out.
 */
final class SensitiveTasks
{
    public const PREFIX = 'sealed:v1:';

    /** "Valid 5 minutes", fixed, not sliding. */
    public const UNLOCK_SECONDS = 300;

    /** The ability AppAsk / AppAct put on their own short-lived tokens. */
    public const AI_READER = 'ai-reader';

    /** What a sealed file is called to anybody who has not unlocked it. */
    public const LOCKED_FILE = 'Locked file';

    /** What a mention on a sensitive task says instead of the update. */
    public const NOTICE = 'An update on a sensitive task. Open it with your authenticator to read it.';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TotpEnrolment $totp,
        private readonly FactorAttempts $attempts,
        private readonly TwoFactorPolicy $twoFactor,
        private readonly FileStore $files,
    ) {}

    public static function seal(?string $plain): ?string
    {
        return $plain === null || $plain === '' || self::isSealed($plain)
            ? $plain
            : self::PREFIX.Crypt::encryptString($plain);
    }

    public static function open(?string $stored): ?string
    {
        return self::isSealed($stored) ? Crypt::decryptString(substr($stored, strlen(self::PREFIX))) : $stored;
    }

    public static function isSealed(?string $stored): bool
    {
        return is_string($stored) && str_starts_with($stored, self::PREFIX);
    }

    /** Free text is sealed; an option id is not content. */
    public static function sealsColumn(?string $type): bool
    {
        return ! in_array($type, TaskColumn::WITH_OPTIONS, true);
    }

    /**
     * An API key (MCP) or the AI: never shown a sensitive task. The AI is
     * known by the flag AppAsk / AppAct put on their internal request, not
     * only by its token: InternalRequest keeps a guard's cached user, whose
     * token is the person's own session (measured: the AI read an unlocked
     * task through the person's step-up until this flag was checked).
     */
    public static function machineReader(?User $user): bool
    {
        $token = $user?->currentAccessToken();

        return (bool) request()->attributes->get(self::AI_READER)
            || ApiKey::is($token)
            || ($token instanceof PersonalAccessToken && in_array(self::AI_READER, (array) $token->abilities, true));
    }

    public static function hiddenFrom(?User $user, Task $task): bool
    {
        return (bool) $task->sensitive && self::machineReader($user);
    }

    /**
     * A current authenticator code opens sensitive tasks for this person on
     * this session for UNLOCK_SECONDS. The same checks as Monitoring › Files
     * and integration disconnects: six digits only (a recovery code is never
     * burned here), the factor the person signs in with, the sign-in budget.
     *
     * @return string when it lapses, ISO 8601
     */
    public function unlock(Request $request): string
    {
        $user = $request->user();
        if (self::machineReader($user)) {
            $this->refuse('Sensitive tasks cannot be opened with an API key.', 'machine_reader', 403);
        }
        $subject = $this->twoFactor->subjectFor($user);
        if (! $subject->hasTotp()) {
            $this->refuse('Set up an authenticator on your account first (My account › Security). '
                .'Sensitive tasks open only with a code from it.', 'no_authenticator');
        }
        $code = trim((string) $request->input('code', ''));
        if (! preg_match('/^\d{6}$/', $code)) {
            $this->refuse('Enter the 6-digit code from your authenticator.', 'authenticator_code_required');
        }
        if (! $this->attempts->verify($subject, $code, fn () => $this->totp->verify($subject, $code))) {
            $this->refuse('That code did not verify. If you just used it, wait for the next one.', 'authenticator_code_invalid');
        }

        $until = now()->addSeconds(self::UNLOCK_SECONDS);
        Cache::put($this->key($request), $until->getTimestamp(), self::UNLOCK_SECONDS);
        $this->audit->log('task.sensitive_unlocked', $user, metadata: ['until' => $until->toIso8601String()], actor: $user);

        return $until->toIso8601String();
    }

    public function lock(Request $request): void
    {
        Cache::forget($this->key($request));
    }

    public function unlocked(Request $request): bool
    {
        return $this->until($request) !== null;
    }

    /** When this session's unlock lapses, ISO 8601; null when it is locked. */
    public function until(Request $request): ?string
    {
        if (self::machineReader($request->user())) {
            return null;
        }
        $until = Cache::get($this->key($request));

        return is_int($until) && $until > now()->getTimestamp()
            ? now()->setTimestamp($until)->toIso8601String()
            : null;
    }

    /**
     * Whether [task]'s content may go out on this request; a sensitive one is
     * audited each time it does. Not sensitive: always, and no audit.
     */
    public function reveal(Request $request, Task $task, string $via): bool
    {
        if (! $task->sensitive) {
            return true;
        }
        if (! $this->unlocked($request)) {
            return false;
        }
        $this->audit->log('task.revealed', $task, metadata: ['via' => $via], actor: $request->user());

        return true;
    }

    /** 403 with the code the client reads to show "Unlock with authenticator". */
    public static function lockedResponse(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => 'This task is sensitive. Unlock it with your authenticator first.',
            'code' => 'task_locked',
        ], 403));
    }

    /**
     * Mark or unmark [task] (and its subtasks): seal or open every stored
     * value, update, Activity row and file name; files are encrypted on disk
     * when marked and stay encrypted when unmarked (served decrypted).
     */
    public function mark(Task $task, bool $on, ?User $actor): void
    {
        DB::transaction(function () use ($task, $on, $actor) {
            $tasks = collect([$task])->concat(Task::query()->where('parent_task_id', $task->id)->get());
            foreach ($tasks as $t) {
                $changed = (bool) $t->sensitive !== $on;
                // Sealing is idempotent, so marking an already-sensitive task
                // again seals whatever reached it around TaskWriter (an import).
                if (! $changed && ! $on) {
                    continue;
                }
                $old = $this->convert($t, $on);
                // The plain copies go only once the encrypted ones are committed;
                // a rolled-back run (an import's --dry-run) keeps them.
                DB::afterCommit(function () use ($old) {
                    foreach ($old as [$path, $disk]) {
                        $this->files->delete($path, $disk);
                    }
                });
                if (! $changed) {
                    continue;
                }
                $t->forceFill(['sensitive' => $on])->save();
                TaskActivity::query()->create([
                    'organization_id' => $t->organization_id, 'task_id' => $t->id, 'user_id' => $actor?->id,
                    'field' => 'sensitive', 'label' => 'Sensitive', 'from' => $on ? 'No' : 'Yes', 'to' => $on ? 'Yes' : 'No',
                ]);
                $this->audit->log($on ? 'task.marked_sensitive' : 'task.unmarked_sensitive', $t, actor: $actor);
            }
        });
    }

    /** Monday's names for credential items (D13, 2026-11-12): the importer marks these. */
    public static function looksSensitive(string ...$names): bool
    {
        foreach ($names as $name) {
            if (preg_match('/login|password|bank|credit card|\bcc\b|ssn|routing/i', $name)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{0: string, 1: string}> plain files to delete */
    private function convert(Task $task, bool $on): array
    {
        $f = fn (?string $v) => $on ? self::seal($v) : self::open($v);

        TaskColumnValue::query()->with('column')->where('task_id', $task->id)->get()
            ->filter(fn (TaskColumnValue $v) => self::sealsColumn($v->column?->type))
            ->each(fn (TaskColumnValue $v) => $v->forceFill(['value_text' => $f($v->value_text)])->save());

        $updates = TaskUpdate::query()->where('task_id', $task->id)->get();
        $updates->each(fn (TaskUpdate $u) => $u->forceFill(['body' => $f($u->body) ?? ''])->save());

        TaskActivity::query()->where('task_id', $task->id)->get()
            ->each(fn (TaskActivity $a) => $a->forceFill(['from' => $f($a->from), 'to' => $f($a->to)])->save());

        $old = [];
        $files = Attachment::query()->where('attachable_type', 'task_updates')->whereIn('attachable_id', $updates->pluck('id'))->get();
        foreach ($files as $a) {
            if ($on) {
                if ($a->sealed_name === null) {
                    $a->forceFill(['sealed_name' => self::seal($a->original_name), 'original_name' => self::LOCKED_FILE]);
                }
                if (! $a->encrypted && ($bytes = $this->files->contents($a->path, $a->disk)) !== null) {
                    $old[] = [$a->path, $a->disk];
                    $a->forceFill([
                        'path' => $this->files->putContents(Crypt::encryptString($bytes),
                            $this->files->directoryFor('attachments', $a->organization_id), 'enc', $a->disk),
                        'encrypted' => true,
                    ]);
                }
            } elseif ($a->sealed_name !== null) {
                $a->forceFill(['original_name' => mb_substr((string) self::open($a->sealed_name), 0, 255), 'sealed_name' => null]);
            }
            $a->save();
        }

        return $old;
    }

    /** Per person and per session: a token's id, else the web session's. */
    private function key(Request $request): string
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $id = $token instanceof PersonalAccessToken ? $token->getKey() : null;
        $session = $id ? 'token:'.$id : ($request->hasSession() ? 'session:'.$request->session()->getId() : 'none');

        return 'task-sensitive-unlock:'.$user?->getKey().':'.$session;
    }

    private function refuse(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => ['code' => [$message]],
        ], $status));
    }
}
