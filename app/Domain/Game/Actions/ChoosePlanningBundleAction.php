<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GamePlayerStateFactory;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChoosePlanningBundleAction
{
    public function __construct(private GamePlayerStateFactory $playerStateFactory)
    {
    }

    public function execute(Game $game, User $user, TerrainType $homeland): Game
    {
        return DB::transaction(function () use ($game, $user, $homeland): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->status !== GameStatus::Active
                || $lockedGame->phase !== GamePhase::Setup
                || $lockedGame->state->setupPool === null) {
                throw ValidationException::withMessages([
                    'game' => 'Выбор стартового комплекта сейчас недоступен.',
                ]);
            }

            if ($lockedGame->active_player_id !== $user->id) {
                throw ValidationException::withMessages([
                    'game' => 'Сейчас стартовый комплект выбирает другой игрок.',
                ]);
            }

            $player = $lockedGame->players()->whereBelongsTo($user)->firstOrFail();

            if ($player->faction !== null) {
                throw ValidationException::withMessages([
                    'game' => 'Стартовый комплект уже выбран.',
                ]);
            }

            $setupPool = $lockedGame->state->setupPool;
            $bundle = collect($setupPool->planningBundles)
                ->first(
                    static fn (PlanningBundleData $bundle): bool => $bundle->homeland === $homeland,
                );

            if ($bundle === null) {
                throw ValidationException::withMessages([
                    'homeland' => 'Этот стартовый комплект уже недоступен.',
                ]);
            }

            $playerState = $this->playerStateFactory->create($player, $bundle);

            $player->update([
                'color' => $playerState->color,
                'faction' => $bundle->faction,
                'homeland' => $bundle->homeland,
            ]);

            $setupPool->planningBundles = array_values(array_filter(
                $setupPool->planningBundles,
                static fn (PlanningBundleData $candidate): bool => $candidate->homeland !== $bundle->homeland,
            ));

            $state = $lockedGame->state;
            $state->setupPool = $setupPool;
            $state->planningSelections = [
                ...$state->planningSelections,
                new PlayerPlanningSelectionData($player->id, $bundle),
            ];
            $state->players = [...$state->players, $playerState];
            $nextPlayer = $this->nextPlayer($lockedGame, $player);

            $lockedGame->update([
                'active_player_id' => $nextPlayer->user_id,
                'version' => $lockedGame->version + 1,
                'state' => $state,
            ]);

            return $lockedGame->refresh();
        });
    }

    private function nextPlayer(Game $game, GamePlayer $currentPlayer): GamePlayer
    {
        $playersById = $game->players()->get()->keyBy('id');
        $turnOrder = $game->state->turnOrder;
        $currentIndex = array_search($currentPlayer->id, $turnOrder, true);

        if ($currentIndex === false) {
            throw ValidationException::withMessages([
                'game' => 'Нарушен порядок игроков в состоянии партии.',
            ]);
        }

        foreach (range(1, count($turnOrder)) as $offset) {
            $playerId = $turnOrder[($currentIndex + $offset) % count($turnOrder)];
            $candidate = $playersById->get($playerId);

            if ($candidate instanceof GamePlayer && $candidate->faction === null) {
                return $candidate;
            }
        }

        $firstPlayer = $playersById->get($turnOrder[0]);

        if (! $firstPlayer instanceof GamePlayer) {
            throw ValidationException::withMessages([
                'game' => 'Не найден первый игрок партии.',
            ]);
        }

        return $firstPlayer;
    }
}
