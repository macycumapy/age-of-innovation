<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\TerrainType;

class FindPalaceWaterTownOptionsAction
{
    public function __construct(private FindEligibleTownHexesAction $findEligibleTownHexes)
    {
    }

    /** @return array<string, list<string>> Town building hex IDs keyed by ignored water hex ID. */
    public function execute(GameStateData $state, GamePlayerStateData $player, string $builtHexId): array
    {
        if ($player->palaceId !== PalaceAbility::Palace14->value) {
            return [];
        }

        $options = [];

        foreach ($state->board->hexes as $hex) {
            if (! $hex instanceof BoardHexStateData
                || $hex->terrain !== TerrainType::Water
                || $hex->building !== null
                || $hex->townId !== null) {
                continue;
            }

            $townHexIds = $this->findEligibleTownHexes->execute($state, $player, $builtHexId, $hex->id);

            if ($townHexIds !== []) {
                $options[$hex->id] = $townHexIds;
            }
        }

        return $options;
    }
}
