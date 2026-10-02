<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\GameEngine\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\Automation\Simulation\Services\SimulationGamePlayerFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Actions\BeginPassAction;
use App\Domain\GameEngine\Turns\Data\PassOptionData;
use App\Domain\GameEngine\Turns\Services\PassOptionFinder;
use InvalidArgumentException;

final class PassSimulator
{
    public function __construct(
        private PassOptionFinder $optionFinder,
        private SimulationGamePlayerFactory $gamePlayerFactory,
        private BeginPassAction $beginPass,
    ) {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PassOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $matchingOption = collect($this->optionFinder->execute($simulatedState, $simulatedPlayer))->first(
            static fn (PassOptionData $candidate): bool => $candidate->knowledgeDisciplines === $option->knowledgeDisciplines,
        );

        if (! $matchingOption instanceof PassOptionData) {
            throw new InvalidArgumentException('Недопустимый вариант паса.');
        }

        $result = $this->beginPass->execute(
            $simulatedState,
            $simulatedPlayer,
            $this->gamePlayerFactory->create($simulatedState),
            $matchingOption->knowledgeDisciplines,
        );
        $nextActivePlayerId = $result['completion']['nextActivePlayerId'] ?? $simulatedPlayer->playerId;

        return new GameActionSimulationData($simulatedState, $nextActivePlayerId);
    }
}
