<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use InvalidArgumentException;

final class PassValueEvaluator
{
    private const int COIN_WEIGHT = 1;

    private const int TOOL_WEIGHT = 3;

    private const int SCHOLAR_WEIGHT = 4;

    private const int BOOK_WEIGHT = 3;

    private const int POWER_WEIGHT = 1;

    private const int SPADE_WEIGHT = 3;

    public function execute(GameStateData $state, int $playerId): int
    {
        $player = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для оценки паса.');
        }

        $resources = $player->resources;
        $books = $resources->books;
        $power = $resources->power;
        $roundMultiplier = max(1, (int) ceil($state->round->number / 2));

        return (
            ($resources->coins * self::COIN_WEIGHT)
            + ($resources->tools * self::TOOL_WEIGHT)
            + ($resources->scholars * self::SCHOLAR_WEIGHT)
            + (($books->banking + $books->law + $books->engineering + $books->medicine + $books->unassigned)
                * self::BOOK_WEIGHT)
            + (($power->bowlThree + intdiv($power->bowlTwo, 2)) * self::POWER_WEIGHT)
            + ($player->unassignedSpades * self::SPADE_WEIGHT)
        ) * $roundMultiplier;
    }
}
