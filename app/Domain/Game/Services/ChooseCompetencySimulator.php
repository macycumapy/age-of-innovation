<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyChooseCompetencyAction;
use App\Domain\Game\Data\ChooseCompetencyOptionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use InvalidArgumentException;

final class ChooseCompetencySimulator
{
    public function __construct(private ApplyChooseCompetencyAction $applyChooseCompetency)
    {
    }

    public function execute(GameStateData $state, int $playerId, ChooseCompetencyOptionData $option): GameActionSimulationData
    {
        $simulatedState = GameStateData::from($state->toArray());
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyChooseCompetency->execute($simulatedState, $player, $option->competency);

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
