<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Game */
class GameResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'mapVariant' => $this->state->board->variant->value,
            'maxPlayers' => $this->state->board->variant->maxPlayers(),
            'playersCount' => (int) $this->getAttribute('players_count'),
            'isJoined' => (bool) $this->getAttribute('is_joined'),
            'players' => GamePlayerResource::collection($this->whenLoaded('players')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
