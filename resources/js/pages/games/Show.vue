<script setup lang="ts">
import { Form, Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import GamePlayerController from '@/actions/App/Http/Controllers/GamePlayerController';
import GamePlayerReadinessController from '@/actions/App/Http/Controllers/GamePlayerReadinessController';
import GameStartController from '@/actions/App/Http/Controllers/GameStartController';
import BridgeController from '@/actions/App/Http/Controllers/BridgeController';
import PalaceChoiceController from '@/actions/App/Http/Controllers/PalaceChoiceController';
import PalaceGuildController from '@/actions/App/Http/Controllers/PalaceGuildController';
import PlanningBundleController from '@/actions/App/Http/Controllers/PlanningBundleController';
import StartingBuildingController from '@/actions/App/Http/Controllers/StartingBuildingController';
import StartingCompetencyController from '@/actions/App/Http/Controllers/StartingCompetencyController';
import StartingResourcesController from '@/actions/App/Http/Controllers/StartingResourcesController';
import BoardMap from '@/components/game/BoardMap.vue';
import BookActionDialog from '@/components/game/BookActionDialog.vue';
import BuildingUpgradeDialog from '@/components/game/BuildingUpgradeDialog.vue';
import BuildWorkshopDialog from '@/components/game/BuildWorkshopDialog.vue';
import CompetencySelector from '@/components/game/CompetencySelector.vue';
import CurrentTurnFinishDialog from '@/components/game/CurrentTurnFinishDialog.vue';
import CurrentTurnPanel from '@/components/game/CurrentTurnPanel.vue';
import FactionActionDialog from '@/components/game/FactionActionDialog.vue';
import CultBoard from '@/components/game/CultBoard.vue';
import InnovationBoard from '@/components/game/InnovationBoard.vue';
import PalaceBoard from '@/components/game/PalaceBoard.vue';
import PalaceActionDialog from '@/components/game/PalaceActionDialog.vue';
import PaidTerraformingDialog from '@/components/game/PaidTerraformingDialog.vue';
import PassDialog from '@/components/game/PassDialog.vue';
import ScienceBonusBooksDialog from '@/components/game/ScienceBonusBooksDialog.vue';
import PalaceSelector from '@/components/game/PalaceSelector.vue';
import PlayerBoards from '@/components/game/PlayerBoards.vue';
import PlayerStatsPanel from '@/components/game/PlayerStatsPanel.vue';
import PowerActionDialog from '@/components/game/PowerActionDialog.vue';
import PowerSacrificeDialog from '@/components/game/PowerSacrificeDialog.vue';
import ResourceExchangeDialog from '@/components/game/ResourceExchangeDialog.vue';
import RoundBonusActionDialog from '@/components/game/RoundBonusActionDialog.vue';
import ScholarActionDialog from '@/components/game/ScholarActionDialog.vue';
import TerraformWorkshopDialog from '@/components/game/TerraformWorkshopDialog.vue';
import RoundBonusBoard from '@/components/game/RoundBonusBoard.vue';
import TownTileBoard from '@/components/game/TownTileBoard.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { NumberStepper } from '@/components/ui/number-stepper';
import { factionNames, roundBonusNames, terrainNames } from '@/lib/gameDisplay';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { index } from '@/routes/games';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import bankingRoundUrl from '../../../images/token_parts/coin_round.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import engineeringRoundUrl from '../../../images/token_parts/engineering_round.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import lawRoundUrl from '../../../images/token_parts/law_round.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';
import medicineRoundUrl from '../../../images/token_parts/medicine_round.png';
import type {
    Competency,
    BookActionState,
    Faction,
    GamePlayerSummary,
    GameResource,
    KnowledgeDiscipline,
    MapVariant,
    PalaceAbility,
    PowerActionState,
    RoundBonus,
    TerrainType,
} from '@/types';

const props = defineProps<{
    game: GameResource;
}>();

const page = usePage();

usePoll(3000, { only: ['game'] });

const currentPlayer = computed(() =>
    props.game.data.players.find((player) => player.user.id === page.props.auth.user.id),
);

const activePlayer = computed(() =>
    props.game.data.players.find((player) => player.user.id === props.game.data.activePlayerId),
);

const pendingInteractionPlayer = computed(() =>
    props.game.data.players.find((player) => player.id === props.game.data.pendingInteraction?.playerId),
);

const orderedPlayers = computed(() =>
    props.game.data.turnOrder
        .map((playerId) => props.game.data.players.find((player) => player.id === playerId))
        .filter((player): player is GamePlayerSummary => player !== undefined),
);

const playersWithSelectedFactions = computed(() => orderedPlayers.value.filter((player) => player.faction !== null));

const pageTitle = computed(() => {
    const prefix = {
        lobby: 'Подготовка игры',
        active: 'Игра',
        finished: 'Результаты игры',
        abandoned: 'Прерванная игра',
    }[props.game.data.status];

    return `${prefix} №${props.game.data.id}`;
});

const canChoosePlanningBundle = computed(
    () =>
        props.game.data.status === 'active' &&
        props.game.data.activePlayerId === page.props.auth.user.id &&
        currentPlayer.value?.faction === null,
);

const canChooseStartingResources = computed(
    () =>
        props.game.data.pendingInteraction?.type === 'choose_starting_resources' &&
        props.game.data.pendingInteraction.playerId === currentPlayer.value?.id &&
        props.game.data.activePlayerId === page.props.auth.user.id,
);

const isIncomeResourceDistribution = computed(
    () => props.game.data.phase === 'income'
        && props.game.data.pendingInteraction?.type === 'choose_starting_resources',
);

const allPlanningBundlesChosen = computed(() => props.game.data.players.every((player) => player.faction !== null));

const planningChoicesCompleted = computed(
    () => allPlanningBundlesChosen.value
        && props.game.data.pendingInteraction?.type !== 'choose_starting_resources',
);

const shouldShowPlanningBundleGroup = computed(
    () => props.game.data.status === 'active'
        && (isIncomeResourceDistribution.value
            ? canChooseStartingResources.value
            : !planningChoicesCompleted.value),
);

const isStartingBuildingStage = computed(
    () => props.game.data.phase === 'setup' && planningChoicesCompleted.value,
);

const isOmarStartingTowerTurn = computed(() =>
    activePlayer.value?.faction === 'omar'
    && props.game.data.board.hexes.filter(
        (hex) => hex.building?.ownerPlayerId === activePlayer.value?.id,
    ).length >= 2,
);

const canPlaceStartingBuilding = computed(
    () => isStartingBuildingStage.value
        && props.game.data.activePlayerId === page.props.auth.user.id
        && props.game.data.pendingInteraction === null
        && props.game.data.pendingStartingBuildingHexId === null,
);

const canChooseStartingCompetency = computed(
    () => props.game.data.pendingInteraction?.type === 'choose_competency'
        && props.game.data.pendingInteraction.playerId === currentPlayer.value?.id
        && props.game.data.activePlayerId === page.props.auth.user.id,
);

const canChoosePalace = computed(
    () => props.game.data.pendingInteraction?.type === 'choose_palace'
        && props.game.data.pendingInteraction.playerId === currentPlayer.value?.id
        && props.game.data.activePlayerId === page.props.auth.user.id,
);

const canPlacePalaceGuild = computed(
    () => props.game.data.pendingInteraction?.type === 'place_palace_guild'
        && props.game.data.pendingInteraction.playerId === currentPlayer.value?.id
        && props.game.data.activePlayerId === page.props.auth.user.id,
);

const pendingPalaceGuildHexId = computed(() =>
    props.game.data.pendingInteraction?.type === 'place_palace_guild'
        ? props.game.data.pendingInteraction.context.selectedHexId
        : null,
);

const isBuildingCompetencyChoice = computed(
    () => props.game.data.pendingInteraction?.type === 'choose_competency'
        && props.game.data.pendingInteraction.context.reason === 'building',
);

const canSpendStartingSpade = computed(
    () => props.game.data.pendingInteraction?.type === 'spend_spades'
        && props.game.data.pendingInteraction.playerId === currentPlayer.value?.id
        && props.game.data.activePlayerId === page.props.auth.user.id,
);

const pendingStartingSpadeHexId = computed(() =>
    props.game.data.pendingInteraction?.type === 'spend_spades'
        ? props.game.data.pendingInteraction.context.selectedHexId ?? null
        : null,
);
const selectedBridgeFromHexId = ref<string | null>(null);
const pendingBridgeInteraction = computed(() => props.game.data.pendingInteraction?.type === 'place_bridge'
    ? props.game.data.pendingInteraction
    : null);
const bridgeOffsets = [[1, 1], [-1, -1], [2, -1], [-2, 1], [1, -2], [-1, 2]] as const;
const neighbourOffsets = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]] as const;
const eligibleBridgePairs = computed(() => {
    if (pendingBridgeInteraction.value === null || currentPlayer.value === undefined) {
        return [];
    }

    const hexesById = new Map(props.game.data.board.hexes.map((hex) => [hex.id, hex]));
    const riverBankHexIds = new Set(props.game.data.board.riverBankHexIds);

    return props.game.data.board.hexes.flatMap((fromHex) => {
        if (fromHex.building?.ownerPlayerId !== currentPlayer.value?.id
            || fromHex.building.isNeutral
            || !riverBankHexIds.has(fromHex.id)) {
            return [];
        }

        return bridgeOffsets.flatMap(([qOffset, rOffset]) => {
            const toHex = hexesById.get(`${fromHex.q + qOffset}:${fromHex.r + rOffset}`);

            if (toHex === undefined || toHex.terrain === 'water' || !riverBankHexIds.has(toHex.id)) {
                return [];
            }

            const fromNeighbours = neighbourOffsets.map(
                ([q, r]) => `${fromHex.q + q}:${fromHex.r + r}`,
            );
            const toNeighbours = new Set(neighbourOffsets.map(
                ([q, r]) => `${toHex.q + q}:${toHex.r + r}`,
            ));
            const betweenHexIds = fromNeighbours.filter((hexId) => toNeighbours.has(hexId));
            const hasWaterBetween = betweenHexIds.length === 2
                && betweenHexIds.every((hexId) => hexesById.get(hexId)?.terrain === 'water');
            const bridgeExists = (props.game.data.board.bridges ?? []).some(
                (bridge) => (bridge.fromHexId === fromHex.id && bridge.toHexId === toHex.id)
                    || (bridge.fromHexId === toHex.id && bridge.toHexId === fromHex.id),
            );

            return hasWaterBetween && !bridgeExists
                ? [{ fromHexId: fromHex.id, toHexId: toHex.id }]
                : [];
        });
    });
});
const pendingBridge = computed(() => {
    const interaction = pendingBridgeInteraction.value;

    if (interaction?.context.selectedFromHexId === undefined
        || interaction.context.selectedToHexId === undefined
        || currentPlayer.value === undefined) {
        return null;
    }

    return {
        fromHexId: interaction.context.selectedFromHexId,
        toHexId: interaction.context.selectedToHexId,
        ownerPlayerId: currentPlayer.value.id,
    };
});

