<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GamePlayerStateFactory;
use App\Models\GamePlayer;
use PHPUnit\Framework\TestCase;

class GamePlayerStateFactoryTest extends TestCase
{
    public function test_it_creates_base_resources_when_a_bundle_is_chosen(): void
    {
        $state = $this->createState(TerrainType::Desert, Faction::Inventors);

        $this->assertSame(15, $state->resources->coins);
        $this->assertSame(3, $state->resources->tools);
        $this->assertSame(0, $state->resources->scholars);
        $this->assertSame(5, $state->resources->power->bowlOne);
        $this->assertSame(7, $state->resources->power->bowlTwo);
        $this->assertSame(0, $state->resources->power->bowlThree);
        $this->assertSame(1, $state->unassignedSpades);
        $this->assertSame(PlayerColor::Yellow, $state->color);
        $this->assertSame(RoundBonus::Coins, $state->roundBonus);
    }

    public function test_it_applies_homeland_and_faction_starting_resources(): void
    {
        $wastelandPsychics = $this->createState(TerrainType::Wasteland, Faction::Psychics);
        $swampGoblins = $this->createState(TerrainType::Swamp, Faction::Goblins);
        $forestBlessed = $this->createState(TerrainType::Forest, Faction::Blessed);

        $this->assertSame(5, $wastelandPsychics->resources->tools);
        $this->assertSame(1, $wastelandPsychics->resources->books->unassigned);
        $this->assertSame(0, $wastelandPsychics->unassignedSpades);
        $this->assertSame(1, $wastelandPsychics->knowledge->banking);
        $this->assertSame(1, $wastelandPsychics->knowledge->medicine);

        $this->assertSame(4, $swampGoblins->resources->tools);
        $this->assertSame(1, $swampGoblins->resources->scholars);
        $this->assertSame(3, $swampGoblins->resources->power->bowlOne);
        $this->assertSame(9, $swampGoblins->resources->power->bowlTwo);
        $this->assertSame(1, $swampGoblins->knowledge->banking);
        $this->assertSame(1, $swampGoblins->knowledge->engineering);

        $this->assertSame(4, $forestBlessed->resources->power->bowlOne);
        $this->assertSame(8, $forestBlessed->resources->power->bowlTwo);
        $this->assertSame(2, $forestBlessed->knowledge->banking);
        $this->assertSame(2, $forestBlessed->knowledge->law);
        $this->assertSame(2, $forestBlessed->knowledge->engineering);
        $this->assertSame(2, $forestBlessed->knowledge->medicine);
    }

    public function test_it_preserves_starting_choices_that_must_be_made_later(): void
    {
        $lizards = $this->createState(TerrainType::Lake, Faction::Lizards);

        $this->assertSame(1, $lizards->shippingLevel);
        $this->assertSame(2, $lizards->knowledge->unassignedSteps);
    }

    private function createState(TerrainType $homeland, Faction $faction): GamePlayerStateData
    {
        $player = new GamePlayer();
        $player->forceFill([
            'id' => 10,
            'user_id' => 20,
        ]);

        return (new GamePlayerStateFactory())->create(
            $player,
            new PlanningBundleData($homeland, $faction, RoundBonus::Coins),
        );
    }
}
