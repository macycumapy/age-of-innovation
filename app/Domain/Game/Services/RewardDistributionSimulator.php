<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyChooseTownBooksAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use InvalidArgumentException;

final class RewardDistributionSimulator
{
    public function __construct(private ApplyChooseTownBooksAction $applyChooseTownBooks)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        RewardDistributionOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $nextActiveUserId = $this->applyChooseTownBooks->execute(
            $simulatedState,
            $player,
            $option->bookCounts,
        );

        return new GameActionSimulationData($simulatedState, $nextActiveUserId);
    }
}
