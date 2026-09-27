<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Factories\BoardStateFactory;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\CompetencySupply;
use App\Models\Game;
use App\Models\GamePlayer;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateAutomatedGameAction
{
    public function __construct(
        private BoardStateFactory $boardStateFactory,
        private GameSetupPoolFactory $setupPoolFactory,
        private CreateGamePlayerAction $createGamePlayer,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(
        string $seed,
        int $playerCount = 2,
        GameBotDifficulty $difficulty = GameBotDifficulty::Fast,
    ): Game {
        if ($playerCount < 2 || $playerCount > 5) {
            throw new InvalidArgumentException('Число ботов должно быть от 2 до 5.');
        }

        return DB::transaction(function () use ($seed, $playerCount, $difficulty): Game {
            $setupPool = $this->setupPoolFactory->createFromSeed($playerCount, $seed);
            $game = Game::create([
                'status' => GameStatus::Active,
                'phase' => GamePhase::Setup,
                'round' => 1,
                'version' => 1,
                'random_seed' => $seed,
                'started_at' => now(),
                'state' => new GameStateData(
                    schemaVersion: CompetencySupply::CURRENT_SCHEMA_VERSION,
                    board: $this->boardStateFactory->create($setupPool->mapVariant),
                ),
            ]);
            $players = collect(range(1, $playerCount))->map(
                fn (int $seat) => $this->createGamePlayer->execute($game, null, $seat, $difficulty),
            );
            $orderedPlayers = $players
                ->slice($setupPool->firstPlayerIndex)
                ->concat($players->take($setupPool->firstPlayerIndex))
                ->values();
            $activePlayer = $orderedPlayers->firstOrFail();
            $turnOrder = array_values(array_map(
                static fn (GamePlayer $player): int => $player->id,
                $orderedPlayers->all(),
            ));
            $game->update([
                'active_game_player_id' => $activePlayer->id,
                'state' => new GameStateData(
                    schemaVersion: CompetencySupply::CURRENT_SCHEMA_VERSION,
                    turnOrder: $turnOrder,
                    board: $this->boardStateFactory->create($setupPool->mapVariant),
                    round: new RoundStateData(
                        number: 1,
                        phase: GamePhase::Setup,
                        scoringTileId: $setupPool->roundScoringTiles[0] instanceof BackedEnum
                            ? (string) $setupPool->roundScoringTiles[0]->value
                            : $setupPool->roundScoringTiles[0],
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
            ]);
            $this->appendGameHistory->execute(
                $game,
                $activePlayer,
                GameActionType::StartGame,
                [],
                [[
                    'type' => GameEventType::GameStarted->value,
                    'turn_order' => $turnOrder,
                    'active_game_player_id' => $activePlayer->id,
                    'map_variant' => $setupPool->mapVariant->value,
                    'random_seed' => $seed,
                    'rules_version' => $game->rules_version,
                ]],
                0,
                1,
                true,
            );

            return $game->refresh();
        });
    }

    /**
     * @param list<BackedEnum> $cases
     * @return list<string>
     */
    private function enumValues(array $cases): array
    {
        return array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
    }
}
