<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\GamePhase;
use App\Models\GamePlayer;
use BackedEnum;
use Illuminate\Database\Eloquent\Collection;

final class StartNextRoundAction
{
    public function __construct(private ResolveIncomePhaseAction $resolveIncomePhase)
    {
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @return array{GamePlayer, GamePhase}
     */
    public function execute(GameStateData $state, Collection $players): array
    {
        foreach ($state->setupPool?->availableRoundBonuses ?? [] as $roundBonus) {
            $roundBonus->coins++;
        }

        foreach ($state->players as $playerState) {
            $playerState->usedSpecialActionIds = [];
        }

        $state->round->number++;
        $scoringTile = $state->setupPool?->roundScoringTiles[$state->round->number - 1] ?? null;
        $state->round->scoringTileId = $scoringTile instanceof BackedEnum ? (string) $scoringTile->value : $scoringTile;
        $state->round->usedSharedActionIds = [];
        $state->round->usedBookActionIds = [];
        $state->round->incomeTurnIndex = 0;
        $state->round->scienceBonusTurnIndex = 0;
        $state->round->phase = GamePhase::Income;

        return $this->resolveIncomePhase->execute($state, $players);
    }
}
