<?php

namespace App\Http\Controllers\Api;

use App\Models\Chat;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Chat\ChatPulse;
use App\Support\Files\AttachmentUploader;
use App\Support\Files\UploadLimits;
use App\Support\Notifications\PushSender;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chat: EzBizi's own WhatsApp between EzBizi accounts (Jose, 2026-11-25).
 *
 * Messages are ours, end to end: no Twilio, no SMS, nothing billed per text.
 * Calls are placed by the client on the Twilio identity that already rings a
 * colleague (`client:<id>`); this controller only records them as a line in
 * the chat.
 *
 * Every route sits behind `phone.chat.view` (routes/api.php). Inside it, the
 * boundary is MEMBERSHIP: a chat you are not in is a 404, exactly like one in
 * another organization, so an id says nothing about whether it exists.
 *
 * There is no websocket in this app. Clients poll GET chat/pulse (cheap: two
 * cache reads) and refetch only when its `rev` moves; ChatPulse::bump moves
 * it for every member on every write.
 */
class ChatController extends Controller
{
    /** WhatsApp's window for editing a sent message. */
    private const EDIT_MINUTES = 15;

    /** Photos, documents, and the audio/video types the global list leaves out. */
    private const MEDIA_EXTENSIONS = ['m4a', 'aac', 'mp3', 'ogg', 'oga', 'opus', 'wav', 'webm', 'mp4', 'mov', 'gif', 'zip', 'ppt', 'pptx'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ChatPulse $pulse,
    ) {}

    // ── people ───────────────────────────────────────────────────

