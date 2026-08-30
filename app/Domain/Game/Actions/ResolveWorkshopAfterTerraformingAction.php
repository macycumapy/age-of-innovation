<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolveWorkshopAfterTerraformingAction
{
    public function __construct(private AppendGameHistoryAction $appendGameHistory)
    {
    }

    public function execute(Game $game, User $user, bool $build, ?string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $build, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || ! $player instanceof GamePlayer
                || $interaction?->type !== PendingInteractionType::BuildWorkshopAfterTerraforming
                || $interaction->playerId !== $player->id
                || ($build && (! is_string($hexId) || ! in_array($hexId, $interaction->optionIds, true)))) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя подтвердить строительство дома.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            if ($build) {
                $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
                $workshopsOnMap = count(array_filter(
                    $state->board->hexes,
                    static fn (BoardHexStateData $boardHex): bool => $boardHex->building?->ownerPlayerId === $player->id
                        && $boardHex->building->type === BuildingType::Workshop,
                ));

                if (! $hex instanceof BoardHexStateData
                    || $hex->building !== null
                    || $hex->terrain !== $playerState->homeland
                    || $playerState->resources->tools < 1
                    || $playerState->resources->coins < 2
                    || $workshopsOnMap >= 9) {
                    throw ValidationException::withMessages(['game' => 'Дом нельзя построить на выбранной клетке.']);
                }

                $playerState->resources->tools--;
                $playerState->resources->coins -= 2;
                $hex->building = new BuildingStateData(BuildingType::Workshop, $player->id);
            }

            $stateVersionBefore = $lockedGame->version;
            $state->pendingInteraction = null;
            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::TerraformAndBuild,
                ['built' => $build, 'hex_id' => $build ? $hexId : null],
                [[
                    'type' => $build ? 'workshop_built_after_terraforming' : 'workshop_declined_after_terraforming',
                    'player_id' => $player->id,
                    'hex_id' => $build ? $hexId : null,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
