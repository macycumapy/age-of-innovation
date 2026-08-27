<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BridgeStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Models\Game;
use App\Models\GamePlayer;
use BackedEnum;
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
            'board' => [
                'variant' => $this->state->board->variant->value,
                'riverBankHexIds' => $this->state->board->riverBankHexIds,
                'edgeHexIds' => $this->state->board->edgeHexIds,
                'hexes' => array_map(
                    static fn (BoardHexStateData $hex): array => [
                        'id' => $hex->id,
                        'q' => $hex->q,
                        'r' => $hex->r,
                        'initialTerrain' => $hex->initialTerrain->value,
                        'terrain' => $hex->terrain->value,
                    ],
                    $this->state->board->hexes,
                ),
            ],
            'canStart' => $playersLoaded
                && $this->status === GameStatus::Lobby
                && $this->players->count() >= 2
                && $this->players->every(
                    static fn (GamePlayer $player): bool => $player->is_ready,
                ),
            'players' => GamePlayerResource::collection($this->whenLoaded('players')),
            'playerBoardStates' => array_map(
                fn (GamePlayerStateData $player): array => [
                    'playerId' => $player->playerId,
                    'victoryPoints' => $player->victoryPoints,
                    'roundBonus' => $player->roundBonus->value,
                    'scholars' => $player->resources->scholars,
                    'coins' => $player->resources->coins,
                    'tools' => $player->resources->tools,
                    'books' => [
                        'banking' => $player->resources->books->banking,
                        'law' => $player->resources->books->law,
                        'engineering' => $player->resources->books->engineering,
                        'medicine' => $player->resources->books->medicine,
                        'unassigned' => $player->resources->books->unassigned,
                    ],
                    'availableBridges' => max(
                        0,
                        3 - count(array_filter(
                            $this->state->board->bridges,
                            static fn (BridgeStateData $bridge): bool => $bridge->ownerPlayerId === $player->playerId,
                        )),
                    ),
                    'competencyIds' => $player->competencyIds,
                    'activeTownKeys' => max(
                        0,
                        count($player->townTileIds)
                            - count($player->knowledge->unlockedDisciplines),
                    ),
                    'activeAnnexes' => count(array_filter(
                        $this->state->board->hexes,
                        static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                            && $hex->building->hasAnnex,
                    )),
                    'income' => [
                        'tools' => 0,
                        'coins' => 0,
                        'scholars' => 0,
                        'power' => 0,
                    ],
                    'shippingLevel' => $player->shippingLevel,
                    'terraformingLevel' => $player->terraformingLevel,
                    'unassignedSpades' => $player->unassignedSpades,
                    'knowledge' => [
                        'banking' => $player->knowledge->banking,
                        'law' => $player->knowledge->law,
                        'engineering' => $player->knowledge->engineering,
                        'medicine' => $player->knowledge->medicine,
                    ],
                    'power' => [
                        'bowlOne' => $player->resources->power->bowlOne,
                        'bowlTwo' => $player->resources->power->bowlTwo,
                        'bowlThree' => $player->resources->power->bowlThree,
                    ],
                ],
                $this->state->players,
            ),
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
            'planningBundleDescriptions' => [
                'homelands' => $this->enumDescriptions(
                    TerrainType::cases(),
                    static fn (TerrainType $terrain): string => $terrain->description(),
                ),
                'factions' => $this->enumDescriptions(
                    Faction::cases(),
                    static fn (Faction $faction): string => $faction->description(),
                ),
                'roundBonuses' => $this->enumDescriptions(
                    RoundBonus::cases(),
                    static fn (RoundBonus $roundBonus): string => $roundBonus->description(),
                ),
            ],
            'competencyDescriptions' => $this->enumDescriptions(
                Competency::cases(),
                static fn (Competency $competency): string => $competency->description(),
            ),
            'innovationDescriptions' => $this->enumDescriptions(
                Innovation::cases(),
                static fn (Innovation $innovation): string => $innovation->description(),
            ),
            'roundBonusDescriptions' => $this->enumDescriptions(
                RoundBonus::cases(),
                static fn (RoundBonus $roundBonus): string => $roundBonus->description(),
            ),
            'roundScoringTiles' => $this->enumValues(
                $this->state->setupPool?->roundScoringTiles ?? [],
            ),
            'finalRoundScoringTile' => $this->enumValue(
                $this->state->setupPool?->additionalFinalRoundGoal,
            ),
            'bookActions' => $this->enumValues(
                $this->state->setupPool?->bookActions ?? [],
            ),
            'innovations' => $this->enumValues(
                $this->state->setupPool?->innovations ?? [],
            ),
            'competencies' => $this->enumValues(
                $this->state->setupPool?->competencies ?? [],
            ),
            'availablePalaceIds' => $this->state->availablePalaceIds,
            'availableTownTileIds' => $this->state->availableTownTileIds,
            'roundBonusOffers' => array_map(
                static fn (RoundBonusOfferData $offer): array => [
                    'roundBonus' => $offer->roundBonus->value,
                    'coins' => $offer->coins,
                ],
                $this->state->setupPool?->availableRoundBonuses ?? [],
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

    /**
     * @param list<BackedEnum|string> $values
     * @return list<string>
     */
    private function enumValues(array $values): array
    {
        return array_map(
            static fn (BackedEnum|string $value): string => $value instanceof BackedEnum
                ? (string) $value->value
                : $value,
            $values,
        );
    }

    private function enumValue(BackedEnum|string|null $value): ?string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }

    /**
     * @template T of BackedEnum
     * @param list<T> $values
     * @param callable(T): string $description
     * @return array<string, string>
     */
    private function enumDescriptions(array $values, callable $description): array
    {
        $descriptions = [];

        foreach ($values as $value) {
            $descriptions[(string) $value->value] = $description($value);
        }

        return $descriptions;
    }
}