watch(
    () => pendingBridgeInteraction.value?.context.selectedFromHexId,
    () => {
        if (pendingBridgeInteraction.value?.context.selectedFromHexId !== undefined
            || pendingBridgeInteraction.value === null) {
            selectedBridgeFromHexId.value = null;
        }
    },
);

const selectableStartingHexIds = computed(() => {
    if (pendingBridgeInteraction.value !== null) {
        if (pendingBridgeInteraction.value.context.selectedFromHexId !== undefined) {
            return [];
        }

        if (selectedBridgeFromHexId.value === null) {
            return [...new Set(eligibleBridgePairs.value.map((pair) => pair.fromHexId))];
        }

        return eligibleBridgePairs.value
            .filter((pair) => pair.fromHexId === selectedBridgeFromHexId.value)
            .map((pair) => pair.toHexId);
    }

    if (canPlacePalaceGuild.value && props.game.data.pendingInteraction?.type === 'place_palace_guild') {
        return pendingPalaceGuildHexId.value === null
            ? props.game.data.pendingInteraction.optionIds
            : [];
    }

    if (canSpendStartingSpade.value && props.game.data.pendingInteraction?.type === 'spend_spades') {
        return pendingStartingSpadeHexId.value === null
            ? props.game.data.pendingInteraction.optionIds
            : [];
    }

    if (canStartPaidTerraforming.value) {
        return [...paidTerraformHexIds.value, ...buildableWorkshopHexIds.value];
    }

    if (!canPlaceStartingBuilding.value || !currentPlayer.value?.homeland) {
        return [];
    }

    return props.game.data.board.hexes
        .filter((hex) => hex.building === null && hex.terrain === currentPlayer.value?.homeland)
        .map((hex) => hex.id);
});

function placeStartingBuilding(hexId: string): void {
    if (pendingBridgeInteraction.value !== null) {
        if (selectedBridgeFromHexId.value === null) {
            selectedBridgeFromHexId.value = hexId;

            return;
        }

        router.post(BridgeController.store.url(props.game.data.id), {
            from_hex_id: selectedBridgeFromHexId.value,
            to_hex_id: hexId,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                selectedBridgeFromHexId.value = null;
            },
        });

        return;
    }

    if (canPlacePalaceGuild.value) {
        router.post(PalaceGuildController.store.url(props.game.data.id), { hex_id: hexId }, {
            preserveScroll: true,
        });

        return;
    }

    if (canSpendStartingSpade.value) {
        selectedPaidTerraformHexId.value = hexId;
        isPaidTerraformingDialogOpen.value = true;

        return;
    }

    if (paidTerraformHexIds.value.includes(hexId)) {
        selectedPaidTerraformHexId.value = hexId;
        isPaidTerraformingDialogOpen.value = true;

        return;
    }

    if (buildableWorkshopHexIds.value.includes(hexId)) {
        selectedBuildWorkshopHexId.value = hexId;
        isBuildWorkshopDialogOpen.value = true;

        return;
    }

    if (!canPlaceStartingBuilding.value) {
        return;
    }

    router.post(StartingBuildingController.store.url(props.game.data.id), { hex_id: hexId }, {
        preserveScroll: true,
    });
}

