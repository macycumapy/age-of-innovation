<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPlacePalaceGuildAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlacePalaceGuildOptionData;
use InvalidArgumentException;

final class PlacePalaceGuildSimulator
{
    public function __construct(private ApplyPlacePalaceGuildAction $applyPlacePalaceGuild)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PlacePalaceGuildOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyPlacePalaceGuild->execute($simulatedState, $player, $option->hexId);

        return new GameActionSimulationData($simulatedState, $result->nextActiveUserId);
    }
}