    /** GET chat/contacts — every active colleague who can use Chat. */
    public function contacts(Request $request): JsonResponse
    {
        $me = $request->user();
        $online = $this->pulse->online(User::query()->where('is_active', true)->pluck('id')->all());

        $rows = User::query()
            ->where('is_active', true)
            ->whereKeyNot($me->getKey())
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $u->hasPermission('phone.chat.view'))
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'online' => in_array($u->id, $online, true),
                'last_seen' => $this->pulse->lastSeen($u->id),
            ])->values();

        return response()->json(['data' => $rows]);
    }

    // ── chats ────────────────────────────────────────────────────

    /** GET chat/chats — my conversations, newest activity first. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $mine = ChatMember::query()->where('user_id', $me->id)->whereNull('left_at')->get()->keyBy('chat_id');
        $chats = Chat::query()->whereIn('id', $mine->keys())->with('activeMembers.user:id,name')->get();

        $unread = ChatMessage::query()
            ->join('chat_members as m', fn ($j) => $j->on('m.chat_id', '=', 'chat_messages.chat_id')->where('m.user_id', $me->id))
            ->whereIn('chat_messages.chat_id', $mine->keys())
            ->where(fn ($q) => $q->whereNull('chat_messages.sender_id')->orWhere('chat_messages.sender_id', '!=', $me->id))
            ->whereNull('chat_messages.deleted_at')
            ->whereNotIn('chat_messages.kind', ['system'])
            ->whereRaw('chat_messages.created_at > COALESCE(m.read_at, ?)', ['1970-01-01'])
            ->whereRaw('chat_messages.created_at > COALESCE(m.cleared_at, ?)', ['1970-01-01'])
            ->groupBy('chat_messages.chat_id')
            ->select('chat_messages.chat_id', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'chat_id');

        $rows = $chats->map(function (Chat $chat) use ($me, $mine, $unread) {
            $member = $mine[$chat->id];
            // ponytail: one query per chat for the last line; a lateral join if lists grow past hundreds.
            $last = $this->visible($chat, $member)->latest('created_at')->first();

            return [
                ...$this->chatRow($chat, $me),
                'last' => $last ? $this->snippet($last, $me, $chat) : null,
                'unread' => (int) ($unread[$chat->id] ?? 0),
                'marked_unread' => $member->marked_unread,
                'pinned' => $member->pinned_at !== null,
                'archived' => $member->archived_at !== null,
                'muted' => $member->isMuted(),
                'activity_at' => ($last?->created_at ?? $chat->created_at)?->toIso8601ZuluString('microsecond'),
            ];
        })->sortByDesc('activity_at')->values();

        return response()->json(['data' => $rows]);
    }

    /** POST chat/chats — open a direct chat (user_id) or create a group (name, user_ids). */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $input = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'user_ids' => ['nullable', 'array', 'max:256'],
            'user_ids.*' => ['uuid'],
        ]);

        if (! empty($input['user_id'])) {
            $other = $this->colleague($input['user_id']);
            abort_if($other->id === $me->id, Response::HTTP_UNPROCESSABLE_ENTITY, 'You cannot start a chat with yourself.');

            $chat = DB::transaction(function () use ($me, $other) {
                $key = Chat::directKey($me->id, $other->id);
                $chat = Chat::query()->where('direct_key', $key)->lockForUpdate()->first()
                    ?? Chat::query()->create([
                        'organization_id' => $this->tenant->idOrFail(),
                        'type' => 'direct',
                        'direct_key' => $key,
                        'created_by' => $me->id,
                    ]);
                foreach ([$me, $other] as $person) {
                    ChatMember::query()->firstOrCreate(
                        ['chat_id' => $chat->id, 'user_id' => $person->id],
                        ['organization_id' => $chat->organization_id],
                    )->forceFill(['left_at' => null, 'archived_at' => null])->save();
                }

                return $chat;
            });

            return response()->json(['data' => $this->chatRow($chat->fresh('activeMembers.user'), $me)], Response::HTTP_CREATED);
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the group a name.']);
        }
        $people = collect($input['user_ids'] ?? [])->unique()->reject(fn ($id) => $id === $me->id)
            ->map(fn ($id) => $this->colleague($id));

        $chat = DB::transaction(function () use ($me, $name, $input, $people) {
            $chat = Chat::query()->create([
                'organization_id' => $this->tenant->idOrFail(),
                'type' => 'group',
                'name' => $name,
                'description' => $input['description'] ?? null,
                'created_by' => $me->id,
            ]);
            ChatMember::query()->create(['organization_id' => $chat->organization_id, 'chat_id' => $chat->id, 'user_id' => $me->id, 'is_admin' => true]);
            foreach ($people as $person) {
                ChatMember::query()->create(['organization_id' => $chat->organization_id, 'chat_id' => $chat->id, 'user_id' => $person->id]);
            }
            $this->system($chat, $me, 'created', ['name' => $name]);

            return $chat;
        });

        $this->pulse->bump($chat);

        return response()->json(['data' => $this->chatRow($chat->fresh('activeMembers.user'), $me)], Response::HTTP_CREATED);
    }

    /** GET chat/chats/{chat} — contact/group info. */
    public function show(Request $request, Chat $chat): JsonResponse
    {
        $me = $request->user();
        $member = $this->member($chat, $me);
        $chat->load('activeMembers.user:id,name,email');
        $online = $this->pulse->online($chat->activeMembers->pluck('user_id')->all());

        return response()->json(['data' => [
            ...$this->chatRow($chat, $me),
            'description' => $chat->description,
            'only_admins_send' => $chat->only_admins_send,
            'created_at' => $chat->created_at?->toIso8601String(),
            'i_am_admin' => $member->is_admin,
            'muted' => $member->isMuted(),
            'muted_until' => $member->muted_until?->toIso8601String(),
            'pinned' => $member->pinned_at !== null,
            'archived' => $member->archived_at !== null,
            'media_count' => $this->visible($chat, $member)->whereIn('kind', ['image', 'video', 'file', 'audio'])->count(),
            'starred_count' => $this->visible($chat, $member)->whereJsonContains('starred_by', $me->id)->count(),
            'members' => $chat->activeMembers->map(fn (ChatMember $m) => [
                'id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'is_admin' => $m->is_admin,
                'online' => in_array($m->user_id, $online, true),
                'last_seen' => $this->pulse->lastSeen($m->user_id),
            ])->sortBy(fn ($m) => [$m['id'] !== $me->id, ! $m['is_admin'], $m['name']])->values(),
        ]]);
    }

    /** PATCH chat/chats/{chat} — group name, description, who may send (admins). */
    public function update(Request $request, Chat $chat): JsonResponse
    {
        $me = $request->user();
        $member = $this->member($chat, $me);
        abort_unless($chat->type === 'group', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only a group has a name and description.');
        abort_unless($member->is_admin, Response::HTTP_FORBIDDEN, 'Only group admins can change the group.');

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'only_admins_send' => ['sometimes', 'boolean'],
        ]);
        $before = $chat->only(array_keys($input));
        $chat->fill($input)->save();

        if (isset($input['name']) && $input['name'] !== $before['name']) {
            $this->system($chat, $me, 'renamed', ['name' => $input['name']]);
        }
        if (array_key_exists('description', $input) && $input['description'] !== $before['description']) {
            $this->system($chat, $me, 'described');
        }
        $this->pulse->bump($chat);

        return $this->show($request, $chat);
    }

    /** POST chat/chats/{chat}/members — add people to a group (admins). */
    public function addMembers(Request $request, Chat $chat): JsonResponse
    {
        $me = $request->user();
        $this->adminOfGroup($chat, $me);
        $input = $request->validate(['user_ids' => ['required', 'array', 'min:1', 'max:256'], 'user_ids.*' => ['uuid']]);

        foreach (collect($input['user_ids'])->unique() as $id) {
            $person = $this->colleague($id);
            $row = ChatMember::query()->firstOrNew(['chat_id' => $chat->id, 'user_id' => $person->id]);
            if ($row->exists && $row->left_at === null) {
                continue;
            }
            // A person added back sees only what is said from now on, as WhatsApp.
            $row->forceFill(['organization_id' => $chat->organization_id, 'left_at' => null, 'is_admin' => false, 'cleared_at' => now()])->save();
            $this->system($chat, $me, 'added', ['user_id' => $person->id, 'name' => $person->name]);
        }
        $this->pulse->bump($chat);

        return $this->show($request, $chat);
    }

    /** PATCH chat/chats/{chat}/members/{user} — make or dismiss an admin. */
    public function updateMember(Request $request, Chat $chat, string $user): JsonResponse
    {
        $me = $request->user();
        $this->adminOfGroup($chat, $me);
        $input = $request->validate(['is_admin' => ['required', 'boolean']]);
        $row = $chat->activeMembers()->where('user_id', $user)->firstOrFail();
        $row->forceFill(['is_admin' => $input['is_admin']])->save();
        $this->pulse->bump($chat);

        return $this->show($request, $chat);
    }

    /** DELETE chat/chats/{chat}/members/{user} — an admin removes someone, or you leave. */
    public function removeMember(Request $request, Chat $chat, string $user): JsonResponse
    {
        $me = $request->user();
        $member = $this->member($chat, $me);
        abort_unless($chat->type === 'group', Response::HTTP_UNPROCESSABLE_ENTITY, 'You cannot leave a direct chat; archive or clear it instead.');
        abort_unless($user === $me->id || $member->is_admin, Response::HTTP_FORBIDDEN, 'Only group admins can remove people.');

        $row = $chat->activeMembers()->where('user_id', $user)->firstOrFail();
        $row->forceFill(['left_at' => now(), 'is_admin' => false])->save();
        $this->system($chat, $me, $user === $me->id ? 'left' : 'removed', ['user_id' => $user, 'name' => $row->user?->name]);

        // A group never loses its last admin: the longest-standing member takes over.
        if (! $chat->activeMembers()->where('is_admin', true)->exists()) {
            $chat->activeMembers()->oldest()->first()?->forceFill(['is_admin' => true])->save();
        }
        $this->pulse->bump($chat, also: [$user]);

        return response()->json(['ok' => true]);
    }

    /** PATCH chat/chats/{chat}/settings — my own pin, archive, mute, unread mark, clear. */
    public function settings(Request $request, Chat $chat): JsonResponse
    {
        $me = $request->user();
        $member = $this->member($chat, $me);
        $input = $request->validate([
            'pinned' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            // Hours; 0 unmutes, -1 is "always".
            'mute_hours' => ['sometimes', 'integer', 'min:-1', 'max:8760'],
            'marked_unread' => ['sometimes', 'boolean'],
            'clear' => ['sometimes', 'accepted'],
        ]);

        if (($input['pinned'] ?? false) && $member->pinned_at === null
            && ChatMember::query()->where('user_id', $me->id)->whereNotNull('pinned_at')->whereNull('left_at')->count() >= 3) {
            throw ValidationException::withMessages(['pinned' => 'You can pin up to 3 chats.']);
        }

        if (array_key_exists('pinned', $input)) {
            $member->pinned_at = $input['pinned'] ? now() : null;
        }
        if (array_key_exists('archived', $input)) {
            $member->archived_at = $input['archived'] ? now() : null;
        }
        if (array_key_exists('mute_hours', $input)) {
            $hours = $input['mute_hours'];
            $member->muted_until = match (true) {
                $hours === 0 => null,
                $hours < 0 => Carbon::create(2100),
                default => now()->addHours($hours),
            };
        }
        if (array_key_exists('marked_unread', $input)) {
            $member->marked_unread = $input['marked_unread'];
        }
        if (! empty($input['clear'])) {
            $member->cleared_at = now();
        }
        $member->save();
        $this->pulse->bump($chat, only: [$me->id]);

        return response()->json(['ok' => true]);
    }

    /** POST chat/chats/{chat}/typing — "typing…" / "recording audio…" for six seconds. */
    public function typing(Request $request, Chat $chat): JsonResponse
    {
        $this->member($chat, $request->user());
        $input = $request->validate(['state' => ['nullable', 'in:typing,recording,stop']]);
        $this->pulse->typing($chat, $request->user(), $input['state'] ?? 'typing');

        return response()->json(['ok' => true]);
    }

    /** GET chat/pulse — has anything moved? Polled every few seconds. */
    public function pulse(Request $request): JsonResponse
    {
        $me = $request->user();
        $this->pulse->seen($me->id);
        $chatIds = ChatMember::query()->where('user_id', $me->id)->whereNull('left_at')->pluck('chat_id')->all();

        return response()->json(['data' => [
            'rev' => $this->pulse->rev($me->id),
            'typing' => $this->pulse->typingIn($chatIds, $me->id),
        ]]);
    }

    // ── messages ─────────────────────────────────────────────────

    /**
     * GET chat/chats/{chat}/messages?before=&after=
     *
     * Opening a chat delivers and reads what is in it (blue ticks), as WhatsApp.
     */
    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $me = $request->user();
        $member = $this->member($chat, $me);
        $input = $request->validate([
            'before' => ['nullable', 'date'],
            'after' => ['nullable', 'date'],
            'around' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $limit = (int) ($input['limit'] ?? 60);

        $query = $this->visible($chat, $member)->with(['sender:id,name', 'replyTo.sender:id,name', 'attachments']);
        if (! empty($input['after'])) {
            $rows = $query->where('created_at', '>', $this->ts(Carbon::parse($input['after'])))->oldest('created_at')->limit($limit)->get();
        } else {
            if (! empty($input['around'])) {
                $anchor = $this->visible($chat, $member)->whereKey($input['around'])->firstOrFail();
                $input['before'] = $this->visible($chat, $member)->where('created_at', '>=', $this->ts($anchor->created_at))
                    ->oldest('created_at')->skip(20)->value('created_at')?->addMicrosecond()?->toIso8601ZuluString('microsecond');
            }
            if (! empty($input['before'])) {
                $query->where('created_at', '<', $this->ts(Carbon::parse($input['before'])));
            }
            $rows = $query->latest('created_at')->limit($limit)->get()->reverse()->values();
        }

        $latest = $chat->messages()->max('created_at');
        if ($latest !== null) {
            $member->forceFill([
                'delivered_at' => $latest,
                'read_at' => $latest,
                'marked_unread' => false,
            ])->save();
            $this->pulse->bump($chat);
        }

        $receipts = $this->receipts($chat, $me);

        return response()->json([
            'data' => $rows->map(fn (ChatMessage $m) => $this->row($m, $me, $receipts))->all(),
            'has_more' => $rows->count() === $limit,
        ]);
    }

    /**
     * POST chat/chats/{chat}/messages — text, a file (multipart `file`), or a
     * call record (kind=call, meta.status, meta.duration).
     */
    public function send(Request $request, Chat $chat, UploadLimits $limits, AttachmentUploader $uploader): JsonResponse
    {
        $me = $request->user();
        $member = $this->member($chat, $me);
        $this->mayWrite($chat, $member);

        if ($request->hasFile('file') || $request->has('file')) {
            $limits->assertUploadArrived($request, 'file');
        }

        $input = $request->validate([
            'body' => ['nullable', 'string', 'max:65000'],
            'kind' => ['nullable', 'in:text,image,video,audio,voice,file,call'],
            'reply_to_id' => ['nullable', 'uuid'],
            'meta' => ['nullable', 'array'],
            'meta.status' => ['nullable', 'in:answered,missed,declined,failed'],
            'meta.duration' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'meta.seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'file' => ['nullable', 'file',
                'mimes:'.implode(',', array_unique([...$limits->extensions(), ...self::MEDIA_EXTENSIONS])),
                'max:'.$limits->maxKilobytes()],
        ], $limits->messagesFor('file'));

        $file = $request->file('file');
        $kind = $input['kind'] ?? null;
        $body = isset($input['body']) ? trim($input['body']) : null;

        if ($file !== null) {
            $mime = (string) $file->getMimeType();
            $kind = match (true) {
                $kind === 'voice' => 'voice',
                str_starts_with($mime, 'image/') => 'image',
                str_starts_with($mime, 'video/') => 'video',
                str_starts_with($mime, 'audio/') => 'audio',
                default => 'file',
            };
        } elseif ($kind === 'call') {
            $body = null;
        } else {
            $kind = 'text';
            if ($body === null || $body === '') {
                throw ValidationException::withMessages(['body' => 'Type a message.']);
            }
        }

        $replyTo = null;
        if (! empty($input['reply_to_id'])) {
            $replyTo = $chat->messages()->whereKey($input['reply_to_id'])->first();
            abort_if($replyTo === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'That message is no longer in this chat.');
        }

        $message = DB::transaction(function () use ($chat, $me, $kind, $body, $replyTo, $input, $file, $uploader) {
            $message = ChatMessage::query()->create([
                'organization_id' => $chat->organization_id,
                'chat_id' => $chat->id,
                'sender_id' => $me->id,
                'kind' => $kind,
                'body' => $body === '' ? null : $body,
                'reply_to_id' => $replyTo?->id,
                'meta' => $kind === 'call' || $kind === 'voice'
                    ? array_filter($input['meta'] ?? [], fn ($v) => $v !== null)
                    : null,
            ]);
            if ($file !== null) {
                $uploader->upload($me, $message, $file, 'chat_messages');
            }

            return $message;
        });

        $this->delivered($chat, $message);

        return response()->json(['data' => $this->row(
            $message->load(['sender:id,name', 'replyTo.sender:id,name', 'attachments']),
            $me,
            $this->receipts($chat, $me),
        )], Response::HTTP_CREATED);
    }

    /** POST chat/messages/{chatMessage}/forward — to one or more of my chats (max 5, as WhatsApp). */
    public function forward(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        $me = $request->user();
        $this->member($chatMessage->chat, $me);
        abort_if($chatMessage->deleted_at !== null || in_array($chatMessage->kind, ['call', 'system'], true),
            Response::HTTP_UNPROCESSABLE_ENTITY, 'That message cannot be forwarded.');
        $input = $request->validate(['chat_ids' => ['required', 'array', 'min:1', 'max:5'], 'chat_ids.*' => ['uuid']]);

        foreach (array_unique($input['chat_ids']) as $id) {
            $target = Chat::query()->find($id);
            abort_if($target === null, Response::HTTP_NOT_FOUND);
            $this->mayWrite($target, $this->member($target, $me));

            $copy = DB::transaction(function () use ($target, $me, $chatMessage) {
                $copy = ChatMessage::query()->create([
                    'organization_id' => $target->organization_id,
                    'chat_id' => $target->id,
                    'sender_id' => $me->id,
                    'kind' => $chatMessage->kind,
                    'body' => $chatMessage->body,
                    'forwarded' => true,
                    'meta' => $chatMessage->kind === 'voice' ? $chatMessage->meta : null,
                ]);
                // The same bytes, a second row: each message owns its file's lifetime.
                foreach ($chatMessage->attachments as $a) {
                    $copy->attachments()->make([
                        'organization_id' => $copy->organization_id,
                        'original_name' => $a->original_name,
                        'mime' => $a->mime,
                        'uploaded_by_user_id' => $me->id,
                    ])->forceFill([
                        'disk' => $a->disk, 'path' => $a->path, 'size_bytes' => $a->size_bytes,
                        'checksum' => $a->checksum, 'encrypted' => $a->encrypted,
                    ])->save();
                }

                return $copy;
            });
            $this->delivered($target, $copy);
        }

        return response()->json(['ok' => true]);
    }

    /** PATCH chat/messages/{chatMessage} — edit my own text within 15 minutes. */
    public function edit(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        $me = $request->user();
        $this->member($chatMessage->chat, $me);
        abort_unless($chatMessage->sender_id === $me->id, Response::HTTP_FORBIDDEN, 'You can only edit your own messages.');
        abort_unless($chatMessage->kind === 'text' && $chatMessage->deleted_at === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Only a text message can be edited.');
        abort_if($chatMessage->created_at->lt(now()->subMinutes(self::EDIT_MINUTES)), Response::HTTP_UNPROCESSABLE_ENTITY,
            'A message can be edited for 15 minutes after it is sent.');

        $input = $request->validate(['body' => ['required', 'string', 'max:65000']]);
        $chatMessage->forceFill(['body' => trim($input['body']), 'edited_at' => now()])->save();
        $this->pulse->bump($chatMessage->chat);

        return response()->json(['data' => $this->row($chatMessage->load(['sender:id,name', 'replyTo.sender:id,name', 'attachments']), $me, $this->receipts($chatMessage->chat, $me))]);
    }

    /** DELETE chat/messages/{chatMessage}?everyone=1 — delete for me, or for everyone (my own, within two days). */
    public function destroy(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        $me = $request->user();
        $chat = $chatMessage->chat;
        $member = $this->member($chat, $me);

        if ($request->boolean('everyone')) {
            $mayAsAdmin = $chat->type === 'group' && $member->is_admin;
            abort_unless($chatMessage->sender_id === $me->id || $mayAsAdmin, Response::HTTP_FORBIDDEN, 'You can only delete your own messages for everyone.');
            abort_if($chatMessage->sender_id === $me->id && $chatMessage->created_at->lt(now()->subDays(2)),
                Response::HTTP_UNPROCESSABLE_ENTITY, 'A message can be deleted for everyone for two days after it is sent.');

            DB::transaction(function () use ($chatMessage, $me) {
                $chatMessage->forceFill(['body' => null, 'deleted_at' => now(), 'reactions' => null, 'meta' => ['deleted_by' => $me->id]])->save();
                $chatMessage->attachments()->get()->each(function ($a) {
                    // Bytes shared with a forwarded copy stay until the last row naming them goes.
                    $shared = \App\Models\Attachment::query()->where('path', $a->path)->whereKeyNot($a->id)->exists();
                    if (! $shared) {
                        app(\App\Support\Files\FileStore::class)->delete($a->path, $a->disk);
                    }
                    $a->delete();
                });
            });
            $this->pulse->bump($chat);
        } else {
            $hidden = $chatMessage->hidden_for ?? [];
            $chatMessage->forceFill(['hidden_for' => array_values(array_unique([...$hidden, $me->id]))])->save();
            $this->pulse->bump($chat, only: [$me->id]);
        }

        return response()->json(['ok' => true]);
    }

    /** POST chat/messages/{chatMessage}/react {emoji} — one reaction each; the same or empty removes it. */
    public function react(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        $me = $request->user();
        $this->member($chatMessage->chat, $me);
        abort_if($chatMessage->deleted_at !== null, Response::HTTP_UNPROCESSABLE_ENTITY);
        $input = $request->validate(['emoji' => ['nullable', 'string', 'max:16']]);

        DB::transaction(function () use ($chatMessage, $me, $input) {
            $row = ChatMessage::query()->whereKey($chatMessage->id)->lockForUpdate()->first();
            $reactions = $row->reactions ?? [];
            $emoji = trim((string) ($input['emoji'] ?? ''));
            if ($emoji === '' || ($reactions[$me->id] ?? null) === $emoji) {
                unset($reactions[$me->id]);
            } else {
                $reactions[$me->id] = $emoji;
            }
            $row->forceFill(['reactions' => $reactions ?: null])->save();
        });
        $this->pulse->bump($chatMessage->chat);

        return response()->json(['ok' => true]);
    }

    /** POST chat/messages/{chatMessage}/star — toggle my star. */
    public function star(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        $me = $request->user();
        $this->member($chatMessage->chat, $me);
        $stars = collect($chatMessage->starred_by ?? []);
        $starred = ! $stars->contains($me->id);
        $chatMessage->forceFill(['starred_by' => ($starred ? $stars->push($me->id) : $stars->reject(fn ($id) => $id === $me->id))->values()->all() ?: null])->save();
        $this->pulse->bump($chatMessage->chat, only: [$me->id]);

        return response()->json(['data' => ['starred' => $starred]]);
    }

    /** GET chat/messages/{chatMessage}/info — who it was delivered to and read by. */
    public function info(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        $me = $request->user();
        $this->member($chatMessage->chat, $me);
        abort_unless($chatMessage->sender_id === $me->id, Response::HTTP_FORBIDDEN, 'Message info is for messages you sent.');

        $rows = $chatMessage->chat->activeMembers()->with('user:id,name')->where('user_id', '!=', $me->id)->get()
            ->map(fn (ChatMember $m) => [
                'id' => $m->user_id,
                'name' => $m->user?->name,
                'read' => $m->read_at !== null && $m->read_at->gte($chatMessage->created_at),
                'delivered' => $m->delivered_at !== null && $m->delivered_at->gte($chatMessage->created_at),
                // When this member last caught up, which is when they had this message at the latest.
                'read_at' => $m->read_at !== null && $m->read_at->gte($chatMessage->created_at) ? $m->read_at->toIso8601String() : null,
                'delivered_at' => $m->delivered_at !== null && $m->delivered_at->gte($chatMessage->created_at) ? $m->delivered_at->toIso8601String() : null,
            ]);

        return response()->json(['data' => $rows->values()]);
    }

    /** GET chat/starred — my starred messages across every chat. */
    public function starred(Request $request): JsonResponse
    {
        return $this->across($request, fn ($q) => $q->whereJsonContains('chat_messages.starred_by', $request->user()->id));
    }

    /** GET chat/search?q= — messages across every chat I am in. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $like = '%'.addcslashes($q, '%_\\').'%';

        return $this->across($request, fn ($query) => $query->where('chat_messages.body', 'like', $like));
    }

    /** GET chat/chats/{chat}/media?kind=media|docs — the chat's photos, videos and documents. */
    public function media(Request $request, Chat $chat): JsonResponse
    {
        $me = $request->user();
        $member = $this->member($chat, $me);
        $kinds = $request->query('kind') === 'docs' ? ['file', 'audio'] : ['image', 'video'];
        $rows = $this->visible($chat, $member)->whereIn('kind', $kinds)->with(['sender:id,name', 'attachments'])
            ->latest('created_at')->limit(200)->get();
        $receipts = $this->receipts($chat, $me);

        return response()->json(['data' => $rows->map(fn ($m) => $this->row($m, $me, $receipts))->values()]);
    }

    // ── helpers ──────────────────────────────────────────────────

    /** @param callable(\Illuminate\Database\Eloquent\Builder): void $narrow */
    private function across(Request $request, callable $narrow): JsonResponse
    {
        $me = $request->user();
        $members = ChatMember::query()->where('user_id', $me->id)->whereNull('left_at')->get()->keyBy('chat_id');

        $query = ChatMessage::query()
            ->whereIn('chat_id', $members->keys())
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('hidden_for')->orWhereJsonDoesntContain('hidden_for', $me->id))
            ->with(['sender:id,name', 'attachments', 'chat.activeMembers.user:id,name']);
        $narrow($query);

        $rows = $query->latest('created_at')->limit(100)->get()
            ->filter(fn (ChatMessage $m) => $members[$m->chat_id]->cleared_at === null || $m->created_at->gt($members[$m->chat_id]->cleared_at))
            ->map(fn (ChatMessage $m) => [
                ...$this->row($m, $me, []),
                'chat' => $this->chatRow($m->chat, $me),
            ]);

        return response()->json(['data' => $rows->values()]);
    }

    /** What this member can see in a chat: after their clear, not hidden by them, and not before they joined a group. */
    private function visible(Chat $chat, ChatMember $member): \Illuminate\Database\Eloquent\Builder
    {
        $q = ChatMessage::query()->where('chat_id', $chat->id)
            ->where(fn ($q) => $q->whereNull('hidden_for')->orWhereJsonDoesntContain('hidden_for', $member->user_id));
        if ($member->cleared_at !== null) {
            $q->where('created_at', '>', $this->ts($member->cleared_at));
        }

        return $q;
    }

    /** A moment as the query builder must see it: its default format drops microseconds. */
    private function ts(Carbon|\DateTimeInterface $at): string
    {
        return $at->format('Y-m-d H:i:s.u');
    }

    private function member(Chat $chat, User $user): ChatMember
    {
        $member = $chat->members()->where('user_id', $user->id)->whereNull('left_at')->first();
        abort_if($member === null, Response::HTTP_NOT_FOUND);

        return $member;
    }

    private function adminOfGroup(Chat $chat, User $me): ChatMember
    {
        $member = $this->member($chat, $me);
        abort_unless($chat->type === 'group', Response::HTTP_UNPROCESSABLE_ENTITY, 'A direct chat has no members to manage.');
        abort_unless($member->is_admin, Response::HTTP_FORBIDDEN, 'Only group admins can do that.');

        return $member;
    }

    private function mayWrite(Chat $chat, ChatMember $member): void
    {
        abort_if($chat->type === 'group' && $chat->only_admins_send && ! $member->is_admin,
            Response::HTTP_FORBIDDEN, 'Only admins can send messages to this group.');
    }

    /** An active colleague in this organization who can use Chat; 404 otherwise. */
    private function colleague(string $id): User
    {
        $user = User::query()->whereKey($id)->where('is_active', true)->first();
        abort_if($user === null || ! $user->hasPermission('phone.chat.view'), Response::HTTP_NOT_FOUND, 'That person cannot use Chat.');

        return $user;
    }

    private function system(Chat $chat, User $by, string $event, array $meta = []): void
    {
        ChatMessage::query()->create([
            'organization_id' => $chat->organization_id,
            'chat_id' => $chat->id,
            'sender_id' => $by->id,
            'kind' => 'system',
            'meta' => ['event' => $event, ...$meta],
        ]);
        $chat->forceFill(['last_message_at' => now()])->save();
    }

    /** After a new message: touch the chat, unarchive it for others, move everyone's pulse, push the absent. */
    private function delivered(Chat $chat, ChatMessage $message): void
    {
        $chat->forceFill(['last_message_at' => $message->created_at])->save();
        // The sender has read their own message; nobody else has yet.
        $chat->members()->where('user_id', $message->sender_id)->update(['read_at' => $message->created_at->format('Y-m-d H:i:s.u'), 'delivered_at' => $message->created_at->format('Y-m-d H:i:s.u')]);
        // A new message brings an archived chat back for everyone not muting it, as WhatsApp.
        $chat->members()->whereNotNull('archived_at')->where(fn ($q) => $q->whereNull('muted_until')->orWhere('muted_until', '<', now()))->update(['archived_at' => null]);
        $this->pulse->bump($chat);

        $recipients = $chat->activeMembers()->with('user:id,name')->where('user_id', '!=', $message->sender_id)->get()
            ->reject(fn (ChatMember $m) => $m->isMuted());
        $absent = array_diff($recipients->pluck('user_id')->all(), $this->pulse->online($recipients->pluck('user_id')->all()));
        if ($absent === []) {
            return;
        }

        $sender = $message->sender?->name ?? 'Someone';
        $title = $chat->type === 'group' ? (string) $chat->name : $sender;
        $line = ($chat->type === 'group' ? $sender.': ' : '').$this->preview($message);
        $organization = $chat->organization_id;

        dispatch(function () use ($absent, $title, $line, $organization) {
            $push = app(PushSender::class);
            $org = \App\Models\Organization::query()->find($organization);
            if (! $push->configured() || $org === null || ! app(SettingsResolver::class)->get('notifications.push_enabled', $org)) {
                return;
            }
            DeviceToken::query()->withoutGlobalScopes()->whereIn('user_id', $absent)->get()
                ->groupBy('user_id')
                ->each(fn (Collection $devices) => $push->send($devices, $title, $line));
        })->afterResponse();
    }

    /**
     * Other members' receipts: the earliest anyone has read / been delivered to.
     * A message is read (blue) when everyone else has read it, as WhatsApp groups.
     *
     * @return array{read: ?Carbon, delivered: ?Carbon}
     */
    private function receipts(Chat $chat, User $me): array
    {
        $others = $chat->activeMembers()->where('user_id', '!=', $me->id)->get(['read_at', 'delivered_at']);
        if ($others->isEmpty()) {
            return ['read' => null, 'delivered' => null];
        }

        return [
            'read' => $others->contains(fn ($m) => $m->read_at === null) ? null : $others->min('read_at'),
            'delivered' => $others->contains(fn ($m) => $m->delivered_at === null) ? null : $others->min('delivered_at'),
        ];
    }

    private function chatRow(Chat $chat, User $me): array
    {
        $members = $chat->relationLoaded('activeMembers') ? $chat->activeMembers : $chat->activeMembers()->with('user:id,name')->get();
        $other = $chat->type === 'direct' ? $members->firstWhere('user_id', '!=', $me->id) : null;

        return [
            'id' => $chat->id,
            'type' => $chat->type,
            'name' => $chat->type === 'direct' ? ($other?->user?->name ?? 'Former colleague') : $chat->name,
            'user_id' => $other?->user_id,
            'member_count' => $members->count(),
            'member_names' => $chat->type === 'group' ? $members->map(fn ($m) => $m->user_id === $me->id ? 'You' : $m->user?->name)->filter()->values() : [],
            'online' => $other ? in_array($other->user_id, $this->pulse->online([$other->user_id]), true) : false,
            'last_seen' => $other ? $this->pulse->lastSeen($other->user_id) : null,
        ];
    }

    /** @param array{read?: ?Carbon, delivered?: ?Carbon} $receipts */
    private function row(ChatMessage $m, User $me, array $receipts): array
    {
        $mine = $m->sender_id === $me->id;
        $attachment = $m->attachments->first();
        $reactions = collect($m->reactions ?? []);

        return [
            'id' => $m->id,
            'chat_id' => $m->chat_id,
            'kind' => $m->kind,
            'body' => $m->deleted_at ? null : $m->body,
            'mine' => $mine,
            'sender_id' => $m->sender_id,
            'sender_name' => $m->sender?->name,
            'sent_at' => $m->created_at?->toIso8601ZuluString('microsecond'),
            'edited' => $m->edited_at !== null,
            'deleted' => $m->deleted_at !== null,
            'forwarded' => $m->forwarded,
            'meta' => $m->meta,
            'starred' => in_array($me->id, $m->starred_by ?? [], true),
            'reactions' => $reactions->groupBy(fn ($e) => $e, preserveKeys: true)->map(fn ($who, $emoji) => [
                'emoji' => $emoji,
                'count' => $who->count(),
                'mine' => ($reactions[$me->id] ?? null) === $emoji,
                'user_ids' => $who->keys()->values(),
            ])->values(),
            'status' => ! $mine || in_array($m->kind, ['system', 'call'], true) ? null : match (true) {
                ($receipts['read'] ?? null) !== null && $receipts['read']->gte($m->created_at) => 'read',
                ($receipts['delivered'] ?? null) !== null && $receipts['delivered']->gte($m->created_at) => 'delivered',
                default => 'sent',
            },
            'reply_to' => $m->replyTo ? [
                'id' => $m->replyTo->id,
                'sender_name' => $m->replyTo->sender_id === $me->id ? 'You' : $m->replyTo->sender?->name,
                'kind' => $m->replyTo->kind,
                'body' => $m->replyTo->deleted_at ? null : mb_substr((string) $m->replyTo->body, 0, 200),
            ] : null,
            'attachment' => $attachment && ! $m->deleted_at ? [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'mime' => $attachment->mime,
                'size' => $attachment->size_bytes,
            ] : null,
        ];
    }

    private function snippet(ChatMessage $m, User $me, Chat $chat): array
    {
        return [
            'id' => $m->id,
            'kind' => $m->kind,
            'text' => $this->preview($m),
            'mine' => $m->sender_id === $me->id,
            'sender_name' => $m->sender_id === $me->id ? 'You' : $m->sender?->name,
            'sent_at' => $m->created_at?->toIso8601ZuluString('microsecond'),
            'deleted' => $m->deleted_at !== null,
            'status' => $m->sender_id === $me->id && ! in_array($m->kind, ['system', 'call'], true)
                ? $this->row($m, $me, $this->receipts($chat, $me))['status'] : null,
        ];
    }

    private function preview(ChatMessage $m): string
    {
        if ($m->deleted_at !== null) {
            return 'This message was deleted';
        }

        return match ($m->kind) {
            'image' => '📷 '.($m->body ?: 'Photo'),
            'video' => '🎥 '.($m->body ?: 'Video'),
            'voice' => '🎤 Voice message',
            'audio' => '🎵 '.($m->body ?: 'Audio'),
            'file' => '📄 '.($m->body ?: ($m->attachments->first()?->original_name ?? 'Document')),
            'call' => ($m->meta['status'] ?? '') === 'missed' ? '📞 Missed voice call' : '📞 Voice call',
            'system' => $this->systemLine($m),
            default => mb_substr((string) $m->body, 0, 200),
        };
    }

    private function systemLine(ChatMessage $m): string
    {
        $who = $m->sender?->name ?? 'Someone';
        $meta = $m->meta ?? [];

        return match ($meta['event'] ?? '') {
            'created' => "{$who} created group \"".($meta['name'] ?? '').'"',
            'renamed' => "{$who} changed the group name to \"".($meta['name'] ?? '').'"',
            'described' => "{$who} changed the group description",
            'added' => "{$who} added ".($meta['name'] ?? 'someone'),
            'removed' => "{$who} removed ".($meta['name'] ?? 'someone'),
            'left' => "{$who} left",
            default => '',
        };
    }
}