const startingBookCounts = reactive<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const startingKnowledgeCounts = reactive<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const selectedStartingCompetency = ref<Competency | null>(null);
const selectedMonkCompetency = ref<Competency | null>(null);
const selectedPalace = ref<PalaceAbility | null>(null);
const isPlanningBundleGroupOpen = ref(true);
const isPowerSacrificeDialogOpen = ref(false);
const isPowerActionDialogOpen = ref(false);
const isBookActionDialogOpen = ref(false);
const isResourceExchangeDialogOpen = ref(false);
const isCurrentTurnFinishDialogOpen = ref(false);
const isBuildingUpgradeDialogOpen = ref(false);
const isScholarActionDialogOpen = ref(false);
const isRoundBonusActionDialogOpen = ref(false);
const isFactionActionDialogOpen = ref(false);
const isPalaceActionDialogOpen = ref(false);
const isPassDialogOpen = ref(false);
const isPaidTerraformingDialogOpen = ref(false);
const isBuildWorkshopDialogOpen = ref(false);
const selectedPaidTerraformHexId = ref<string | null>(null);
const selectedBuildWorkshopHexId = ref<string | null>(null);
const selectedBuildingUpgradeHexId = ref<string | null>(null);
const selectedPowerAction = ref<PowerActionState | null>(null);
const selectedBookAction = ref<BookActionState | null>(null);
const selectedScholarDiscipline = ref<KnowledgeDiscipline | null>(null);

const currentPlayerState = computed(() =>
    props.game.data.playerBoardStates.find((state) => state.playerId === currentPlayer.value?.id),
);
const currentRoundBonusDescription = computed(() => currentPlayerState.value
    ? props.game.data.roundBonusDescriptions[currentPlayerState.value.roundBonus]
    : '');

const maximumPowerSacrifice = computed(() =>
    Math.floor((currentPlayerState.value?.power.bowlTwo ?? 0) / 2),
);
const selectedScholarDisciplineOccupiedSlots = computed(() =>
    selectedScholarDiscipline.value === null
        ? 0
        : props.game.data.playerBoardStates.reduce(
            (total, state) => total + state.scholarDisciplineIds.filter(
                (discipline) => discipline === selectedScholarDiscipline.value,
            ).length,
            0,
        ),
);

function selectScholarDiscipline(discipline: KnowledgeDiscipline): void {
    if (!props.game.data.canSendScholar) {
        return;
    }

    selectedScholarDiscipline.value = discipline;
    isScholarActionDialogOpen.value = true;
}

const canSacrificePower = computed(
    () => props.game.data.phase === 'actions'
        && props.game.data.activePlayerId === page.props.auth.user.id
        && props.game.data.pendingInteraction === null
        && maximumPowerSacrifice.value > 0,
);

const canExchangeResources = computed(
    () => props.game.data.phase === 'actions'
        && props.game.data.activePlayerId === page.props.auth.user.id
        && props.game.data.pendingInteraction === null,
);

const canStartPaidTerraforming = computed(() => {
    const state = currentPlayerState.value;

    return props.game.data.canPass
        && state !== undefined;
});
const reachableEmptyLandHexIds = computed(() => {
    const player = currentPlayer.value;
    const playerState = currentPlayerState.value;

    if (!canStartPaidTerraforming.value || player === undefined || playerState === undefined) {
        return [];
    }

    const hexesById = new Map(props.game.data.board.hexes.map((hex) => [hex.id, hex]));
    const reachableHexIds = new Set<string>();
    let waterFrontier: string[] = [];

    props.game.data.board.hexes.forEach((hex) => {
        if (hex.building?.ownerPlayerId === player.id) {
            hex.adjacentHexIds.forEach((hexId) => {
                if (hexesById.get(hexId)?.terrain === 'water') {
                    waterFrontier.push(hexId);
                } else {
                    reachableHexIds.add(hexId);
                }
            });
        }
    });

    const visitedWaterHexIds = new Set<string>();

    const navigationRange = playerState.shippingLevel
        + (playerState.roundBonus === 'river_workshop' ? 1 : 0);

    for (let distance = 1; distance <= navigationRange && waterFrontier.length > 0; distance++) {
        const nextWaterFrontier: string[] = [];

        [...new Set(waterFrontier)].forEach((hexId) => {
            if (visitedWaterHexIds.has(hexId)) {
                return;
            }

            visitedWaterHexIds.add(hexId);
            hexesById.get(hexId)?.adjacentHexIds.forEach((adjacentHexId) => {
                if (hexesById.get(adjacentHexId)?.terrain === 'water') {
                    nextWaterFrontier.push(adjacentHexId);
                } else {
                    reachableHexIds.add(adjacentHexId);
                }
            });
        });

        waterFrontier = nextWaterFrontier;
    }

    return [...reachableHexIds].filter((hexId) => {
        const hex = hexesById.get(hexId);

        return hex !== undefined
            && hex.building === null
            && hex.terrain !== 'water';
    });
});
const paidTerraformHexIds = computed(() => reachableEmptyLandHexIds.value.filter((hexId) =>
    props.game.data.board.hexes.find((hex) => hex.id === hexId)?.terrain !== currentPlayer.value?.homeland,
));
const buildableWorkshopHexIds = computed(() => {
    const state = currentPlayerState.value;

    if (state === undefined
        || state.tools < 1
        || state.coins < 2
        || state.buildingsOnMap.workshop >= 9) {
        return [];
    }

    return reachableEmptyLandHexIds.value.filter((hexId) =>
        props.game.data.board.hexes.find((hex) => hex.id === hexId)?.terrain === currentPlayer.value?.homeland,
    );
});
const selectedPaidTerraformHex = computed(() => props.game.data.board.hexes.find(
    (hex) => hex.id === selectedPaidTerraformHexId.value,
));

const availableActionsBeforePass = computed(() => {
    const state = currentPlayerState.value;

    if (state === undefined) {
        return [];
    }

    const actions: string[] = [];
    const availablePower = state.power.bowlThree + Math.floor(state.power.bowlTwo / 2);

    if (props.game.data.powerActions.some((action) => !action.isUsed && action.cost <= availablePower)) {
        actions.push('действие за Силу');
    }

    const maximumBooks = Math.max(state.books.banking, state.books.law, state.books.engineering, state.books.medicine);

    if (props.game.data.bookActionStates.some((action) => !action.isUsed && action.cost <= maximumBooks)) {
        actions.push('действие за книги');
    }

    if (props.game.data.buildingUpgrades.length > 0) {
        actions.push('улучшение здания');
    }

    if (buildableWorkshopHexIds.value.length > 0) {
        actions.push('строительство дома');
    }

    if (props.game.data.canSendScholar) {
        actions.push('отправка учёного');
    }

    if (state.canUseFactionAction) {
        actions.push('действие расы');
    }

    if (state.canUseRoundBonusAction) {
        actions.push('действие бонуса раунда');
    }

    if (state.canUsePalaceAction) {
        actions.push('действие жетона Дворца');
    }

    return actions;
});

