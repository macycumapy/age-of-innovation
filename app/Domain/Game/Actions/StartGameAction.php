<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartGameAction
{
    public function __construct(private GameSetupPoolFactory $setupPoolFactory)
    {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->status !== GameStatus::Lobby) {
                throw ValidationException::withMessages([
                    'game' => 'Игра уже началась.',
                ]);
            }

            /** @var Collection<int, GamePlayer> $players */
            $players = $lockedGame->players()->orderBy('seat')->get();
            $owner = $players->firstWhere('seat', 1);

            if ($owner === null || $owner->user_id !== $user->id) {
                throw new AuthorizationException('Начать игру может только её создатель.');
            }

            if ($players->count() < 2) {
                throw ValidationException::withMessages([
                    'game' => 'Для старта нужны как минимум два игрока.',
                ]);
            }

            if ($players->contains(
                static fn (GamePlayer $player): bool => ! $player->is_ready,
            )) {
                throw ValidationException::withMessages([
                    'game' => 'Все игроки должны подтвердить готовность.',
                ]);
            }

            $setupPool = $this->setupPoolFactory->createFromSeed(
                playerCount: $players->count(),
                seed: $lockedGame->random_seed,
                mapVariant: $lockedGame->state->board->variant,
            );
            $orderedPlayers = $players
                ->slice($setupPool->firstPlayerIndex)
                ->concat($players->take($setupPool->firstPlayerIndex))
                ->values();
            $activePlayer = $orderedPlayers->firstOrFail();

            $lockedGame->update([
                'status' => GameStatus::Active,
                'phase' => GamePhase::Setup,
                'active_player_id' => $activePlayer->user_id,
                'version' => $lockedGame->version + 1,
                'state' => new GameStateData(
                    schemaVersion: 2,
                    turnOrder: $orderedPlayers->pluck('id')->all(),
                    board: $lockedGame->state->board,
                    round: new RoundStateData(
                        number: 1,
                        phase: GamePhase::Setup,
                        scoringTileId: $setupPool->roundScoringTiles[0]->value,
                        additionalScoringTileId: $setupPool->additionalFinalRoundGoal->value,
                    ),
                    availableTownTileIds: $this->enumValues($setupPool->townTiles),
                    availablePalaceIds: $this->enumValues($setupPool->palaces),
                    availableInventionIds: $this->enumValues($setupPool->innovations),
                    availableCompetencyIds: $this->enumValues($setupPool->competencies),
                    roundBonusIds: [
                        ...array_map(
                            static fn (PlanningBundleData $bundle): string => $bundle->roundBonus->value,
                            $setupPool->planningBundles,
                        ),
                        ...array_map(
                            static fn (RoundBonusOfferData $offer): string => $offer->roundBonus->value,
                            $setupPool->availableRoundBonuses,
                        ),
                    ],
                    setupPool: $setupPool,
                ),
                'started_at' => now(),
            ]);

            return $lockedGame->refresh();
        });
    }

    /**
     * @param list<\BackedEnum> $cases
     * @return list<string>
     */
    private function enumValues(array $cases): array
    {
        return array_map(
            static fn (\BackedEnum $case): string => (string) $case->value,
            $cases,
        );
    }
}
