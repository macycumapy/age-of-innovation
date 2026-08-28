<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Game\Enums\MapVariant;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Game */
final class GameSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $mapVariant = $this->mapVariant();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'mapVariant' => $mapVariant->value,
            'maxPlayers' => $mapVariant->maxPlayers(),
            'playersCount' => (int) $this->getAttribute('players_count'),
            'isJoined' => (bool) $this->getAttribute('is_joined'),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    private function mapVariant(): MapVariant
    {
        $variant = $this->getAttribute('map_variant');

        if (is_string($variant)) {
            return MapVariant::tryFrom($variant) ?? MapVariant::ThreeToFivePlayers;
        }

        return MapVariant::ThreeToFivePlayers;
    }
}
