<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Chat\Data\ChatMessageData;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChatMessage */
final class ChatMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ChatMessageData::fromMessage($this->resource)->toArray();
    }
}
