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
                $hex->id, $hex->terrain->value,
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
            'layout_id' => $this->layout($board)['layout_id'],
            'hex_fields' => ['id', 'terrain', 'building', 'townId', 'townTileId'],
            'building_fields' => ['type', 'ownerPlayerId', 'isNeutral', 'hasAnnex'],
            'bridge_fields' => ['fromHexId', 'toHexId', 'ownerPlayerId'],
            'hexes' => $hexes,
            'bridges' => $bridges,
        ];
    }

    /** @return array<string, mixed> */
    public function layout(BoardStateData $board): array
    {
        $hexes = [];
        foreach ($board->hexes as $hex) {
            $adjacent = $hex->adjacentHexIds;
            $riverConnected = $hex->riverConnectedHexIds;
            sort($adjacent, SORT_STRING);
            sort($riverConnected, SORT_STRING);
            $hexes[] = [$hex->id, $hex->q, $hex->r, $hex->initialTerrain->value, $adjacent, $riverConnected];
        }
        usort($hexes, static fn (array $first, array $second): int => strcmp($first[0], $second[0]));
        $riverBank = $board->riverBankHexIds;
        $edge = $board->edgeHexIds;
        sort($riverBank, SORT_STRING);
        sort($edge, SORT_STRING);

        $layout = [
            'format' => 'board-layout-v1',
            'variant' => $board->variant->value,
            'hex_fields' => ['id', 'q', 'r', 'initialTerrain', 'adjacentHexIds', 'riverConnectedHexIds'],
            'hexes' => $hexes,
            'riverBankHexIds' => $riverBank,
            'edgeHexIds' => $edge,
        ];

        return ['layout_id' => hash('sha256', json_encode($layout, JSON_THROW_ON_ERROR)), ...$layout];
    }
}