function selectPowerAction(action: PowerActionState): void {
    selectedPowerAction.value = action;
    isPowerActionDialogOpen.value = true;
}

function selectBookAction(action: BookActionState): void {
    selectedBookAction.value = action;
    isBookActionDialogOpen.value = true;
}

const selectedBuildingUpgradeOptions = computed(() =>
    props.game.data.buildingUpgrades.filter(
        (option) => option.hexId === selectedBuildingUpgradeHexId.value,
    ),
);

function selectBuildingUpgrade(hexId: string): void {
    selectedBuildingUpgradeHexId.value = hexId;
    isBuildingUpgradeDialogOpen.value = true;
}

const availableStartingBookCount = computed(() => props.game.data.pendingInteraction?.context.bookCount ?? 0);

const assignedStartingBookCount = computed(() =>
    Object.values(startingBookCounts).reduce((total, count) => total + count, 0),
);

const remainingStartingBookCount = computed(() =>
    Math.max(0, availableStartingBookCount.value - assignedStartingBookCount.value),
);

const availableStartingKnowledgeStepCount = computed(
    () => props.game.data.pendingInteraction?.context.knowledgeStepCount ?? 0,
);

const assignedStartingKnowledgeStepCount = computed(() =>
    Object.values(startingKnowledgeCounts).reduce((total, count) => total + count, 0),
);

const remainingStartingKnowledgeStepCount = computed(() =>
    Math.max(0, availableStartingKnowledgeStepCount.value - assignedStartingKnowledgeStepCount.value),
);

const requiresStartingCompetency = computed(
    () => (props.game.data.pendingInteraction?.context.competencyIds?.length ?? 0) > 0,
);

watch(
    () => props.game.data.pendingInteraction?.playerId,
    () => {
        for (const discipline of Object.keys(startingBookCounts) as KnowledgeDiscipline[]) {
            startingBookCounts[discipline] = 0;
            startingKnowledgeCounts[discipline] = 0;
        }

        selectedStartingCompetency.value = null;
        selectedMonkCompetency.value = null;
        selectedPalace.value = null;
    },
);

defineOptions({
    layout: {
        fullWidth: true,
        breadcrumbs: [
            {
                title: 'Игры',
                href: index(),
            },
        ],
    },
});

const mapVariantNames: Record<MapVariant, string> = {
    one_to_three_players: '1–3 игрока',
    three_to_five_players: '3–5 игроков',
};

const terrainBundleClasses: Record<TerrainType, string> = {
    desert: 'border-yellow-500/60 bg-yellow-400/25 dark:bg-yellow-400/20',
    plains: 'border-amber-800/60 bg-amber-800/20 dark:bg-amber-600/20',
    swamp: 'border-zinc-700/60 bg-zinc-900/20 dark:bg-zinc-400/15',
    lake: 'border-blue-500/60 bg-blue-500/20 dark:bg-blue-500/20',
    forest: 'border-green-600/60 bg-green-600/20 dark:bg-green-500/20',
    mountain: 'border-gray-500/60 bg-gray-500/20 dark:bg-gray-400/15',
    wasteland: 'border-red-500/60 bg-red-500/20 dark:bg-red-500/20',
    water: 'border-cyan-500/60 bg-cyan-500/20 dark:bg-cyan-500/20',
};

