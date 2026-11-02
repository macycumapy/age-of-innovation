<?php

declare(strict_types=1);

namespace App\Domain\Chat\Data;

use App\Models\ChatMessage;
use Spatie\LaravelData\Data;

final class ChatMessageData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $gameId,
        public readonly ?int $authorId,
        public readonly string $authorName,
        public readonly string $clientMessageId,
        public readonly string $body,
        public readonly string $createdAt,
    ) {
    }

    public static function fromMessage(ChatMessage $message): self
    {
        return new self(
            id: $message->id,
            gameId: $message->game_id,
            authorId: $message->user_id,
            authorName: $message->user->name ?? 'Удалённый пользователь',
            clientMessageId: $message->client_message_id,
            body: $message->body,
            createdAt: $message->created_at->toISOString(),
        );
    }
}
