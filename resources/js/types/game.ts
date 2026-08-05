export type MapVariant = 'one_to_three_players' | 'three_to_five_players';

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
