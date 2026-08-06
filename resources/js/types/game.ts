export type MapVariant = 'one_to_three_players' | 'three_to_five_players';

export type GameStatus = 'lobby' | 'active' | 'finished' | 'abandoned';

export type GameSummary = {
    id: number;
    status: GameStatus;
    mapVariant: MapVariant;
    playersCount: number;
    createdAt: string | null;
};

export type GameCollection = {
    data: GameSummary[];
};

export type TerrainType =
    | 'desert'
    | 'plains'
    | 'swamp'
    | 'lake'
    | 'forest'
    | 'mountain'
    | 'wasteland';

export type BoardHexState = {
    id: string;
    q: number;
    r: number;
    initialTerrain: TerrainType;
    terrain: TerrainType;
};

export type BoardState = {
    variant: MapVariant;
    hexes: BoardHexState[];
};
