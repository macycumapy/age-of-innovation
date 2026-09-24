<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPalaceAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PalaceActionOptionData;
use InvalidArgumentException;

final class PalaceActionSimulator
{
    public function __construct(
        private PalaceActionOptionFinder $optionFinder,
        private ApplyPalaceAction $applyPalaceAction,
    ) {
    }

    public function execute(GameStateData $state, int $playerId, PalaceActionOptionData $option): GameActionSimulationData
    {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $matchingOption = collect($this->optionFinder->execute($simulatedState, $simulatedPlayer))->first(
            static fn (PalaceActionOptionData $candidate): bool => $candidate->toArray() === $option->toArray(),
        );
        if (! $matchingOption instanceof PalaceActionOptionData) {
            throw new InvalidArgumentException('Недопустимый вариант действия Дворца.');
        }

        $result = $this->applyPalaceAction->execute(
            $simulatedState,
            $simulatedPlayer,
            $matchingOption->discipline,
            $matchingOption->knowledgeDisciplines,
            $matchingOption->hexId,
        );

        return new GameActionSimulationData($simulatedState, $result['nextActivePlayerId']);
    }
}
