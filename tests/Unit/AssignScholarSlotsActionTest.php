<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Actions\AssignScholarSlotsAction;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\KnowledgeStateData;
use App\Domain\Game\Data\NeutralKnowledgeStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use PHPUnit\Framework\TestCase;

class AssignScholarSlotsActionTest extends TestCase
{
    public function test_it_assigns_stable_slots_around_the_neutral_scholar(): void
    {
        $firstPlayer = $this->player(1, PlayerColor::Green, ['law']);
        $secondPlayer = $this->player(2, PlayerColor::Red, ['law']);
        $state = new GameStateData(
            players: [$firstPlayer, $secondPlayer],
            neutralKnowledge: new NeutralKnowledgeStateData(
                PlayerColor::Black,
                new KnowledgeStateData(),
                ['banking', 'law', 'engineering', 'medicine'],
                1,
            ),
        );
        $action = new AssignScholarSlotsAction();

        $nextSlotIndex = $action->nextAvailable($state, KnowledgeDiscipline::Law);

        $this->assertSame([0], $firstPlayer->scholarSlotIndexes);
        $this->assertSame([2], $secondPlayer->scholarSlotIndexes);
        $this->assertSame(3, $nextSlotIndex);

        $firstPlayer->scholarDisciplineIds[] = KnowledgeDiscipline::Law->value;
        $firstPlayer->scholarSlotIndexes[] = $nextSlotIndex;
        $action->normalize($state);

        $this->assertSame([0, 3], $firstPlayer->scholarSlotIndexes);
        $this->assertSame([2], $secondPlayer->scholarSlotIndexes);
    }

    /** @param list<string> $scholarDisciplineIds */
    private function player(int $playerId, PlayerColor $color, array $scholarDisciplineIds): GamePlayerStateData
    {
        return new GamePlayerStateData(
            playerId: $playerId,
            userId: $playerId,
            color: $color,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            scholarDisciplineIds: $scholarDisciplineIds,
        );
    }
}
