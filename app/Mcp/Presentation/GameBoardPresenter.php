<?php

declare(strict_types=1);

namespace App\Mcp\Presentation;

use App\Domain\GameEngine\Board\Data\BoardStateData;

final class GameBoardPresenter
{
    /** @return array<string, mixed> */
    public function present(BoardStateData $board): array
    {
        $hexes = [];
        foreach ($board->hexes as $hex) {
            $building = $hex->building;
            $row = [
                $hex->id, $hex->q, $hex->r, $hex->initialTerrain->value, $hex->terrain->value,
                $hex->adjacentHexIds, $hex->riverConnectedHexIds,
                $building === null ? null : [$building->type->value, $building->ownerPlayerId, $building->isNeutral, $building->hasAnnex],
                $hex->townId, $hex->townTileId,
            ];
            while (end($row) === null) {
                array_pop($row);
            }
            $hexes[] = $row;
        }

        $bridges = [];
        foreach ($board->bridges as $bridge) {
            $bridges[] = [$bridge->fromHexId, $bridge->toHexId, $bridge->ownerPlayerId];
        }

        return [
            'variant' => $board->variant->value,
            'hex_fields' => ['id', 'q', 'r', 'initialTerrain', 'terrain', 'adjacentHexIds', 'riverConnectedHexIds', 'building', 'townId', 'townTileId'],
            'building_fields' => ['type', 'ownerPlayerId', 'isNeutral', 'hasAnnex'],
            'bridge_fields' => ['fromHexId', 'toHexId', 'ownerPlayerId'],
            'hexes' => $hexes,
            'bridges' => $bridges,
            'riverBankHexIds' => $board->riverBankHexIds,
            'edgeHexIds' => $board->edgeHexIds,
        ];
    }
}
