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
        return $user->hasPermission('phone.chat.view')
            && ChatMember::query()
                ->where('chat_id', $message->chat_id)
                ->where('user_id', $user->getKey())
                ->exists();
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
