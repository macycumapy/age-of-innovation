<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\Competency;

final class CompetencySupply
{
    public const int CURRENT_SCHEMA_VERSION = 4;

    public const int COPIES_PER_COMPETENCY = 4;

    /** @return list<string> */
    public static function availableIds(GameStateData $state): array
    {
        if ($state->schemaVersion !== 3 || $state->setupPool === null) {
            return $state->availableCompetencyIds;
        }

        $ownedCounts = array_count_values(array_merge(
            ...array_map(
                static fn (GamePlayerStateData $playerState): array => $playerState->competencyIds,
                $state->players,
            ),
        ));
        $availableIds = [];

        foreach ($state->setupPool->competencies as $competency) {
            $competencyId = $competency instanceof Competency ? $competency->value : $competency;
            $availableCount = max(0, self::COPIES_PER_COMPETENCY - ($ownedCounts[$competencyId] ?? 0));
            array_push($availableIds, ...array_fill(0, $availableCount, $competencyId));
        }

        return $availableIds;
    }
}
