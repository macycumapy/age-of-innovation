<script setup lang="ts">
import { Head, Link, router, useHttp, usePage, usePoll } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { ChevronDown } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import BridgeController from '@/actions/App/Http/Controllers/BridgeController';
import PalaceGuildController from '@/actions/App/Http/Controllers/PalaceGuildController';
import NeutralInnovationBuildingController from '@/actions/App/Http/Controllers/NeutralInnovationBuildingController';
import StartingBuildingController from '@/actions/App/Http/Controllers/StartingBuildingController';
import StartingResourcesController from '@/actions/App/Http/Controllers/StartingResourcesController';
import Form from '@/components/game/GameActionForm.vue';
import BoardMap from '@/components/game/BoardMap.vue';
import BookActionDialog from '@/components/game/BookActionDialog.vue';
import BuildingUpgradeDialog from '@/components/game/BuildingUpgradeDialog.vue';
import BuildWorkshopDialog from '@/components/game/BuildWorkshopDialog.vue';
import CompetencyActionDialog from '@/components/game/CompetencyActionDialog.vue';
import CompetencyChoicePanel from '@/components/game/CompetencyChoicePanel.vue';
import CurrentTurnFinishDialog from '@/components/game/CurrentTurnFinishDialog.vue';
import CurrentTurnPanel from '@/components/game/CurrentTurnPanel.vue';
import GameLobby from '@/components/game/GameLobby.vue';
import IncomeDistributionPanel from '@/components/game/IncomeDistributionPanel.vue';
import TownInteractionPanel from '@/components/game/TownInteractionPanel.vue';
import FactionActionDialog from '@/components/game/FactionActionDialog.vue';
import FinalLeaderboard from '@/components/game/FinalLeaderboard.vue';
import CultBoard from '@/components/game/CultBoard.vue';
import InnovationBoard from '@/components/game/InnovationBoard.vue';
import InnovationPurchaseDialog from '@/components/game/InnovationPurchaseDialog.vue';
import InnovationActionDialog from '@/components/game/InnovationActionDialog.vue';
import PalaceBoard from '@/components/game/PalaceBoard.vue';
import PalaceActionDialog from '@/components/game/PalaceActionDialog.vue';
import PaidTerraformingDialog from '@/components/game/PaidTerraformingDialog.vue';
import PassDialog from '@/components/game/PassDialog.vue';
import BookDistributionPanel from '@/components/game/BookDistributionPanel.vue';
import PalaceChoicePanel from '@/components/game/PalaceChoicePanel.vue';
import PlanningBundleSelector from '@/components/game/PlanningBundleSelector.vue';
import PlayerBoards from '@/components/game/PlayerBoards.vue';
import PlayerStatsPanel from '@/components/game/PlayerStatsPanel.vue';
import PowerActionDialog from '@/components/game/PowerActionDialog.vue';
import PowerSacrificeDialog from '@/components/game/PowerSacrificeDialog.vue';
import ResourceExchangeDialog from '@/components/game/ResourceExchangeDialog.vue';
import RoundBonusActionDialog from '@/components/game/RoundBonusActionDialog.vue';
import RoundBonusChoiceDialog from '@/components/game/RoundBonusChoiceDialog.vue';
import ScholarActionDialog from '@/components/game/ScholarActionDialog.vue';
import ShippingAdvancementDialog from '@/components/game/ShippingAdvancementDialog.vue';
import TerraformingAdvancementDialog from '@/components/game/TerraformingAdvancementDialog.vue';
import RoundBonusBoard from '@/components/game/RoundBonusBoard.vue';
import TownTileBoard from '@/components/game/TownTileBoard.vue';
import PalaceWaterTownDialog from '@/components/game/PalaceWaterTownDialog.vue';
import InputError from '@/components/InputError.vue';
import { NumberStepper } from '@/components/ui/number-stepper';
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
    BookActionState,
    GamePlayerSummary,
    GameResource,
    KnowledgeDiscipline,
    Innovation,
    InnovationPurchaseState,
    MapVariant,
    PowerActionState,
} from '@/types';

const props = defineProps<{
    game: GameResource;
}>();

const page = usePage();

