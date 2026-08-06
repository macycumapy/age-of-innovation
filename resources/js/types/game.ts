export type MapVariant = 'one_to_three_players' | 'three_to_five_players';

export type GameStatus = 'lobby' | 'active' | 'finished' | 'abandoned';

export type GameSummary = {
    id: number;
    status: GameStatus;
    mapVariant: MapVariant;
    maxPlayers: number;
    playersCount: number;
    isJoined: boolean;
    createdAt: string | null;
};

export type GameCollection = {
    data: GameSummary[];
};

export type GameResource = {
    data: GameSummary & {
        players: GamePlayerSummary[];
        isOwner: boolean;
        activePlayerId: number | null;
        turnOrder: number[];
        canStart: boolean;
        planningBundles: PlanningBundle[];
        planningSelections: PlanningSelection[];
        pendingInteraction: PendingInteraction | null;
    };
};

export type GamePlayerSummary = {
    id: number;
    seat: number;
    isReady: boolean;
    faction: Faction | null;
    homeland: TerrainType | null;
    user: {
        id: number;
        name: string;
    };
};

export type Faction =
    | 'blessed'
    | 'felines'
    | 'goblins'
    | 'illusionists'
    | 'inventors'
    | 'lizards'
    | 'moles'
    | 'monks'
    | 'navigators'
    | 'omar'
    | 'philosophers'
    | 'psychics';

export type RoundBonus =
    | 'river_workshop'
    | 'send_scholar'
    | 'build_guild'
    | 'pass_palace_university'
    | 'spade'
    | 'bridge'
    | 'knowledge'
    | 'pass_school'
    | 'power_coins'
    | 'coins';

export type PlanningBundle = {
    homeland: TerrainType;
    faction: Faction;
    roundBonus: RoundBonus;
};

export type PlanningSelection = {
    playerId: number;
    bundle: PlanningBundle;
};

export type KnowledgeDiscipline =
    | 'banking'
    | 'law'
    | 'engineering'
    | 'medicine';

export type PendingInteraction = {
    type: 'choose_starting_resources';
    playerId: number;
    optionIds: KnowledgeDiscipline[];
    context: {
        bookCount: number;
        knowledgeStepCount: number;
    };
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
