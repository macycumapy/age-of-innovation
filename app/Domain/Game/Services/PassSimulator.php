<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\BeginPassAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PassOptionData;
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
        $simulatedState = GameStateData::from($state->toArray());
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
