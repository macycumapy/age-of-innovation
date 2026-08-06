<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GamePlayer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GamePlayer */
class GamePlayerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seat' => $this->seat,
            'isReady' => $this->is_ready,
            'faction' => $this->faction?->value,
            'homeland' => $this->homeland?->value,
            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
