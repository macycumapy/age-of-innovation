<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\ScienceBonusEvaluator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class ScienceBonusEvaluatorTest extends TestCase
{
    public function test_projection_uses_each_remaining_tile_and_its_real_knowledge_threshold(): void
    {
        [$state, $player] = $this->state();
        $evaluator = app(ScienceBonusEvaluator::class);
        $before = $state->toArray();

        $this->assertSame(128, $evaluator->execute($state, $player));
        $this->assertSame($before, $state->toArray());
        $player->knowledge->banking++;
        $this->assertSame(148, $evaluator->execute($state, $player));
        $state->setupPool->roundScoringTiles = array_map(
            fn (RoundScoringTile $tile): string => $tile->value,
            $state->setupPool->roundScoringTiles,
        );
        $this->assertSame(148, $evaluator->execute($state, $player));
    }

    public function test_already_received_rewards_are_not_projected_again(): void
    {
        [$state, $player] = $this->state();
        $state->round->number = 2;
        $state->round->scoringTileId = RoundScoringTile::GuildLaw->value;
        $evaluator = app(ScienceBonusEvaluator::class);
        $this->assertSame(88, $evaluator->execute($state, $player));
        $state->round->phase = GamePhase::ScienceBonus;
        $state->round->scienceBonusReceipts = [['round' => 2, 'player_id' => 1, 'books' => 1]];
        $this->assertSame(63, $evaluator->execute($state, $player));
        $state->round->number = 3;
        $state->round->phase = GamePhase::Actions;
        $state->round->scoringTileId = RoundScoringTile::PalaceUniversityMedicine->value;
        $this->assertSame(63, $evaluator->execute($state, $player));
    }

    public function test_blessed_science_bonus_uses_the_faction_modifier(): void
    {
        [$state, $player] = $this->state();
        $state->setupPool->roundScoringTiles = [RoundScoringTile::SchoolBanking];
        $player->knowledge = new KnowledgeStateData();
        $evaluator = app(ScienceBonusEvaluator::class);
        $this->assertSame(0, $evaluator->execute($state, $player));
        $player->faction = Faction::Blessed;
        $this->assertSame(60, $evaluator->execute($state, $player));
    }

    public function test_spades_have_no_projected_value_without_a_reachable_target(): void
    {
        [$state, $player] = $this->state();
        $state->setupPool->roundScoringTiles = [RoundScoringTile::GuildMedicine];
        $state->round->scoringTileId = null;
        $evaluator = app(ScienceBonusEvaluator::class);
        $this->assertSame(0, $evaluator->execute($state, $player));
        $state->board->hexes = [
            new BoardHexStateData(
                id: 'home',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                adjacentHexIds: ['target'],
                building: new BuildingStateData(BuildingType::Workshop, 1),
            ),
            new BoardHexStateData(
                id: 'target',
                q: 1,
                r: 0,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Mountain,
                adjacentHexIds: ['home'],
            ),
        ];
        $this->assertSame(30, $evaluator->execute($state, $player));
    }

    public function test_the_final_round_has_no_science_bonus(): void
    {
        [$state, $player] = $this->state();
        $state->round->number = 6;
        $this->assertSame(0, app(ScienceBonusEvaluator::class)->execute($state, $player));
        $state->round->number = 2;
        $state->round->phase = GamePhase::Finished;
        $this->assertSame(0, app(ScienceBonusEvaluator::class)->execute($state, $player));
    }

    public function test_current_scholar_reward_respects_the_pool_limit(): void
    {
        [$state, $player] = $this->state();
        $state->round->number = 5;
        $state->round->scoringTileId = RoundScoringTile::WorkshopLaw->value;
        $this->assertSame(2, app(ScienceBonusEvaluator::class)->execute($state, $player));
        $player->resources->scholars = $player->scholarPoolSize;
        $this->assertSame(0, app(ScienceBonusEvaluator::class)->execute($state, $player));
    }

    /** @return array{GameStateData, GamePlayerStateData} */
    private function state(): array
    {
        $player = new GamePlayerStateData(
            playerId: 1,
            userId: 10,
            color: PlayerColor::Black,
            faction: Faction::Inventors,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 2, law: 3, engineering: 3, medicine: 4),
        );
        $pool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'science-bonus-projection');
        $pool->roundScoringTiles = [
            RoundScoringTile::SchoolBanking, RoundScoringTile::GuildLaw,
            RoundScoringTile::PalaceUniversityMedicine, RoundScoringTile::TrackEngineering,
            RoundScoringTile::WorkshopLaw, RoundScoringTile::WorkshopBanking,
        ];

        return [new GameStateData(
            players: [$player],
            setupPool: $pool,
            round: new RoundStateData(number: 1, phase: GamePhase::Actions, scoringTileId: RoundScoringTile::SchoolBanking->value),
        ), $player];
    }
}
