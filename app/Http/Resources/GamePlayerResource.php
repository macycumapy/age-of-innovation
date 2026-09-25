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
            'botDifficulty' => $this->bot_difficulty?->value,
            'color' => $this->color?->value,
            'faction' => $this->faction?->value,
            'homeland' => $this->homeland?->value,
            'user_id' => $this->user_id,
            'name' => $this->whenLoaded('user', fn () => $this->user->name) ?? $this->botName(),
        ];
    }

    private function botName(): ?string
    {
        $botDifficulty = $this->bot_difficulty?->title();
        if ($botDifficulty) {
            return "Бот ($botDifficulty)";
        }

        return null;
    }
}
