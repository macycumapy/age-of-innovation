<?php

declare(strict_types=1);

namespace App\Domain\Chat\Data;

use App\Models\ChatMessage;

final readonly class SentChatMessageData
{
    public function __construct(public ChatMessage $message, public bool $created)
    {
    }
}
