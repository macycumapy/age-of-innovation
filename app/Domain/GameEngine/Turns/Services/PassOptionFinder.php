<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Services;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Data\PassOptionData;

final class PassOptionFinder
{
    /** @return list<PassOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction
            || in_array($player->playerId, $state->passedPlayerIds, true)) {
            return [];
        }

        $schoolCount = $player->roundBonus === RoundBonus::PassSchool
            ? count(array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                    && ! $hex->building->isNeutral
                    && $hex->building->type === BuildingType::School,
            ))
            : 0;

        return array_map(
            static fn (array $disciplines): PassOptionData => new PassOptionData($disciplines),
            $this->disciplineCombinations($schoolCount),
        );
    }

    /** @return list<list<KnowledgeDiscipline>> */
    private function disciplineCombinations(int $count, int $minimumIndex = 0): array
    {
        if ($count === 0) {
            return [[]];
        }

        $combinations = [];
        $disciplines = KnowledgeDiscipline::cases();

        for ($index = $minimumIndex; $index < count($disciplines); $index++) {
            foreach ($this->disciplineCombinations($count - 1, $index) as $remaining) {
                $combinations[] = [$disciplines[$index], ...$remaining];
            }
        }

        return $combinations;
    }
}
