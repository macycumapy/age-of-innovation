<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Game */
class GameResource extends JsonResource
{
    /** @return array<string, int|string|null> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'mapVariant' => $this->state->board->variant->value,
            'playersCount' => (int) $this->getAttribute('players_count'),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