useEcho(`games.${props.game.data.id}`, '.game.changed', () => {
    router.reload({ only: ['game'] });
});

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
    () =>
        props.game.data.phase === 'income' && props.game.data.pendingInteraction?.type === 'choose_starting_resources',
);

const allPlanningBundlesChosen = computed(() => props.game.data.players.every((player) => player.faction !== null));

const planningChoicesCompleted = computed(
    () => allPlanningBundlesChosen.value && props.game.data.pendingInteraction?.type !== 'choose_starting_resources',
);

const shouldShowPlanningBundleGroup = computed(
    () => props.game.data.status === 'active' && !isIncomeResourceDistribution.value && !planningChoicesCompleted.value,
);

const isStartingBuildingStage = computed(() => props.game.data.phase === 'setup' && planningChoicesCompleted.value);

const isOmarStartingTowerTurn = computed(
    () =>
        activePlayer.value?.faction === 'omar' &&
        props.game.data.board.hexes.filter((hex) => hex.building?.ownerPlayerId === activePlayer.value?.id).length >= 2,
);

const isStartingBuildingRequestPending = ref(false);
const startingBuildingRequest = useHttp<{ hex_id: string }>({ hex_id: '' });
const boardActionRequest = useHttp<Record<string, string>>({});

watch(
    () => props.game.data.pendingStartingBuildingHexId,
    () => {
        isStartingBuildingRequestPending.value = false;
    },
);

const canPlaceStartingBuilding = computed(
    () =>
        isStartingBuildingStage.value &&
        props.game.data.activePlayerId === page.props.auth.user.id &&
        props.game.data.pendingInteraction === null &&
        props.game.data.pendingStartingBuildingHexId === null &&
        !isStartingBuildingRequestPending.value,
);

const canChooseStartingCompetency = computed(
    () =>
        props.game.data.pendingInteraction?.type === 'choose_competency' &&
        props.game.data.pendingInteraction.playerId === currentPlayer.value?.id &&
        props.game.data.activePlayerId === page.props.auth.user.id,
);

const canChoosePalace = computed(
    () =>
        props.game.data.pendingInteraction?.type === 'choose_palace' &&
        props.game.data.pendingInteraction.playerId === currentPlayer.value?.id &&
        props.game.data.activePlayerId === page.props.auth.user.id,
);

const canPlacePalaceGuild = computed(
    () =>
        props.game.data.pendingInteraction?.type === 'place_palace_guild' &&
        props.game.data.pendingInteraction.playerId === currentPlayer.value?.id &&
        props.game.data.activePlayerId === page.props.auth.user.id,
);

const pendingPalaceGuildHexId = computed(() =>
    props.game.data.pendingInteraction?.type === 'place_palace_guild'
        ? props.game.data.pendingInteraction.context.selectedHexId
        : null,
);

const canPlaceNeutralBuilding = computed(
    () =>
        props.game.data.pendingInteraction?.type === 'place_neutral_building' &&
        props.game.data.pendingInteraction.playerId === currentPlayer.value?.id &&
        props.game.data.activePlayerId === page.props.auth.user.id,
);

const pendingNeutralBuilding = computed(() =>
    props.game.data.pendingInteraction?.type === 'place_neutral_building' ? props.game.data.pendingInteraction : null,
);

const canSpendStartingSpade = computed(
    () =>
        props.game.data.pendingInteraction?.type === 'spend_spades' &&
        props.game.data.pendingInteraction.playerId === currentPlayer.value?.id &&
        props.game.data.activePlayerId === page.props.auth.user.id,
);

const pendingWorkshopAfterTerraforming = computed(() => {
    const interaction = props.game.data.pendingInteraction;

    return interaction?.type === 'build_workshop_after_terraforming' &&
        interaction.playerId === currentPlayer.value?.id &&
        props.game.data.activePlayerId === page.props.auth.user.id
        ? interaction
        : null;
});