const terrainTileImages = import.meta.glob('../../../images/terrain_tiles/*.webp', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const factionImages = import.meta.glob('../../../images/factions/*.jpg', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const roundBonusImages = import.meta.glob('../../../images/round_bonus_cards/*_top.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const competencyImages = import.meta.glob('../../../images/competencies/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const knowledgeDisciplineNames = computed(() => props.game.data.knowledgeDisciplineNames);
const bookImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};

const knowledgeRoundImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingRoundUrl,
    law: lawRoundUrl,
    engineering: engineeringRoundUrl,
    medicine: medicineRoundUrl,
};

function planningBundleButtonLabel(processing: boolean): string {
    if (processing) {
        return 'Выбор…';
    }

    if (allPlanningBundlesChosen.value) {
        return 'Выбор завершён';
    }

    return canChoosePlanningBundle.value ? 'Выбрать комплект' : 'Сейчас выбирает другой игрок';
}

function terrainTileImage(terrain: TerrainType): string {
    const fileName = terrain === 'wasteland' ? 'westland' : terrain;

    return terrainTileImages[`../../../images/terrain_tiles/${fileName}.webp`];
}

function factionImage(faction: Faction): string {
    return factionImages[`../../../images/factions/${faction}.jpg`];
}

function roundBonusImage(roundBonus: RoundBonus): string {
    return roundBonusImages[`../../../images/round_bonus_cards/${roundBonus}_top.png`];
}

function competencyImage(competency: Competency): string {
    return competencyImages[`../../../images/competencies/${competency}.png`];
}

function selectedPlayerForHomeland(homeland: TerrainType): GamePlayerSummary | undefined {
    const selection = props.game.data.planningSelections.find((selection) => selection.bundle.homeland === homeland);

    return props.game.data.players.find((player) => player.id === selection?.playerId);
}

function selectedCompetencyForHomeland(homeland: TerrainType): Competency | undefined {
    const player = selectedPlayerForHomeland(homeland);
    const playerState = props.game.data.playerBoardStates.find((state) => state.playerId === player?.id);

    return playerState?.competencyIds[0];
}

</script>

<template>
    <Head :title="pageTitle" />

    <div class="flex h-full min-w-0 flex-1">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4">
            <Card v-if="game.data.status === 'lobby'">
                <CardHeader>
                    <CardTitle>Участники</CardTitle>
                    <CardDescription> Игроки занимают места в порядке присоединения. </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-3">
                    <div
                        v-for="player in game.data.players"
                        :key="player.id"
                        class="flex items-center justify-between gap-4 rounded-lg border p-3"
                    >
                        <div>
                            <p class="font-medium">{{ player.user.name }}</p>
                            <p class="text-sm text-muted-foreground">Место {{ player.seat }}</p>
                        </div>

                        <span class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium">
                            {{ player.isReady ? 'Готов' : 'Не готов' }}
                        </span>
                    </div>

                    <div
                        v-for="seat in game.data.maxPlayers - game.data.playersCount"
                        :key="`empty-${seat}`"
                        class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
                    >
                        Свободное место
                    </div>

                    <div
                        v-if="currentPlayer || game.data.playersCount < game.data.maxPlayers || game.data.isOwner"
                        class="flex flex-wrap items-start justify-end gap-3 border-t pt-3"
                    >
                        <Form
                            v-if="currentPlayer"
                            v-bind="
                                GamePlayerReadinessController.update.form({
                                    game: game.data.id,
                                    gamePlayer: currentPlayer.id,
                                })
                            "
                            #default="{ errors, processing }"
                            class="grid gap-2"
                        >
                            <input type="hidden" name="is_ready" :value="currentPlayer.isReady ? '0' : '1'" />
                            <InputError :message="errors.is_ready" />
                            <Button
                                type="submit"
                                :variant="currentPlayer.isReady ? 'outline' : 'default'"
                                :disabled="processing"
                            >
                                {{
                                    processing
                                        ? 'Сохранение…'
                                        : currentPlayer.isReady
                                          ? 'Отменить готовность'
                                          : 'Я готов'
                                }}
                            </Button>
                        </Form>

                        <Form
                            v-if="!currentPlayer && game.data.playersCount < game.data.maxPlayers"
                            v-bind="GamePlayerController.store.form(game.data.id)"
                            #default="{ errors, processing }"
                            class="grid gap-2"
                        >
                            <InputError :message="errors.game" />
                            <Button type="submit" :disabled="processing">
                                {{ processing ? 'Присоединение…' : 'Присоединиться' }}
                            </Button>
                        </Form>

                        <Form
                            v-if="game.data.isOwner"
                            v-bind="GameStartController.form(game.data.id)"
                            #default="{ errors, processing }"
                            class="grid gap-2"
                        >
                            <InputError :message="errors.game" />
                            <Button type="submit" :disabled="processing || !game.data.canStart">
                                {{ processing ? 'Запуск…' : 'Начать игру' }}
                            </Button>
                        </Form>
                    </div>
                </CardContent>
            </Card>

            <CurrentTurnPanel
                :game="game"
                :active-player="activePlayer"
                :current-player="currentPlayer"
                :current-user-id="page.props.auth.user.id"
                :is-starting-building-stage="isStartingBuildingStage"
                :is-omar-starting-tower-turn="isOmarStartingTowerTurn"
                :can-spend-starting-spade="canSpendStartingSpade"
                :pending-starting-spade-hex-id="pendingStartingSpadeHexId"
                :pending-palace-guild-hex-id="pendingPalaceGuildHexId"
                :selected-bridge-from-hex-id="selectedBridgeFromHexId"
                @reset-bridge-selection="selectedBridgeFromHexId = null"
                @finish-turn="isCurrentTurnFinishDialogOpen = true"
                @pass="isPassDialogOpen = true"
            />

            <Collapsible
                v-if="shouldShowPlanningBundleGroup"
                v-model:open="isPlanningBundleGroupOpen"
            >
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between gap-4">
                            <CardTitle>
                                {{ isIncomeResourceDistribution ? 'Распределение дохода' : 'Выбор стартового комплекта' }}
                            </CardTitle>
                            <CollapsibleTrigger as-child>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        isPlanningBundleGroupOpen
                                            ? 'Свернуть выбор комплекта'
                                            : 'Развернуть выбор комплекта'
                                    "
                                >
                                    <ChevronDown
                                        class="size-5 transition-transform"
                                        :class="isPlanningBundleGroupOpen ? 'rotate-180' : ''"
                                    />
                                </Button>
                            </CollapsibleTrigger>
                        </div>
                        <ol v-if="!isIncomeResourceDistribution" class="flex flex-wrap items-center gap-2 text-sm font-medium">
                            <template v-for="(player, index) in orderedPlayers" :key="player.id">
                                <li :class="player.user.id === game.data.activePlayerId ? 'text-primary' : ''">
                                    {{ player.user.name }}
                                </li>
                                <li
                                    v-if="index < orderedPlayers.length - 1"
                                    aria-hidden="true"
                                    class="text-muted-foreground"
                                >
                                    →
                                </li>
                            </template>
                        </ol>
                        <CardDescription v-if="game.data.pendingInteraction?.type === 'choose_starting_resources'">
                            Сейчас ресурсы распределяет
                            {{ pendingInteractionPlayer?.user.name ?? 'игрок' }}.
                        </CardDescription>
                        <CardDescription v-else-if="canChoosePlanningBundle">
                            Выберите родную местность, сообщество и бонус раунда.
                        </CardDescription>
                        <CardDescription v-else-if="allPlanningBundlesChosen">
                            Все игроки выбрали стартовые комплекты.
                        </CardDescription>
                        <CardDescription v-else-if="currentPlayer?.faction">
                            Ваш комплект выбран. Ожидаем остальных игроков.
                        </CardDescription>
                        <CardDescription v-else>
                            Сейчас выбирает
                            {{ activePlayer?.user.name ?? 'другой игрок' }}.
                        </CardDescription>
                    </CardHeader>

                    <CollapsibleContent>
                        <CardContent>
                            <Form
                                v-if="canChooseStartingResources"
                                v-bind="StartingResourcesController.store.form(game.data.id)"
                                id="starting-resources-form"
                                #default="{ errors, processing }"
                                class="mb-6 grid w-xl gap-5 rounded-xl border border-primary/40 bg-primary/5 p-5"
                            >
                                <div class="grid gap-1">
                                    <h3 class="font-semibold">
                                        {{ isIncomeResourceDistribution ? 'Распределите полученный доход' : 'Распределите стартовые ресурсы' }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ isIncomeResourceDistribution
                                            ? 'После распределения доход автоматически перейдёт к следующему игроку.'
                                            : 'Этот выбор завершает получение вашего стартового комплекта.' }}
                                    </p>
                                </div>

                                <div
                                    v-if="(game.data.pendingInteraction?.context.bookCount ?? 0) > 0"
                                    class="grid gap-3"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <p class="font-medium">Распределение книг</p>
                                        <p class="rounded-md bg-background/75 px-3 py-1.5 font-medium">
                                            Доступно: {{ availableStartingBookCount }}
                                        </p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        <label
                                            v-for="discipline in game.data.pendingInteraction?.optionIds"
                                            :key="discipline"
                                            class="grid h-full grid-rows-[auto_minmax(2.5rem,1fr)_auto_auto] justify-items-center gap-2 rounded-lg border bg-background/70 p-3 text-center text-sm font-medium"
                                        >
                                            <img
                                                :src="bookImages[discipline]"
                                                :alt="`Книга: ${knowledgeDisciplineNames[discipline]}`"
                                                class="h-16 w-auto object-contain drop-shadow-md"
                                            />
                                            <span>{{ knowledgeDisciplineNames[discipline] }}</span>
                                            <NumberStepper
                                                v-model="startingBookCounts[discipline]"
                                                :name="`book_counts[${discipline}]`"
                                                :min="0"
                                                :max="startingBookCounts[discipline] + remainingStartingBookCount"
                                                required
                                                class="w-28 self-end"
                                            />
                                            <InputError :message="errors[`book_counts.${discipline}`]" />
                                        </label>
                                    </div>
                                    <InputError :message="errors.book_counts" />
                                </div>

                                <div
                                    v-if="(game.data.pendingInteraction?.context.competencyIds?.length ?? 0) > 0"
                                    class="grid gap-3"
                                >
                                    <p class="text-sm font-medium">Выберите стартовую компетенцию</p>
                                    <input
                                        type="hidden"
                                        name="competency_id"
                                        :value="selectedStartingCompetency ?? ''"
                                    />
                                    <CompetencySelector
                                        v-model="selectedStartingCompetency"
                                        :competencies="game.data.pendingInteraction?.context.competencyIds ?? []"
                                        :descriptions="game.data.competencyDescriptions"
                                    />
                                    <InputError :message="errors.competency_id" />
                                </div>

                                <div
                                    v-if="(game.data.pendingInteraction?.context.knowledgeStepCount ?? 0) > 0"
                                    class="grid gap-3"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <p class="font-medium">Распределение шагов знаний</p>
                                        <p class="rounded-md bg-background/75 px-3 py-1.5 font-medium">
                                            Доступно: {{ availableStartingKnowledgeStepCount }}
                                        </p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        <label
                                            v-for="discipline in game.data.pendingInteraction?.optionIds"
                                            :key="discipline"
                                            class="grid h-full grid-rows-[auto_minmax(2.5rem,1fr)_auto_auto] justify-items-center gap-2 rounded-lg border bg-background/70 p-3 text-center text-sm font-medium"
                                        >
                                            <img
                                                :src="knowledgeRoundImages[discipline]"
                                                :alt="`Дисциплина: ${knowledgeDisciplineNames[discipline]}`"
                                                class="h-16 w-auto object-contain drop-shadow-md"
                                            />
                                            <span>{{ knowledgeDisciplineNames[discipline] }}</span>
                                            <NumberStepper
                                                v-model="startingKnowledgeCounts[discipline]"
                                                :name="`knowledge_counts[${discipline}]`"
                                                :min="0"
                                                :max="
                                                    startingKnowledgeCounts[discipline] +
                                                    remainingStartingKnowledgeStepCount
                                                "
                                                required
                                                class="w-28 self-end"
                                            />
                                            <InputError :message="errors[`knowledge_counts.${discipline}`]" />
                                        </label>
                                    </div>
                                    <InputError :message="errors.knowledge_counts" />
                                </div>

                                <InputError :message="errors.game" />
                                <Dialog>
                                    <DialogTrigger as-child>
                                        <Button
                                            type="button"
                                            :disabled="
                                                processing ||
                                                remainingStartingBookCount !== 0 ||
                                                remainingStartingKnowledgeStepCount !== 0 ||
                                                (requiresStartingCompetency && selectedStartingCompetency === null)
                                            "
                                        >
                                            Подтвердить выбор
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Подтвердите распределение ресурсов</DialogTitle>
                                            <DialogDescription>
                                                Проверьте выбранные стартовые ресурсы перед сохранением.
                                            </DialogDescription>
                                        </DialogHeader>

                                        <div class="grid gap-4 rounded-lg bg-muted p-4 text-sm">
                                            <div v-if="availableStartingBookCount > 0" class="grid gap-1">
                                                <p class="font-medium">Книги</p>
                                                <p
                                                    v-for="discipline in game.data.pendingInteraction?.optionIds ?? []"
                                                    v-show="startingBookCounts[discipline] > 0"
                                                    :key="`book-${discipline}`"
                                                    class="text-muted-foreground"
                                                >
                                                    {{ knowledgeDisciplineNames[discipline] }}:
                                                    {{ startingBookCounts[discipline] }}
                                                </p>
                                            </div>

                                            <div v-if="availableStartingKnowledgeStepCount > 0" class="grid gap-1">
                                                <p class="font-medium">Шаги знаний</p>
                                                <p
                                                    v-for="discipline in game.data.pendingInteraction?.optionIds ?? []"
                                                    v-show="startingKnowledgeCounts[discipline] > 0"
                                                    :key="`knowledge-${discipline}`"
                                                    class="text-muted-foreground"
                                                >
                                                    {{ knowledgeDisciplineNames[discipline] }}:
                                                    {{ startingKnowledgeCounts[discipline] }}
                                                </p>
                                            </div>

                                            <div v-if="selectedStartingCompetency" class="grid gap-2">
                                                <p class="font-medium">Компетенция</p>
                                                <div class="flex items-center gap-3 text-muted-foreground">
                                                    <img
                                                        :src="competencyImage(selectedStartingCompetency)"
                                                        :alt="`Компетенция ${selectedStartingCompetency}`"
                                                        class="h-14 w-14 object-contain drop-shadow-md"
                                                    />
                                                    <span>Компетенция {{ selectedStartingCompetency.slice(-2) }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <DialogFooter class="gap-2">
                                            <DialogClose as-child>
                                                <Button type="button" variant="outline">Отмена</Button>
                                            </DialogClose>
                                            <Button type="submit" form="starting-resources-form" :disabled="processing">
                                                {{ processing ? 'Сохранение…' : 'Подтвердить' }}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </Form>

                            <div v-if="!isIncomeResourceDistribution" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <Form
                                    v-for="bundle in game.data.planningBundles"
                                    :key="bundle.homeland"
                                    v-bind="PlanningBundleController.store.form(game.data.id)"
                                    :id="`planning-bundle-${bundle.homeland}`"
                                    #default="{ errors, processing }"
                                    :class="[
                                        'flex flex-col gap-4 rounded-xl border p-4 shadow-sm',
                                        terrainBundleClasses[bundle.homeland],
                                    ]"
                                >
                                    <input type="hidden" name="homeland" :value="bundle.homeland" />

                                    <TooltipProvider :delay-duration="150">
                                        <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <img
                                                        :src="terrainTileImage(bundle.homeland)"
                                                        :alt="`Родная местность: ${terrainNames[bundle.homeland]}`"
                                                        tabindex="0"
                                                        class="h-48 w-auto cursor-help rounded-md object-contain shadow-sm"
                                                    />
                                                </TooltipTrigger>
                                                <TooltipContent class="max-w-xs">
                                                    <p class="font-semibold">{{ terrainNames[bundle.homeland] }}</p>
                                                    <p>
                                                        {{
                                                            game.data.planningBundleDescriptions.homelands[
                                                                bundle.homeland
                                                            ]
                                                        }}
                                                    </p>
                                                </TooltipContent>
                                            </Tooltip>

                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <div
                                                        tabindex="0"
                                                        class="relative aspect-[592/338] w-full max-w-[21rem] min-w-0 cursor-help justify-self-center"
                                                    >
                                                        <img
                                                            :src="factionImage(bundle.faction)"
                                                            :alt="`Сообщество: ${factionNames[bundle.faction]}`"
                                                            class="size-full rounded-md object-cover shadow-sm"
                                                        />
                                                        <img
                                                            v-if="selectedCompetencyForHomeland(bundle.homeland)"
                                                            :src="
                                                                competencyImage(
                                                                    selectedCompetencyForHomeland(bundle.homeland)!,
                                                                )
                                                            "
                                                            :alt="`Выбранная компетенция ${selectedCompetencyForHomeland(bundle.homeland)}`"
                                                            class="absolute top-0 right-0 size-16 rounded-md object-contain p-1 shadow-md"
                                                        />
                                                    </div>
                                                </TooltipTrigger>
                                                <TooltipContent class="max-w-xs">
                                                    <p class="font-semibold">{{ factionNames[bundle.faction] }}</p>
                                                    <p>
                                                        {{
                                                            game.data.planningBundleDescriptions.factions[
                                                                bundle.faction
                                                            ]
                                                        }}
                                                    </p>
                                                </TooltipContent>
                                            </Tooltip>

                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <img
                                                        :src="roundBonusImage(bundle.roundBonus)"
                                                        :alt="`Бонус раунда: ${roundBonusNames[bundle.roundBonus]}`"
                                                        tabindex="0"
                                                        class="h-48 w-auto cursor-help object-contain drop-shadow-sm"
                                                    />
                                                </TooltipTrigger>
                                                <TooltipContent class="max-w-xs">
                                                    <p class="font-semibold">
                                                        {{ roundBonusNames[bundle.roundBonus] }}
                                                    </p>
                                                    <p>
                                                        {{
                                                            game.data.planningBundleDescriptions.roundBonuses[
                                                                bundle.roundBonus
                                                            ]
                                                        }}
                                                    </p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </div>
                                    </TooltipProvider>

                                    <InputError :message="errors.homeland ?? errors.game" />
                                    <div
                                        v-if="selectedPlayerForHomeland(bundle.homeland)"
                                        class="mt-auto flex min-h-10 items-center justify-center gap-3 rounded-md bg-background/75 px-4 py-2 text-center text-sm font-medium shadow-xs"
                                    >
                                        {{ selectedPlayerForHomeland(bundle.homeland)?.user.name }}
                                    </div>
                                    <Dialog v-else>
                                        <DialogTrigger as-child>
                                            <Button
                                                type="button"
                                                class="mt-auto w-full"
                                                :disabled="processing || !canChoosePlanningBundle"
                                            >
                                                {{ planningBundleButtonLabel(processing) }}
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>Подтвердите выбор комплекта</DialogTitle>
                                                <DialogDescription>
                                                    После подтверждения этот комплект будет закреплён за вами.
                                                </DialogDescription>
                                            </DialogHeader>

                                            <div class="grid gap-2 rounded-lg bg-muted p-4 text-sm">
                                                <p>
                                                    <span class="text-muted-foreground">Земля:</span>
                                                    {{ terrainNames[bundle.homeland] }}
                                                </p>
                                                <p>
                                                    <span class="text-muted-foreground">Раса:</span>
                                                    {{ factionNames[bundle.faction] }}
                                                </p>
                                                <p>
                                                    <span class="text-muted-foreground">Бонус раунда:</span>
                                                    {{ roundBonusNames[bundle.roundBonus] }}
                                                </p>
                                            </div>

                                            <DialogFooter class="gap-2">
                                                <DialogClose as-child>
                                                    <Button type="button" variant="outline">Отмена</Button>
                                                </DialogClose>
                                                <Button
                                                    type="submit"
                                                    :form="`planning-bundle-${bundle.homeland}`"
                                                    :disabled="processing"
                                                >
                                                    {{ processing ? 'Выбор…' : 'Подтвердить' }}
                                                </Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                </Form>
                            </div>
                        </CardContent>
                    </CollapsibleContent>
                </Card>
            </Collapsible>

            <Card
                v-if="canChooseStartingCompetency && game.data.pendingInteraction?.type === 'choose_competency'"
                class="mx-auto w-full max-w-3xl border-primary/40"
            >
                <CardHeader>
                    <CardTitle>
                        {{ isBuildingCompetencyChoice ? 'Компетенция нового здания' : 'Стартовая компетенция монахов' }}
                    </CardTitle>
                    <CardDescription>
                        {{ isBuildingCompetencyChoice
                            ? 'Выберите компетенцию для построенной школы или университета.'
                            : 'Выберите компетенцию. Вы сразу получите её книги, продвижение по дисциплине и ресурсы.' }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="StartingCompetencyController.store.form(game.data.id)"
                        #default="{ errors, processing }"
                        class="grid gap-4"
                    >
                        <input
                            type="hidden"
                            name="competency_id"
                            :value="selectedMonkCompetency ?? ''"
                        />
                        <CompetencySelector
                            v-model="selectedMonkCompetency"
                            :competencies="game.data.pendingInteraction.optionIds"
                            :descriptions="game.data.competencyDescriptions"
                            :disabled="!canChooseStartingCompetency || processing"
                        />
                        <InputError :message="errors.competency_id" />
                        <Button
                            type="submit"
                            class="justify-self-end"
                            :disabled="
                                !canChooseStartingCompetency ||
                                selectedMonkCompetency === null ||
                                processing
                            "
                        >
                            {{ processing ? 'Подтверждение…' : 'Подтвердить выбор' }}
                        </Button>
                    </Form>
                </CardContent>
            </Card>

            <Card
                v-if="canChoosePalace && game.data.pendingInteraction?.type === 'choose_palace'"
                class="mx-auto w-full max-w-5xl border-primary/40"
            >
                <CardHeader>
                    <CardTitle>Жетон Дворца</CardTitle>
                    <CardDescription>
                        Выберите один из доступных жетонов для построенного Дворца.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="PalaceChoiceController.form(game.data.id)"
                        #default="{ errors, processing }"
                        class="grid gap-4"
                    >
                        <input type="hidden" name="palace_id" :value="selectedPalace ?? ''" />
                        <PalaceSelector
                            v-model="selectedPalace"
                            :palaces="game.data.pendingInteraction.optionIds"
                            :descriptions="game.data.palaceDescriptions"
                            :disabled="processing"
                        />
                        <InputError :message="errors.palace_id" />
                        <Button
                            type="submit"
                            class="justify-self-end"
                            :disabled="selectedPalace === null || processing"
                        >
                            {{ processing ? 'Подтверждение…' : 'Подтвердить выбор' }}
                        </Button>
                    </Form>
                </CardContent>
            </Card>

            <section v-if="game.data.status === 'active'" class="grid gap-4">
                <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,7fr)_minmax(16rem,3fr)]">
                    <div class="grid gap-4">
                        <BoardMap
                            :board="game.data.board"
                            :players="game.data.players"
                            :selectable-hex-ids="selectableStartingHexIds"
                            :pending-hex-id="game.data.pendingStartingBuildingHexId ?? pendingStartingSpadeHexId ?? pendingPalaceGuildHexId ?? selectedBridgeFromHexId"
                            :pending-bridge="pendingBridge"
                            :current-round="game.data.currentRound"
                            :round-scoring-tiles="game.data.roundScoringTiles"
                            :final-round-scoring-tile="game.data.finalRoundScoringTile"
                            :book-actions="game.data.bookActions"
                            :used-book-action-ids="game.data.usedBookActionIds"
                            :book-action-states="game.data.bookActionStates"
                            :power-actions="game.data.powerActions"
                            :can-use-power-actions="canExchangeResources"
                            :can-use-book-actions="canExchangeResources"
                            :upgradeable-building-hex-ids="game.data.buildingUpgrades.map((option) => option.hexId)"
                            @hex-click="placeStartingBuilding"
                            @power-action-click="selectPowerAction"
                            @book-action-click="selectBookAction"
                            @building-click="selectBuildingUpgrade"
                        />

                        <PlayerBoards
                            v-if="playersWithSelectedFactions.length > 0"
                            :players="playersWithSelectedFactions"
                            :player-states="game.data.playerBoardStates"
                            :current-user-id="page.props.auth.user.id"
                            :round-bonus-descriptions="game.data.roundBonusDescriptions"
                            :competency-descriptions="game.data.competencyDescriptions"
                            :palace-descriptions="game.data.palaceDescriptions"
                            :can-sacrifice-power="canSacrificePower"
                            :can-exchange-resources="canExchangeResources"
                            :can-use-round-bonus-action="canExchangeResources"
                            :can-use-faction-action="canExchangeResources"
                            :can-use-palace-action="canExchangeResources"
                            @sacrifice-power="isPowerSacrificeDialogOpen = true"
                            @exchange-resources="isResourceExchangeDialogOpen = true"
                            @use-round-bonus-action="isRoundBonusActionDialogOpen = true"
                            @use-faction-action="isFactionActionDialogOpen = true"
                            @use-palace-action="isPalaceActionDialogOpen = true"
                        />
                    </div>

                    <aside class="grid gap-4">
                        <CultBoard
                            :players="orderedPlayers"
                            :player-states="game.data.playerBoardStates"
                            :can-send-scholar="game.data.canSendScholar"
                            @send-scholar="selectScholarDiscipline"
                        />
                        <RoundBonusBoard
                            :offers="game.data.roundBonusOffers"
                            :descriptions="game.data.roundBonusDescriptions"
                        />
                        <InnovationBoard
                            :player-count="game.data.playersCount"
                            :innovations="game.data.innovations"
                            :competencies="game.data.competencies"
                            :innovation-descriptions="game.data.innovationDescriptions"
                            :competency-descriptions="game.data.competencyDescriptions"
                        />
                        <PalaceBoard :palaces="game.data.availablePalaceIds" />
                        <TownTileBoard :town-tiles="game.data.availableTownTileIds" />
                    </aside>
                </div>
            </section>

            <PowerSacrificeDialog
                v-model:open="isPowerSacrificeDialogOpen"
                :game-id="game.data.id"
                :maximum-amount="maximumPowerSacrifice"
            />

            <PowerActionDialog
                v-model:open="isPowerActionDialogOpen"
                :game-id="game.data.id"
                :action="selectedPowerAction"
                :player-state="currentPlayerState"
            />

            <BookActionDialog
                v-model:open="isBookActionDialogOpen"
                :game-id="game.data.id"
                :action="selectedBookAction"
                :player-state="currentPlayerState"
                :board="game.data.board"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <ScholarActionDialog
                v-model:open="isScholarActionDialogOpen"
                :game-id="game.data.id"
                :discipline="selectedScholarDiscipline"
                :player-state="currentPlayerState"
                :occupied-slots="selectedScholarDisciplineOccupiedSlots"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <RoundBonusActionDialog
                v-model:open="isRoundBonusActionDialogOpen"
                :game-id="game.data.id"
                :round-bonus="currentPlayerState?.roundBonus ?? null"
                :description="currentRoundBonusDescription"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <FactionActionDialog
                v-model:open="isFactionActionDialogOpen"
                :game-id="game.data.id"
                :faction="currentPlayer?.faction ?? null"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <PalaceActionDialog
                v-model:open="isPalaceActionDialogOpen"
                :game-id="game.data.id"
                :palace="currentPlayerState?.palaceId ?? null"
                :board="game.data.board"
                :player-id="currentPlayer?.id ?? null"
                :player-color="currentPlayer?.color ?? null"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <PassDialog
                v-model:open="isPassDialogOpen"
                :game-id="game.data.id"
                :offers="game.data.roundBonusOffers"
                :descriptions="game.data.roundBonusDescriptions"
                :available-actions="availableActionsBeforePass"
            />

            <PaidTerraformingDialog
                v-if="currentPlayerState !== undefined && selectedPaidTerraformHex !== undefined && currentPlayer?.homeland"
                v-model:open="isPaidTerraformingDialogOpen"
                :game-id="game.data.id"
                :player-state="currentPlayerState"
                :target-hex="selectedPaidTerraformHex"
                :homeland="currentPlayer.homeland"
                :has-spade-interaction="canSpendStartingSpade"
            />

            <BuildWorkshopDialog
                v-model:open="isBuildWorkshopDialogOpen"
                :game-id="game.data.id"
                :hex-id="selectedBuildWorkshopHexId"
                :player-color="currentPlayer?.color ?? null"
            />

            <ScienceBonusBooksDialog
                v-if="game.data.pendingInteraction?.type === 'choose_science_bonus_books'
                    && game.data.pendingInteraction.playerId === currentPlayer?.id"
                :game-id="game.data.id"
                :book-count="game.data.pendingInteraction.context.bookCount"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <ResourceExchangeDialog
                v-model:open="isResourceExchangeDialogOpen"
                :game-id="game.data.id"
                :player-state="currentPlayerState"
                :knowledge-discipline-names="game.data.knowledgeDisciplineNames"
            />

            <TerraformWorkshopDialog
                v-if="
                    game.data.pendingInteraction?.type === 'build_workshop_after_terraforming' &&
                    game.data.pendingInteraction.playerId === currentPlayer?.id
                "
                :game-id="game.data.id"
                :hex-ids="game.data.pendingInteraction.optionIds"
                :hexes="game.data.board.hexes"
            />

            <CurrentTurnFinishDialog
                v-model:open="isCurrentTurnFinishDialogOpen"
                :game-id="game.data.id"
            />

            <BuildingUpgradeDialog
                v-model:open="isBuildingUpgradeDialogOpen"
                :game-id="game.data.id"
                :hex-id="selectedBuildingUpgradeHexId"
                :options="selectedBuildingUpgradeOptions"
                :player-color="currentPlayer?.color ?? null"
            />
        </div>

        <PlayerStatsPanel
            v-if="game.data.status === 'active' && planningChoicesCompleted"
            :players="orderedPlayers"
            :player-states="game.data.playerBoardStates"
            :game-id="game.data.id"
            :history="game.data.history"
            :can-undo-last-action="game.data.canUndoLastAction"
        />
    </div>
</template>
