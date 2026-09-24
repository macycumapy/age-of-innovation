<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Actions\ApplyFinishActionTurnAction;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use Tests\TestCase;

class ApplyFinishActionTurnActionTest extends TestCase
{
    public function test_it_advances_to_the_next_non_passed_player(): void
    {
        $state = new GameStateData(
            turnOrder: [1, 2, 3],
            passedPlayerIds: [2],
            players: [
                $this->player(1, 10),
                $this->player(2, 20),
                $this->player(3, 30),
            ],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                turnStartVersion: 12,
                hasTakenMainAction: true,
                isCurrentTurnIrrevocable: true,
            ),
            turnStartSnapshot: ['version' => 12],
            townChoiceCheckpoint: ['version' => 11],
        );

        $nextPlayerId = app(ApplyFinishActionTurnAction::class)->execute($state, $state->players[0]);

        $this->assertSame(3, $nextPlayerId);
        $this->assertFalse($state->round->hasTakenMainAction);
        $this->assertFalse($state->round->isCurrentTurnIrrevocable);
        $this->assertNull($state->round->turnStartVersion);
        $this->assertNull($state->turnStartSnapshot);
        $this->assertNull($state->townChoiceCheckpoint);
    }

    private function player(int $playerId, int $userId): GamePlayerStateData
    {
        return new GamePlayerStateData(
            playerId: $playerId,
            userId: $userId,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(),
        );
    }
}
