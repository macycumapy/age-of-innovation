<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Game */
class GameResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $playersLoaded = $this->relationLoaded('players');
        $owner = $playersLoaded ? $this->players->firstWhere('seat', 1) : null;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'mapVariant' => $this->state->board->variant->value,
            'maxPlayers' => $this->state->board->variant->maxPlayers(),
            'playersCount' => (int) $this->getAttribute('players_count'),
            'isJoined' => (bool) $this->getAttribute('is_joined'),
            'isOwner' => $owner?->user_id === $request->user()?->id,
            'activePlayerId' => $this->active_player_id,
            'turnOrder' => $this->state->turnOrder,
            'canStart' => $playersLoaded
                && $this->status === GameStatus::Lobby
                && $this->players->count() >= 2
                && $this->players->every(
                    static fn (GamePlayer $player): bool => $player->is_ready,
                ),
            'players' => GamePlayerResource::collection($this->whenLoaded('players')),
            'planningBundles' => array_map(
                static fn (PlanningBundleData $bundle): array => [
                    'homeland' => $bundle->homeland->value,
                    'faction' => $bundle->faction->value,
                    'roundBonus' => $bundle->roundBonus->value,
                ],
                $this->state->setupPool?->planningBundles ?? [],
            ),
            'planningSelections' => array_map(
                static fn (PlayerPlanningSelectionData $selection): array => [
                    'playerId' => $selection->playerId,
                    'bundle' => [
                        'homeland' => $selection->bundle->homeland->value,
                        'faction' => $selection->bundle->faction->value,
                        'roundBonus' => $selection->bundle->roundBonus->value,
                    ],
                ],
                $this->state->planningSelections,
            ),
            'pendingInteraction' => $this->state->pendingInteraction === null ? null : [
                'type' => $this->state->pendingInteraction->type->value,
                'playerId' => $this->state->pendingInteraction->playerId,
                'optionIds' => $this->state->pendingInteraction->optionIds,
                'context' => $this->state->pendingInteraction->context,
            ],
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
