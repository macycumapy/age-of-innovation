import type { BoardState } from '@/types';

export function hasAdjacentOpponent(board: BoardState, hexId: string | null, playerId: number | null): boolean {
    const hex = board.hexes.find((candidate) => candidate.id === hexId);

    if (hex === undefined || playerId === null) {
        return false;
    }

    const neighborHexIds = new Set(hex.adjacentHexIds);

    for (const bridge of board.bridges ?? []) {
        if (bridge.fromHexId === hex.id) {
            neighborHexIds.add(bridge.toHexId);
        } else if (bridge.toHexId === hex.id) {
            neighborHexIds.add(bridge.fromHexId);
        }
    }

    return board.hexes.some((candidate) =>
        neighborHexIds.has(candidate.id) &&
        candidate.building !== null &&
        candidate.building.ownerPlayerId !== playerId,
    );
}
