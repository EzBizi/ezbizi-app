<?php

namespace App\Policies;

use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\User;

/**
 * A chat message's files are for the people in the chat. Asked by
 * GET /attachments/{id}. Uploads go through ChatController, never
 * POST /attachments, so `update` and `delete` stay closed.
 */
class ChatMessagePolicy
{
    public function view(User $user, ChatMessage $message): bool
    {
        if (! $user->hasPermission('phone.chat.view')) {
            return false;
        }
        // The same reach as reading the chat: a current member (not one who
        // left or was removed), and not a message cleared or deleted for me.
        $member = ChatMember::query()
            ->where('chat_id', $message->chat_id)
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->first();
        if ($member === null || in_array($user->getKey(), (array) ($message->hidden_for ?? []), true)) {
            return false;
        }

        return $member->cleared_at === null || $message->created_at->gt($member->cleared_at);
    }

    public function update(User $user, ChatMessage $message): bool
    {
        return false;
    }

    public function delete(User $user, ChatMessage $message): bool
    {
        return false;
    }
}
