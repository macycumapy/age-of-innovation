<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\Setup;

use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\Setup\Actions\InitializeNeutralKnowledgeFactionAction;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use PHPUnit\Framework\TestCase;

class InitializeNeutralKnowledgeFactionActionTest extends TestCase
{
    public function test_it_places_and_advances_the_neutral_faction_for_two_players_on_the_small_map(): void
    {
        $setupPool = (new GameSetupPoolFactory())->createFromSeed(2, 'neutral-knowledge-test');
        $setupPool->roundScoringTiles = [
            RoundScoringTile::SchoolBanking,
            RoundScoringTile::WorkshopLaw,
            RoundScoringTile::GuildLaw,
            RoundScoringTile::TownEngineering,
            RoundScoringTile::PalaceUniversityMedicine,
            RoundScoringTile::GuildMedicine,
        ];
        $state = new GameStateData(
            turnOrder: [1, 2],
            board: new BoardStateData(variant: MapVariant::OneToThreePlayers),
            players: [
                $this->player(1, PlayerColor::Yellow),
                $this->player(2, PlayerColor::Red),
            ],
            setupPool: $setupPool,
        );

        (new InitializeNeutralKnowledgeFactionAction())->execute($state);

        $this->assertNotNull($state->neutralKnowledge);
        $this->assertSame(PlayerColor::Black, $state->neutralKnowledge->color);
        $this->assertSame(3, $state->neutralKnowledge->knowledge->banking);
        $this->assertSame(8, $state->neutralKnowledge->knowledge->law);
        $this->assertSame(6, $state->neutralKnowledge->knowledge->engineering);
        $this->assertSame(4, $state->neutralKnowledge->knowledge->medicine);
        $this->assertSame(
            ['banking', 'law', 'engineering', 'medicine'],
            $state->neutralKnowledge->scholarDisciplineIds,
        );
        $this->assertSame(1, $state->neutralKnowledge->scholarSlotIndex);
        $this->assertSame([], $state->neutralKnowledge->knowledge->unlockedDisciplines);
    }

    public function test_it_does_not_add_the_neutral_faction_on_the_large_map(): void
    {
        $state = new GameStateData(
            turnOrder: [1, 2],
            board: new BoardStateData(variant: MapVariant::ThreeToFivePlayers),
            players: [
                $this->player(1, PlayerColor::Yellow),
                $this->player(2, PlayerColor::Red),
            ],
            setupPool: (new GameSetupPoolFactory())->createFromSeed(2, 'neutral-knowledge-test'),
        );

        (new InitializeNeutralKnowledgeFactionAction())->execute($state);

        $this->assertNull($state->neutralKnowledge);
    }

    public function test_it_accepts_round_scoring_tiles_hydrated_as_strings(): void
    {
        $setupPool = (new GameSetupPoolFactory())->createFromSeed(2, 'hydrated-neutral-knowledge-test');
        $setupPool->roundScoringTiles = array_map(
            static fn (RoundScoringTile|string $tile): string => $tile instanceof RoundScoringTile
                ? $tile->value
                : $tile,
            $setupPool->roundScoringTiles,
        );
        $state = new GameStateData(
            turnOrder: [1, 2],
            board: new BoardStateData(variant: MapVariant::OneToThreePlayers),
            players: [
                $this->player(1, PlayerColor::Yellow),
                $this->player(2, PlayerColor::Red),
            ],
            setupPool: $setupPool,
        );

        (new InitializeNeutralKnowledgeFactionAction())->execute($state);

        $this->assertNotNull($state->neutralKnowledge);
    }

    public function test_it_does_not_create_a_neutral_faction_when_only_two_of_three_players_have_selected_bundles(): void
    {
        $state = new GameStateData(
            turnOrder: [1, 2, 3],
            board: new BoardStateData(variant: MapVariant::OneToThreePlayers),
            players: [
                $this->player(1, PlayerColor::Yellow),
                $this->player(2, PlayerColor::Red),
            ],
            setupPool: (new GameSetupPoolFactory())->createFromSeed(3, 'three-player-neutral-test'),
        );
        $action = new InitializeNeutralKnowledgeFactionAction();

        $action->execute($state);
        $this->assertNull($state->neutralKnowledge);

        $state->players[] = $this->player(3, PlayerColor::Black);
        $action->execute($state);
        $this->assertNull($state->neutralKnowledge);
    }

    public function test_it_removes_an_existing_neutral_faction_in_a_three_player_game(): void
    {
        $state = new GameStateData(
            turnOrder: [1, 2],
            board: new BoardStateData(variant: MapVariant::OneToThreePlayers),
            players: [
                $this->player(1, PlayerColor::Yellow),
                $this->player(2, PlayerColor::Red),
            ],
            setupPool: (new GameSetupPoolFactory())->createFromSeed(2, 'stale-neutral-test'),
        );
        $action = new InitializeNeutralKnowledgeFactionAction();
        $action->execute($state);
        $this->assertNotNull($state->neutralKnowledge);

        $state->turnOrder[] = 3;
        $action->execute($state);
        $this->assertNull($state->neutralKnowledge);
    }

    private function player(int $playerId, PlayerColor $color): GamePlayerStateData
    {
        return new GamePlayerStateData(
            playerId: $playerId,
            userId: $playerId,
            color: $color,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
    }
}
