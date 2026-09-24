<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplySendScholarAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\SendScholarOptionData;
use InvalidArgumentException;

final class SendScholarSimulator
{
    public function __construct(private ApplySendScholarAction $applySendScholar)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        SendScholarOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $this->applySendScholar->execute($simulatedState, $simulatedPlayer, $option);

        return new GameActionSimulationData($simulatedState, $simulatedPlayer->playerId);
    }
}
