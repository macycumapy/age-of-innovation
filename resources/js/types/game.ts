export type MapVariant = 'one_to_three_players' | 'three_to_five_players';

export type GameStatus = 'lobby' | 'active' | 'finished' | 'abandoned';

export type GameSummary = {
    id: number;
    status: GameStatus;
    currentRound: number | null;
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
        canUndoLastAction: boolean;
        canUndoTownChoice: boolean;
        canRestartCurrentTurn: boolean;
        canFinishCurrentTurn: boolean;
        canPass: boolean;
        activePlayerId: number | null;
        turnOrder: number[];
        board: BoardState;
        canStart: boolean;
        canSendScholar: boolean;
        canMakeInnovation: boolean;
        canPlaceAnnex: boolean;
        planningBundles: PlanningBundle[];
        planningSelections: PlanningSelection[];
        planningBundleDescriptions: PlanningBundleDescriptions;
        competencyDescriptions: Record<Competency, string>;
        innovationDescriptions: Record<Innovation, string>;
        roundBonusDescriptions: Record<RoundBonus, string>;
        palaceDescriptions: Record<PalaceAbility, string>;
        knowledgeDisciplineNames: Record<KnowledgeDiscipline, string>;
        roundScoringTiles: RoundScoringTile[];
        finalRoundScoringTile: FinalRoundScoringTile | null;
        bookActions: BookAction[];
        usedBookActionIds: BookAction[];
        bookActionStates: BookActionState[];
        powerActions: PowerActionState[];
        buildingUpgrades: BuildingUpgradeOption[];
        innovations: Innovation[];
        availableInventionIds: Innovation[];
        innovationStates: InnovationPurchaseState[];
        competencies: Competency[];
        competencyCounts: Partial<Record<Competency, number>>;
        availablePalaceIds: PalaceAbility[];
        availableTownTileIds: TownTile[];
        townTileDescriptions: Record<TownTile, string>;
        roundBonusOffers: RoundBonusOffer[];
        pendingInteraction: PendingInteraction | null;
        startingBuildingTurnIndex: number;
        pendingStartingBuildingHexId: string | null;
        phase: 'setup' | 'income' | 'actions' | 'science_bonus' | 'finished';
        history: GameHistoryPage;
    };
};

export type GameActionType =
    | 'phase_checkpoint'
    | 'start_game'
    | 'choose_planning_bundle'
    | 'choose_starting_resources'
    | 'choose_income_resources'
    | 'place_starting_building'
    | 'undo_starting_building'
    | 'finish_starting_building_turn'
    | 'spend_starting_spade'
    | 'terraform_and_build'
    | 'build_workshop'
    | 'finish_turn'
    | 'upgrade_building'
    | 'advance_shipping'
    | 'advance_terraforming'
    | 'make_innovation'
    | 'send_scholar'
    | 'power_action'
    | 'sacrifice_power'
    | 'book_action'
    | 'special_action'
    | 'exchange_resources'
    | 'pass'
    | 'choose_science_bonus_books'
    | 'accept_power'
    | 'decline_power'
    | 'choose_town'
    | 'choose_town_books'
    | 'accept_palace_water_town'
    | 'decline_palace_water_town'
    | 'choose_palace'
    | 'place_palace_guild'
    | 'place_annex'
    | 'choose_competency';

export type GameHistoryEntry = {
    id: number;
    sequence: number;
    type: GameActionType;
    payload: Record<string, unknown>;
    stateVersionBefore: number;
    stateVersionAfter: number;
    player: {
        id: number;
        name: string;
    } | null;
    createdAt: string | null;
};

export type GameHistoryPage = {
    data: GameHistoryEntry[];
    hasMore: boolean;
};