const pendingStartingSpadeHexId = computed(() =>
    props.game.data.pendingInteraction?.type === 'spend_spades'
        ? (props.game.data.pendingInteraction.context.selectedHexId ?? null)
        : null,
);
const selectedBridgeFromHexId = ref<string | null>(null);
const pendingBridgeInteraction = computed(() =>
    props.game.data.pendingInteraction?.type === 'place_bridge' ? props.game.data.pendingInteraction : null,
);
const eligibleBridgePairs = computed(() => {
    if (pendingBridgeInteraction.value === null) {
        return [];
    }

    return pendingBridgeInteraction.value.context.pairs ?? [];
});
const pendingBridge = computed(() => {
    const interaction = pendingBridgeInteraction.value;

    if (
        interaction?.context.selectedFromHexId === undefined ||
        interaction.context.selectedToHexId === undefined ||
        currentPlayer.value === undefined
    ) {
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
        if (
            pendingBridgeInteraction.value?.context.selectedFromHexId !== undefined ||
            pendingBridgeInteraction.value === null
        ) {
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
        return pendingPalaceGuildHexId.value === null ? props.game.data.pendingInteraction.optionIds : [];
    }

    if (canPlaceNeutralBuilding.value && pendingNeutralBuilding.value !== null) {
        return pendingNeutralBuilding.value.optionIds;
    }

    if (canSpendStartingSpade.value && props.game.data.pendingInteraction?.type === 'spend_spades') {
        return pendingStartingSpadeHexId.value === null
            ? [...new Set([...props.game.data.pendingInteraction.optionIds, ...moleTunnelTerraformHexIds.value])]
            : [];
    }

    if (pendingWorkshopAfterTerraforming.value !== null) {
        const state = currentPlayerState.value;

        return state !== undefined && state.tools >= 1 && state.coins >= 2 && state.buildingsOnMap.workshop < 9
            ? pendingWorkshopAfterTerraforming.value.optionIds
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
    if (boardActionRequest.processing) {
        return;
    }

    if (pendingBridgeInteraction.value !== null) {
        if (selectedBridgeFromHexId.value === null) {
            selectedBridgeFromHexId.value = hexId;

            return;
        }

        const fromHexId = selectedBridgeFromHexId.value;

        boardActionRequest.transform(() => ({
            from_hex_id: fromHexId,
            to_hex_id: hexId,
        }));
        void boardActionRequest.post(BridgeController.store.url(props.game.data.id), {
            onSuccess: () => {
                selectedBridgeFromHexId.value = null;
            },
        });

        return;
    }

    if (canPlacePalaceGuild.value) {
        boardActionRequest.transform(() => ({ hex_id: hexId }));
        void boardActionRequest.post(PalaceGuildController.store.url(props.game.data.id));

        return;
    }

    if (canPlaceNeutralBuilding.value) {
        const targetHex = props.game.data.board.hexes.find((hex) => hex.id === hexId);

        if (targetHex?.terrain !== currentPlayer.value?.homeland) {
            selectedPaidTerraformHexId.value = hexId;
            isPaidTerraformingDialogOpen.value = true;

            return;
        }

        boardActionRequest.transform(() => ({ hex_id: hexId }));
        void boardActionRequest.post(NeutralInnovationBuildingController.url(props.game.data.id));

        return;
    }
    if (canSpendStartingSpade.value) {
        selectedPaidTerraformHexId.value = hexId;
        isPaidTerraformingDialogOpen.value = true;

        return;
    }

    if (pendingWorkshopAfterTerraforming.value?.optionIds.includes(hexId)) {
        selectedBuildWorkshopHexId.value = hexId;
        isBuildWorkshopDialogOpen.value = true;

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

    isStartingBuildingRequestPending.value = true;
    startingBuildingRequest.hex_id = hexId;

    void startingBuildingRequest.post(StartingBuildingController.store.url(props.game.data.id), {
        onError: () => {
            isStartingBuildingRequestPending.value = false;
        },
        onCancel: () => {
            isStartingBuildingRequestPending.value = false;
        },
        onNetworkError: () => {
            isStartingBuildingRequestPending.value = false;
        },
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
const isPlanningBundleGroupOpen = ref(true);
const isPowerSacrificeDialogOpen = ref(false);
const isPowerActionDialogOpen = ref(false);
const isBookActionDialogOpen = ref(false);
const isResourceExchangeDialogOpen = ref(false);
const isShippingAdvancementDialogOpen = ref(false);
const isTerraformingAdvancementDialogOpen = ref(false);
const isCurrentTurnFinishDialogOpen = ref(false);
const isBuildingUpgradeDialogOpen = ref(false);
const isScholarActionDialogOpen = ref(false);
const isRoundBonusActionDialogOpen = ref(false);
const isFactionActionDialogOpen = ref(false);
const isCompetencyActionDialogOpen = ref(false);
const isPalaceActionDialogOpen = ref(false);
const isPassDialogOpen = ref(false);
const isPaidTerraformingDialogOpen = ref(false);

const hasImplementedFactionAction = computed(
    () =>
        currentPlayer.value?.faction !== null &&
        currentPlayer.value?.faction !== undefined &&
        ['moles', 'philosophers', 'psychics'].includes(currentPlayer.value.faction),
);

function openFactionActionDialog(): void {
    if (hasImplementedFactionAction.value) {
        isFactionActionDialogOpen.value = true;
    }
}
const isBuildWorkshopDialogOpen = ref(false);
const isInnovationPurchaseDialogOpen = ref(false);
const isInnovationActionDialogOpen = ref(false);
const selectedPaidTerraformHexId = ref<string | null>(null);
const selectedBuildWorkshopHexId = ref<string | null>(null);
const selectedBuildingUpgradeHexId = ref<string | null>(null);
const selectedPowerAction = ref<PowerActionState | null>(null);
const selectedBookAction = ref<BookActionState | null>(null);
const selectedInnovation = ref<Innovation | null>(null);
const selectedInnovationAction = ref<Innovation | null>(null);

function openInnovationActionDialog(innovation: Innovation): void {
    selectedInnovationAction.value = innovation;
    isInnovationActionDialogOpen.value = true;
}
const selectedScholarDiscipline = ref<KnowledgeDiscipline | null>(null);

const currentPlayerState = computed(() =>
    props.game.data.playerBoardStates.find((state) => state.playerId === currentPlayer.value?.id),
);
const pendingBookDistribution = computed(() => {
    const interaction = props.game.data.pendingInteraction;

    if (
        interaction?.type !== 'choose_science_bonus_books' &&
        interaction?.type !== 'choose_innovation_books' &&
        interaction?.type !== 'choose_shipping_books' &&
        interaction?.type !== 'choose_terraforming_books' &&
        interaction?.type !== 'choose_palace_books' &&
        interaction?.type !== 'choose_town_books'
    ) {
        return null;
    }

    return {
        type: interaction.type,
        bookCount: interaction.context.bookCount,
    };
});
const currentRoundBonusDescription = computed(() =>
    currentPlayerState.value ? props.game.data.roundBonusDescriptions[currentPlayerState.value.roundBonus] : '',
);

const maximumPowerSacrifice = computed(() => Math.floor((currentPlayerState.value?.power.bowlTwo ?? 0) / 2));
const selectedScholarDisciplineOccupiedSlots = computed(() =>
    selectedScholarDiscipline.value === null
        ? 0
        : props.game.data.playerBoardStates.reduce(
              (total, state) =>
                  total +
                  state.scholarDisciplineIds.filter((discipline) => discipline === selectedScholarDiscipline.value)
                      .length,
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
    () =>
        props.game.data.phase === 'actions' &&
        props.game.data.activePlayerId === page.props.auth.user.id &&
        maximumPowerSacrifice.value > 0,
);

const canExchangeResources = computed(
    () => props.game.data.phase === 'actions' && props.game.data.activePlayerId === page.props.auth.user.id,
);

const canStartPaidTerraforming = computed(() => {
    const state = currentPlayerState.value;

    return props.game.data.canPass && state !== undefined;
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

    props.game.data.board.bridges.forEach((bridge) => {
        if (bridge.ownerPlayerId !== player.id) {
            return;
        }

        const fromHex = hexesById.get(bridge.fromHexId);
        const toHex = hexesById.get(bridge.toHexId);

        if (fromHex?.building?.ownerPlayerId === player.id) {
            reachableHexIds.add(bridge.toHexId);
        }

        if (toHex?.building?.ownerPlayerId === player.id) {
            reachableHexIds.add(bridge.fromHexId);
        }
    });

    const visitedWaterHexIds = new Set<string>();

    const navigationRange = playerState.shippingLevel + (playerState.roundBonus === 'river_workshop' ? 1 : 0);

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

        return hex !== undefined && hex.building === null && hex.terrain !== 'water';
    });
});
const moleTunnelTerraformHexIds = computed(() => {
    const player = currentPlayer.value;

    if ((!canStartPaidTerraforming.value && !canSpendStartingSpade.value) || player?.faction !== 'moles') {
        return [];
    }

    const hexesById = new Map(props.game.data.board.hexes.map((hex) => [hex.id, hex]));
    const ownedBuildingHexIds = new Set(
        props.game.data.board.hexes.filter((hex) => hex.building?.ownerPlayerId === player.id).map((hex) => hex.id),
    );
    const eligibleHexIds = new Set<string>();

    props.game.data.board.hexes.forEach((originHex) => {
        if (!ownedBuildingHexIds.has(originHex.id)) {
            return;
        }

        props.game.data.board.hexes.forEach((targetHex) => {
            const qDistance = targetHex.q - originHex.q;
            const rDistance = targetHex.r - originHex.r;
            const hexDistance = Math.max(Math.abs(qDistance), Math.abs(rDistance), Math.abs(qDistance + rDistance));
            const hasIntermediateHex = originHex.adjacentHexIds.some(
                (hexId) => targetHex.adjacentHexIds.includes(hexId) && hexesById.has(hexId),
            );

            if (
                hexDistance === 2 &&
                hasIntermediateHex &&
                targetHex.building === null &&
                targetHex.terrain !== 'water' &&
                targetHex.terrain !== player.homeland &&
                !targetHex.adjacentHexIds.some((hexId) => ownedBuildingHexIds.has(hexId))
            ) {
                eligibleHexIds.add(targetHex.id);
            }
        });
    });

    return [...eligibleHexIds];
});
const paidTerraformHexIds = computed(() => [
    ...new Set([
        ...reachableEmptyLandHexIds.value.filter(
            (hexId) =>
                props.game.data.board.hexes.find((hex) => hex.id === hexId)?.terrain !== currentPlayer.value?.homeland,
        ),
        ...moleTunnelTerraformHexIds.value,
    ]),
]);
const buildableWorkshopHexIds = computed(() => {
    const state = currentPlayerState.value;

    if (state === undefined || state.tools < 1 || state.coins < 2 || state.buildingsOnMap.workshop >= 9) {
        return [];
    }

    return reachableEmptyLandHexIds.value.filter(
        (hexId) =>
            props.game.data.board.hexes.find((hex) => hex.id === hexId)?.terrain === currentPlayer.value?.homeland,
    );
});
const selectedPaidTerraformHex = computed(() =>
    props.game.data.board.hexes.find((hex) => hex.id === selectedPaidTerraformHexId.value),
);

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

    if (props.game.data.canMakeInnovation) {
        actions.push('покупка инновации');
    }

    if (state.canUseFactionAction) {
        actions.push('действие расы');
    }

    if (state.canUseCompetencyAction) {
        actions.push('действие Компетенции Силы');
    }

    if (state.canUseRoundBonusAction) {
        actions.push('действие бонуса раунда');
    }

    if (state.canUsePalaceAction) {
        actions.push('действие жетона Дворца');
    }

    if (state.availableInnovationActionIds.length > 0) {
        actions.push('действие инновации');
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

function selectInnovation(innovation: Innovation): void {
    if (!props.game.data.canMakeInnovation) {
        return;
    }

    selectedInnovation.value = innovation;
    isInnovationPurchaseDialogOpen.value = true;
}

const selectedInnovationPurchaseState = computed<InnovationPurchaseState | null>(
    () => props.game.data.innovationStates.find((state) => state.id === selectedInnovation.value) ?? null,
);

const selectedBuildingUpgradeOptions = computed(() =>
    props.game.data.buildingUpgrades.filter((option) => option.hexId === selectedBuildingUpgradeHexId.value),
);

const annexableBuildingHexIds = computed(() => {
    if (!props.game.data.canPlaceAnnex || currentPlayer.value === undefined) {
        return [];
    }

    return props.game.data.board.hexes
        .filter(
            (hex) =>
                hex.building !== null &&
                hex.building.ownerPlayerId === currentPlayer.value?.id &&
                !hex.building.hasAnnex,
        )
        .map((hex) => hex.id);
});

const interactiveBuildingHexIds = computed(() => [
    ...new Set([...props.game.data.buildingUpgrades.map((option) => option.hexId), ...annexableBuildingHexIds.value]),
]);

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

watch(
    () => props.game.data.pendingInteraction?.playerId,
    () => {
        for (const discipline of Object.keys(startingBookCounts) as KnowledgeDiscipline[]) {
            startingBookCounts[discipline] = 0;
            startingKnowledgeCounts[discipline] = 0;
        }
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
</script>

<template>
    <Head :title="pageTitle" />

    <div class="flex h-full min-w-0 flex-1">
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-4">
            <GameLobby v-if="game.data.status === 'lobby'" :game="game" :current-player="currentPlayer" />

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

            <TownInteractionPanel
                v-if="
                    game.data.pendingInteraction?.type === 'choose_town' &&
                    game.data.pendingInteraction.playerId === currentPlayer?.id &&
                    activePlayer?.user.id === page.props.auth.user.id
                "
                :game="game"
            />

            <BookDistributionPanel
                v-if="pendingBookDistribution !== null && game.data.pendingInteraction?.playerId === currentPlayer?.id"
                :game-id="game.data.id"
                :book-count="pendingBookDistribution.bookCount"
                :type="pendingBookDistribution.type"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <IncomeDistributionPanel
                v-if="
                    isIncomeResourceDistribution &&
                    canChooseStartingResources &&
                    game.data.pendingInteraction?.type === 'choose_starting_resources'
                "
                :game-id="game.data.id"
                :interaction="game.data.pendingInteraction"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <Collapsible v-if="shouldShowPlanningBundleGroup" v-model:open="isPlanningBundleGroupOpen">
                <Card>
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
                                    <h3 class="font-semibold">Распределите стартовые ресурсы</h3>
                                    <p class="text-sm text-muted-foreground">
                                        Этот выбор завершает получение вашего стартового комплекта.
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
                                        <div
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
                                        </div>
                                    </div>
                                    <InputError :message="errors.book_counts" />
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
                                        <div
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
                                        </div>
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
                                                remainingStartingKnowledgeStepCount !== 0
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

                            <PlanningBundleSelector :game="game" :can-choose="canChoosePlanningBundle" />
                        </CardContent>
                    </CollapsibleContent>
                </Card>
            </Collapsible>

            <CompetencyChoicePanel
                v-if="canChooseStartingCompetency && game.data.pendingInteraction?.type === 'choose_competency'"
                :game-id="game.data.id"
                :competencies="game.data.pendingInteraction.optionIds"
                :descriptions="game.data.competencyDescriptions"
                :is-building-choice="game.data.pendingInteraction.context.reason === 'building'"
            />

            <PalaceChoicePanel
                v-if="canChoosePalace && game.data.pendingInteraction?.type === 'choose_palace'"
                :game-id="game.data.id"
                :palaces="game.data.pendingInteraction.optionIds"
                :descriptions="game.data.palaceDescriptions"
            />

            <section v-if="['active', 'finished'].includes(game.data.status)" class="grid gap-4">
                <FinalLeaderboard
                    v-if="game.data.phase === 'finished'"
                    :players="game.data.players"
                    :player-states="game.data.playerBoardStates"
                />

                <RoundBonusChoiceDialog
                    v-if="
                        game.data.pendingInteraction?.type === 'choose_round_bonus' &&
                        game.data.pendingInteraction.playerId === currentPlayer?.id
                    "
                    :game-id="game.data.id"
                    :offers="game.data.roundBonusOffers"
                    :option-ids="game.data.pendingInteraction.optionIds"
                    :descriptions="game.data.roundBonusDescriptions"
                />

                <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,7fr)_minmax(16rem,3fr)]">
                    <div class="grid gap-4">
                        <BoardMap
                            :board="game.data.board"
                            :players="game.data.players"
                            :selectable-hex-ids="selectableStartingHexIds"
                            :pending-hex-id="
                                game.data.pendingStartingBuildingHexId ??
                                pendingStartingSpadeHexId ??
                                pendingPalaceGuildHexId ??
                                selectedBridgeFromHexId
                            "
                            :pending-bridge="pendingBridge"
                            :current-round="game.data.currentRound"
                            :round-scoring-tiles="game.data.roundScoringTiles"
                            :final-round-scoring-tile="game.data.finalRoundScoringTile"
                            :book-actions="game.data.bookActions"
                            :used-book-action-ids="game.data.usedBookActionIds"
                            :book-action-states="game.data.bookActionStates"
                            :power-actions="game.data.powerActions"
                            :can-use-power-actions="game.data.canPass"
                            :can-use-book-actions="game.data.canPass"
                            :upgradeable-building-hex-ids="interactiveBuildingHexIds"
                            @hex-click="placeStartingBuilding"
                            @power-action-click="selectPowerAction"
                            @book-action-click="selectBookAction"
                            @building-click="selectBuildingUpgrade"
                        />

                        <PlayerBoards
                            v-if="playersWithSelectedFactions.length > 0"
                            :players="playersWithSelectedFactions"
                            :player-states="game.data.playerBoardStates"
                            :current-round="game.data.currentRound"
                            :current-user-id="page.props.auth.user.id"
                            :round-bonus-descriptions="game.data.roundBonusDescriptions"
                            :competency-descriptions="game.data.competencyDescriptions"
                            :innovation-descriptions="game.data.innovationDescriptions"
                            :palace-descriptions="game.data.palaceDescriptions"
                            :can-sacrifice-power="canSacrificePower"
                            :can-exchange-resources="canExchangeResources"
                            :can-advance-shipping="
                                game.data.canPass && (currentPlayerState?.canAdvanceShipping ?? false)
                            "
                            :can-advance-terraforming="
                                game.data.canPass && (currentPlayerState?.canAdvanceTerraforming ?? false)
                            "
                            :can-use-round-bonus-action="canExchangeResources"
                            :can-use-faction-action="canExchangeResources"
                            :can-use-competency-action="canExchangeResources"
                            :can-use-palace-action="canExchangeResources"
                            :can-use-innovation-action="canExchangeResources"
                            @sacrifice-power="isPowerSacrificeDialogOpen = true"
                            @exchange-resources="isResourceExchangeDialogOpen = true"
                            @advance-shipping="isShippingAdvancementDialogOpen = true"
                            @advance-terraforming="isTerraformingAdvancementDialogOpen = true"
                            @use-round-bonus-action="isRoundBonusActionDialogOpen = true"
                            @use-faction-action="openFactionActionDialog"
                            @use-competency-action="isCompetencyActionDialogOpen = true"
                            @use-palace-action="isPalaceActionDialogOpen = true"
                            @use-innovation-action="openInnovationActionDialog"
                        />
                    </div>

                    <aside class="grid gap-4">
                        <CultBoard
                            :players="orderedPlayers"
                            :player-states="game.data.playerBoardStates"
                            :can-send-scholar="game.data.canSendScholar"
                            @send-scholar="selectScholarDiscipline"
                        />
                        <InnovationBoard
                            v-if="game.data.status === 'active'"
                            :player-count="game.data.playersCount"
                            :innovations="game.data.innovations"
                            :competencies="game.data.competencies"
                            :competency-counts="game.data.competencyCounts"
                            :innovation-descriptions="game.data.innovationDescriptions"
                            :competency-descriptions="game.data.competencyDescriptions"
                            :innovation-states="game.data.innovationStates"
                            :can-make-innovation="game.data.canMakeInnovation"
                            @innovation-click="selectInnovation"
                        />
                        <TownTileBoard
                            v-if="game.data.status === 'active'"
                            :town-tiles="game.data.availableTownTileIds"
                        />
                        <PalaceBoard v-if="game.data.status === 'active'" :palaces="game.data.availablePalaceIds" />
                        <RoundBonusBoard
                            v-if="game.data.status === 'active' && game.data.currentRound !== 6"
                            :offers="game.data.roundBonusOffers"
                            :descriptions="game.data.roundBonusDescriptions"
                        />
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

            <InnovationPurchaseDialog
                v-model:open="isInnovationPurchaseDialogOpen"
                :game-id="game.data.id"
                :innovation="selectedInnovation"
                :purchase-state="selectedInnovationPurchaseState"
                :player-state="currentPlayerState"
                :description="selectedInnovation ? game.data.innovationDescriptions[selectedInnovation] : ''"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <InnovationActionDialog
                v-model:open="isInnovationActionDialogOpen"
                :game-id="game.data.id"
                :innovation="selectedInnovationAction"
                :description="
                    selectedInnovationAction ? game.data.innovationDescriptions[selectedInnovationAction] : ''
                "
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
                v-if="hasImplementedFactionAction"
                v-model:open="isFactionActionDialogOpen"
                :game-id="game.data.id"
                :faction="currentPlayer?.faction ?? null"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <CompetencyActionDialog v-model:open="isCompetencyActionDialogOpen" :game-id="game.data.id" />

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
                :available-actions="availableActionsBeforePass"
                :is-final-round="game.data.currentRound === 6"
                :current-round-bonus="currentPlayerState?.roundBonus ?? null"
                :school-count="currentPlayerState?.buildingsOnMap.school ?? 0"
                :discipline-names="game.data.knowledgeDisciplineNames"
            />

            <PaidTerraformingDialog
                v-if="
                    currentPlayerState !== undefined &&
                    selectedPaidTerraformHex !== undefined &&
                    currentPlayer?.homeland
                "
                v-model:open="isPaidTerraformingDialogOpen"
                :game-id="game.data.id"
                :player-state="currentPlayerState"
                :target-hex="selectedPaidTerraformHex"
                :homeland="currentPlayer.homeland"
                :has-spade-interaction="canSpendStartingSpade"
                :builds-neutral-building="canPlaceNeutralBuilding"
                :tunnel-available="moleTunnelTerraformHexIds.includes(selectedPaidTerraformHex.id)"
                :tunnel-required="
                    moleTunnelTerraformHexIds.includes(selectedPaidTerraformHex.id) &&
                    !reachableEmptyLandHexIds.includes(selectedPaidTerraformHex.id)
                "
                :player-count="game.data.players.length"
            />

            <BuildWorkshopDialog
                v-model:open="isBuildWorkshopDialogOpen"
                :game-id="game.data.id"
                :hex-id="selectedBuildWorkshopHexId"
                :player-color="currentPlayer?.color ?? null"
                :after-terraforming="pendingWorkshopAfterTerraforming !== null"
            />

            <ResourceExchangeDialog
                v-model:open="isResourceExchangeDialogOpen"
                :game-id="game.data.id"
                :player-state="currentPlayerState"
                :knowledge-discipline-names="game.data.knowledgeDisciplineNames"
            />

            <ShippingAdvancementDialog v-model:open="isShippingAdvancementDialogOpen" :game-id="game.data.id" />

            <TerraformingAdvancementDialog
                v-model:open="isTerraformingAdvancementDialogOpen"
                :game-id="game.data.id"
                :player-color="currentPlayer?.color ?? null"
            />

            <PalaceWaterTownDialog
                v-if="
                    game.data.pendingInteraction?.type === 'offer_palace_water_town' &&
                    game.data.pendingInteraction.playerId === currentPlayer?.id
                "
                :game-id="game.data.id"
                :water-hex-ids="game.data.pendingInteraction.optionIds"
            />

            <CurrentTurnFinishDialog v-model:open="isCurrentTurnFinishDialogOpen" :game-id="game.data.id" />

            <BuildingUpgradeDialog
                v-model:open="isBuildingUpgradeDialogOpen"
                :game-id="game.data.id"
                :hex-id="selectedBuildingUpgradeHexId"
                :options="selectedBuildingUpgradeOptions"
                :player-color="currentPlayer?.color ?? null"
                :can-place-annex="
                    selectedBuildingUpgradeHexId !== null &&
                    annexableBuildingHexIds.includes(selectedBuildingUpgradeHexId)
                "
            />
        </div>

        <PlayerStatsPanel
            v-if="['active', 'finished'].includes(game.data.status)"
            :players="orderedPlayers"
            :player-states="game.data.playerBoardStates"
            :current-player-id="currentPlayer?.id ?? null"
            :active-player-id="activePlayer?.id ?? null"
            :game-id="game.data.id"
            :history="game.data.history"
            :can-undo-last-action="game.data.canUndoLastAction"
        />
    </div>
</template>
