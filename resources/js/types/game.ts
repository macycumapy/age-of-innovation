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
        playerBoardStates: GamePlayerBoardState[];
        isOwner: boolean;
        activePlayerId: number | null;
        turnOrder: number[];
        board: BoardState;
        canStart: boolean;
        planningBundles: PlanningBundle[];
        planningSelections: PlanningSelection[];
        roundScoringTiles: RoundScoringTile[];
        finalRoundScoringTile: FinalRoundScoringTile | null;
        bookActions: BookAction[];
        innovations: Innovation[];
        competencies: Competency[];
        pendingInteraction: PendingInteraction | null;
    };
};

export type GamePlayerBoardState = {
    playerId: number;
    shippingLevel: number;
    terraformingLevel: number;
    power: {
        bowlOne: number;
        bowlTwo: number;
        bowlThree: number;
    };
};

export type GamePlayerSummary = {
    id: number;
    seat: number;
    isReady: boolean;
    color: PlayerColor | null;
    faction: Faction | null;
    homeland: TerrainType | null;
    user: {
        id: number;
        name: string;
    };
};

export type PlayerColor =
    | 'yellow'
    | 'red'
    | 'black'
    | 'blue'
    | 'green'
    | 'brown'
    | 'grey';

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

export type RoundScoringTile =
    | 'workshop_law'
    | 'workshop_banking'
    | 'guild_law'
    | 'guild_medicine'
    | 'school_banking'
    | 'palace_university_medicine'
    | 'palace_university_banking'
    | 'spade_engineering'
    | 'knowledge_medicine'
    | 'town_engineering'
    | 'track_engineering'
    | 'innovation_law';

export type FinalRoundScoringTile =
    | 'workshop'
    | 'guild'
    | 'school'
    | 'edge_workshop';

export type BookAction =
    | 'gain_power'
    | 'advance_knowledge'
    | 'gain_coins'
    | 'upgrade_to_guild'
    | 'score_guilds'
    | 'terraform_three_spades';

export type Innovation =
    | 'deus_ex_machina'
    | 'trade_routes'
    | 'professor'
    | 'sewage_system'
    | 'architecture'
    | 'library'
    | 'steam_engine'
    | 'league_of_cities'
    | 'telecommunication'
    | 'steel'
    | 'census'
    | 'science'
    | 'workshop'
    | 'guild'
    | 'school'
    | 'university'
    | 'palace'
    | 'monument';

export type Competency =
    | 'competency_01'
    | 'competency_02'
    | 'competency_03'
    | 'competency_04'
    | 'competency_05'
    | 'competency_06'
    | 'competency_07'
    | 'competency_08'
    | 'competency_09'
    | 'competency_10'
    | 'competency_11'
    | 'competency_12';

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
