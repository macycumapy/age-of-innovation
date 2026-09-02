<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChoosePalaceAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    public function execute(Game $game, User $user, PalaceAbility $palace): Game
    {
        return DB::transaction(function () use ($game, $user, $palace): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()
                ->whereKey($interaction?->playerId)
                ->whereBelongsTo($user)
                ->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChoosePalace
                || ! $player instanceof GamePlayer
                || ! in_array($palace->value, $interaction->optionIds, true)) {
                throw ValidationException::withMessages([
                    'palace_id' => 'Этот жетон Дворца недоступен.',
                ]);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData || $playerState->palaceId !== null) {
                throw ValidationException::withMessages([
                    'palace_id' => 'Игрок не может выбрать этот жетон Дворца.',
                ]);
            }

            $stateVersionBefore = $lockedGame->version;
            $builtHexId = (string) ($interaction->context['builtHexId'] ?? '');
            $victoryPoints = $palace->buildingVictoryPoints(BuildingType::Palace);
            $playerState->palaceId = $palace->value;
            $playerState->victoryPoints += $victoryPoints;
            $state->availablePalaceIds = array_values(array_filter(
                $state->availablePalaceIds,
                static fn (string $palaceId): bool => $palaceId !== $palace->value,
            ));
            $state->pendingInteraction = null;

            if ($palace === PalaceAbility::Palace16) {
                $eligibleHexIds = array_values(array_map(
                    static fn (BoardHexStateData $hex): string => $hex->id,
                    array_filter(
                        $state->board->hexes,
                        static fn (BoardHexStateData $hex): bool => $hex->terrain === $playerState->homeland
                            && $hex->building === null,
                    ),
                ));
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::PlacePalaceGuild,
                    $player->id,
                    $eligibleHexIds,
                    [
                        'palaceBuiltHexId' => $builtHexId,
                        'selectedHexId' => null,
                    ],
                );
                $nextActiveUserId = $player->user_id;
            } else {
                $nextActiveUserId = $this->createTownChoiceAfterBuilding->execute(
                    $state,
                    $playerState,
                    $builtHexId,
                );
            }

            $lockedGame->update([
                'active_player_id' => $nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ChoosePalace,
                [
                    'palace_id' => $palace->value,
                    'built_hex_id' => $builtHexId,
                    'victory_points' => $victoryPoints,
                ],
                [[
                    'type' => 'palace_chosen',
                    'player_id' => $player->id,
                    'palace_id' => $palace->value,
                    'built_hex_id' => $builtHexId,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
