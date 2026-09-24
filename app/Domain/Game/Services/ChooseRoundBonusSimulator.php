<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyChooseRoundBonusAction;
use App\Domain\Game\Data\ChooseRoundBonusOptionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use InvalidArgumentException;

final class ChooseRoundBonusSimulator
{
    public function __construct(
        private SimulationGamePlayerFactory $gamePlayerFactory,
        private ApplyChooseRoundBonusAction $applyChooseRoundBonus,
    ) {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        ChooseRoundBonusOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyChooseRoundBonus->execute(
            $simulatedState,
            $simulatedPlayer,
            $option,
            $this->gamePlayerFactory->create($simulatedState),
        );

        return new GameActionSimulationData(
            $simulatedState,
            $result->nextActivePlayerId ?? $simulatedPlayer->playerId,
        );
    }
}
