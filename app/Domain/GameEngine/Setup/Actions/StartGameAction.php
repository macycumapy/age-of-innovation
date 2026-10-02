<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Actions;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusOfferData;
use App\Domain\GameEngine\Research\Services\CompetencySupply;
use App\Domain\GameEngine\Setup\Data\PlanningBundleData;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartGameAction
{
    public function __construct(
        private GameSetupPoolFactory $setupPoolFactory,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $stateVersionBefore = $lockedGame->version;

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
                'active_game_player_id' => $activePlayer->id,
                'version' => $lockedGame->version + 1,
                'state' => new GameStateData(
                    schemaVersion: CompetencySupply::CURRENT_SCHEMA_VERSION,
                    turnOrder: $orderedPlayers->pluck('id')->all(),
                    board: $lockedGame->state->board,
                    round: new RoundStateData(
                        number: 1,
                        phase: GamePhase::Setup,
                        scoringTileId: $setupPool->roundScoringTiles[0]->value,
                        additionalScoringTileId: $setupPool->additionalFinalRoundGoal->value,
                    ),
                    availableTownTileIds: array_merge(...array_fill(
                        0,
                        3,
                        $this->enumValues($setupPool->townTiles),
                    )),
                    availablePalaceIds: $this->enumValues($setupPool->palaces),
                    availableInventionIds: $this->enumValues($setupPool->innovations),
                    availableCompetencyIds: array_merge(...array_fill(
                        0,
                        CompetencySupply::COPIES_PER_COMPETENCY,
                        $this->enumValues($setupPool->competencies),
                    )),
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
            $this->appendGameHistory->execute(
                $lockedGame,
                $owner,
                GameActionType::StartGame,
                [],
                [[
                    'type' => GameEventType::GameStarted->value,
                    'turn_order' => $orderedPlayers->pluck('id')->all(),
                    'active_game_player_id' => $activePlayer->id,
                    'map_variant' => $lockedGame->state->board->variant->value,
                    'random_seed' => $lockedGame->random_seed,
                    'rules_version' => $lockedGame->rules_version,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
                true,
            );

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
