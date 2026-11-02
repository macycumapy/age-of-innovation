<?php

declare(strict_types=1);

namespace App\Domain\Chat\Actions;

use App\Domain\Chat\Data\ChatMessageData;
use App\Domain\Chat\Data\SentChatMessageData;
use App\Events\ChatMessageCreated;
use App\Models\ChatMessage;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SendChatMessageAction
{
    public function execute(Game $game, User $user, string $clientMessageId, string $body): SentChatMessageData
    {
        return DB::transaction(function () use ($game, $user, $clientMessageId, $body): SentChatMessageData {
            $lockedGame = Game::query()->select('id')->lockForUpdate()->findOrFail($game->id);

            Gate::forUser($user)->authorize('access-game-chat', $lockedGame);

            $message = ChatMessage::query()->firstOrCreate([
                'game_id' => $lockedGame->id,
                'user_id' => $user->id,
                'client_message_id' => $clientMessageId,
            ], [
                'body' => $body,
            ]);

            if ($message->body !== $body) {
                throw ValidationException::withMessages([
                    'client_message_id' => 'Этот идентификатор уже использован для другого сообщения.',
                ]);
            }

            $message->setRelation('user', $user);

            if ($message->wasRecentlyCreated) {
                ChatMessageCreated::dispatch(ChatMessageData::fromMessage($message));
            }

            return new SentChatMessageData($message, $message->wasRecentlyCreated);
        });
    }
}
