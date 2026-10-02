<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Research;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Actions\AdvanceKnowledgeAction;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_level_eight_requires_and_spends_a_town_key(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 7),
        );
        $state = new GameStateData(players: [$playerState]);
        $advanceKnowledge = app(AdvanceKnowledgeAction::class);

        $advanceKnowledge->execute($state, $playerState, KnowledgeDiscipline::Banking, 2);
        $this->assertSame(7, $playerState->knowledge->banking);

        $playerState->townTileIds[] = TownTile::Tools->value;
        $advanceKnowledge->execute($state, $playerState, KnowledgeDiscipline::Banking, 2);

        $this->assertSame(9, $playerState->knowledge->banking);
        $this->assertSame([KnowledgeDiscipline::Banking], $playerState->knowledge->unlockedDisciplines);
    }

    public function test_advancing_knowledge_returns_the_power_that_was_actually_gained(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Black,
            faction: Faction::Navigators,
            homeland: TerrainType::Swamp,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(power: new PowerBowlsStateData(bowlOne: 1, bowlTwo: 11)),
            knowledge: new KnowledgeStateData(law: 2),
        );

        $knowledgeAdvance = app(AdvanceKnowledgeAction::class)->execute(
            new GameStateData(players: [$playerState]),
            $playerState,
            KnowledgeDiscipline::Law,
            1,
        );

        $this->assertSame(1, $knowledgeAdvance->advancedSteps);
        $this->assertSame(1, $knowledgeAdvance->gainedPower);
        $this->assertSame(0, $knowledgeAdvance->victoryPoints);
        $this->assertSame(0, $playerState->resources->power->bowlOne);
        $this->assertSame(12, $playerState->resources->power->bowlTwo);
    }

    public function test_only_one_player_may_reach_the_top_of_each_knowledge_discipline(): void
    {
        $leader = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 12, unlockedDisciplines: [KnowledgeDiscipline::Banking]),
            townTileIds: [TownTile::Tools->value],
        );
        $challenger = new GamePlayerStateData(
            playerId: 16,
            userId: 26,
            color: PlayerColor::Red,
            faction: Faction::Blessed,
            homeland: TerrainType::Desert,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 11, unlockedDisciplines: [KnowledgeDiscipline::Banking]),
            townTileIds: [TownTile::Coins->value],
        );
        $state = new GameStateData(players: [$leader, $challenger]);

        app(AdvanceKnowledgeAction::class)->execute(
            $state,
            $challenger,
            KnowledgeDiscipline::Banking,
            1,
        );

        $this->assertSame(11, $challenger->knowledge->banking);
    }
}