export type GamePlayerBoardState = {
    playerId: number;
    victoryPoints: number;
    passOrder: number | null;
    roundBonus: RoundBonus;
    canUseFactionAction: boolean;
    canUseCompetencyAction: boolean;
    canUseRoundBonusAction: boolean;
    scholars: number;
    scholarPoolSize: number;
    scholarDisciplineIds: KnowledgeDiscipline[];
    coins: number;
    tools: number;
    books: {
        banking: number;
        law: number;
        engineering: number;
        medicine: number;
        unassigned: number;
    };
    availableBridges: number;
    competencyIds: Competency[];
    inventionIds: Innovation[];
    palaceId: PalaceAbility | null;
    canUsePalaceAction: boolean;
    activeTownKeys: number;
    usedTownKeys: number;
    townTileIds: TownTile[];
    activeAnnexes: number;
    availableAnnexes: number;
    buildingsOnMap: Record<'workshop' | 'guild' | 'school' | 'university' | 'palace', number>;
    income: {
        tools: number;
        coins: number;
        scholars: number;
        power: number;
        books: number;
        knowledgeSteps: number;
        victoryPoints: number;
    };
    shippingLevel: number;
    canAdvanceShipping: boolean;
    terraformingLevel: number;
    unassignedSpades: number;
    knowledge: {
        banking: number;
        law: number;
        engineering: number;
        medicine: number;
    };
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

export type PlayerColor = 'yellow' | 'red' | 'black' | 'blue' | 'green' | 'brown' | 'grey';

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

export type RoundBonusOffer = {
    roundBonus: RoundBonus;
    coins: number;
};

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

export type FinalRoundScoringTile = 'workshop' | 'guild' | 'school' | 'edge_workshop';

export type BookAction =
    'gain_power' | 'advance_knowledge' | 'gain_coins' | 'upgrade_to_guild' | 'score_guilds' | 'terraform_three_spades';

export type BookActionState = {
    id: BookAction;
    cost: number;
    description: string;
    isUsed: boolean;
};

export type PowerAction =
    'build_bridge' | 'gain_scholar' | 'gain_tools' | 'gain_coins' | 'terraform_one_spade' | 'terraform_two_spades';

export type PowerActionState = {
    id: PowerAction;
    cost: number;
    description: string;
    isUsed: boolean;
};

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

export type InnovationPurchaseState = {
    id: Innovation;
    isAvailable: boolean;
    isAffordable: boolean;
    requiredBooks: Record<KnowledgeDiscipline, number>;
    extraAnyBooks: number;
    totalBooks: number;
    coins: number;
};

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

export type PalaceAbility =
    | 'palace_01'
    | 'palace_02'
    | 'palace_03'
    | 'palace_04'
    | 'palace_05'
    | 'palace_06'
    | 'palace_07'
    | 'palace_08'
    | 'palace_09'
    | 'palace_10'
    | 'palace_11'
    | 'palace_12'
    | 'palace_13'
    | 'palace_14'
    | 'palace_15'
    | 'palace_16'
    | 'palace_17';

export type TownTile = 'tools' | 'terraform' | 'books' | 'coins' | 'knowledge' | 'power' | 'scholar';

export type PlanningBundle = {
    homeland: TerrainType;
    faction: Faction;
    roundBonus: RoundBonus;
};

export type PlanningSelection = {
    playerId: number;
    bundle: PlanningBundle;
};

export type PlanningBundleDescriptions = {
    homelands: Record<TerrainType, string>;
    factions: Record<Faction, string>;
    roundBonuses: Record<RoundBonus, string>;
};

export type KnowledgeDiscipline = 'banking' | 'law' | 'engineering' | 'medicine';

export type PendingInteraction =
    | {
          type: 'choose_starting_resources';
          playerId: number;
          optionIds: KnowledgeDiscipline[];
          context: {
              bookCount: number;
              knowledgeStepCount: number;
              competencyIds?: Competency[];
          };
      }
    | {
          type: 'choose_science_bonus_books';
          playerId: number;
          optionIds: never[];
          context: {
              bookCount: number;
          };
      }
    | {
          type: 'choose_innovation_books';
          playerId: number;
          optionIds: never[];
          context: {
              bookCount: number;
              innovation: Innovation;
              source: 'development_tracks';
          };
      }
    | {
          type: 'place_neutral_building';
          playerId: number;
          optionIds: string[];
          context: {
              innovation?: Innovation;
              competency?: Competency;
              source: 'innovation' | 'competency';
              buildingType: BuildingType;
          };
      }
    | {
          type: 'choose_competency';
          playerId: number;
          optionIds: Competency[];
          context: {
              reason?: 'building';
              builtHexId?: string;
              buildingType?: 'school' | 'university';
          };
      }
    | {
          type: 'choose_palace';
          playerId: number;
          optionIds: PalaceAbility[];
          context: {
              reason: 'building';
              builtHexId: string;
          };
      }
    | {
          type: 'place_palace_guild';
          playerId: number;
          optionIds: string[];
          context: {
              palaceBuiltHexId: string;
              selectedHexId: string | null;
          };
      }
    | {
          type: 'spend_spades';
          playerId: number;
          optionIds: string[];
          context: {
              spadeCount: number;
              targetTerrain: TerrainType;
              selectedHexId?: string;
              terrainBefore?: TerrainType;
              terrainAfter?: TerrainType;
              remainingSpades?: number;
              buildableHexIds?: string[];
              paidTools?: number;
              paidSpadeCount?: number;
              spentSpades?: number;
          };
      }
    | {
          type: 'choose_town';
          playerId: number;
          optionIds: TownTile[];
          context: {
              townHexIds: string[];
              builtHexId: string;
          };
      }
    | {
          type: 'choose_town_books';
          playerId: number;
          optionIds: never[];
          context: {
              bookCount: number;
              builtHexId: string;
          };
      }
    | {
          type: 'offer_palace_water_town';
          playerId: number;
          optionIds: string[];
          context: {
              townsByWaterHexId: Record<string, string[]>;
              builtHexId: string;
              queuedBuiltHexIds?: string[];
          };
      }
    | {
          type: 'place_bridge';
          playerId: number;
          optionIds: string[];
          context: {
              pairs: Array<{ fromHexId: string; toHexId: string }>;
              selectedFromHexId?: string;
              selectedToHexId?: string;
          };
      }
    | {
          type: 'build_workshop_after_terraforming';
          playerId: number;
          optionIds: string[];
          context: {
              toolCost: number;
              coinCost: number;
          };
      }
    | {
          type: 'power_offer';
          playerId: number;
          optionIds: never[];
          context: {
              buildingPlayerId: number;
              builtHexId: string;
              powerAmount: number;
              remainingOffers: Array<{
                  playerId: number;
                  userId: number;
                  powerAmount: number;
              }>;
              queuedBuiltHexIds?: string[];
          };
      };

export type TerrainType = 'desert' | 'plains' | 'swamp' | 'lake' | 'forest' | 'mountain' | 'wasteland' | 'water';

export type BoardHexState = {
    id: string;
    q: number;
    r: number;
    initialTerrain: TerrainType;
    terrain: TerrainType;
    adjacentHexIds: string[];
    riverConnectedHexIds?: string[];
    building: BuildingState | null;
    townId: string | null;
    townTileId: TownTile | null;
};

export type BuildingState = {
    type: 'workshop' | 'guild' | 'school' | 'university' | 'palace' | 'tower' | 'monument';
    ownerPlayerId: number;
    isNeutral: boolean;
    hasAnnex: boolean;
};

export type BuildingType = BuildingState['type'];

export type BuildingUpgradeOption = {
    hexId: string;
    source: BuildingType;
    target: BuildingType;
    tools: number;
    coins: number;
};

export type BoardState = {
    variant: MapVariant;
    riverBankHexIds: string[];
    edgeHexIds: string[];
    bridges?: Array<{
        fromHexId: string;
        toHexId: string;
        ownerPlayerId: number;
    }>;
    hexes: BoardHexState[];
};
