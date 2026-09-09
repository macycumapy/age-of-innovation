<script setup lang="ts">
import { computed } from 'vue';
import TerraformedTerrainTokens from '@/components/game/TerraformedTerrainTokens.vue';
import BridgeTokens from '@/components/game/BridgeTokens.vue';
import { terrainColors, terrainNames } from '@/lib/gameDisplay';
import type {
    BoardState,
    BookAction,
    BookActionState,
    FinalRoundScoringTile,
    GamePlayerSummary,
    MapVariant,
    PowerActionState,
    RoundScoringTile,
} from '@/types';
import gameBoardUrl from '../../../images/game_board.webp';
import gameBoardTwoPlayerUrl from '../../../images/game_board_2p.webp';
import finishedRoundScoringTileUrl from '../../../images/round_scoring_tiles/finished.png';
import goldCrossUrl from '../../../images/token_parts/gold_cross.png';
import annexUrl from '../../../images/buildings/white/annex.png';

type Props = {
    board: BoardState;
    currentRound?: number | null;
    roundScoringTiles?: RoundScoringTile[];
    finalRoundScoringTile?: FinalRoundScoringTile | null;
    bookActions?: BookAction[];
    usedBookActionIds?: BookAction[];
    bookActionStates?: BookActionState[];
    powerActions?: PowerActionState[];
    players?: GamePlayerSummary[];
    selectableHexIds?: string[];
    pendingHexId?: string | null;
    canUsePowerActions?: boolean;
    canUseBookActions?: boolean;
    upgradeableBuildingHexIds?: string[];
    pendingBridge?: { fromHexId: string; toHexId: string; ownerPlayerId: number } | null;
};

type BoardLayout = {
    hexOriginX: number;
    columnSpacing: number;
    boardOriginY: number;
    rowSpacing: number;
    roundScoringTileX: number;
    firstRoundScoringTileY: number;
    roundScoringTileSpacing: number;
    roundScoringTileWidth: number;
    roundScoringTileHeight: number;
    bookActionY: number;
    bookActionStartX: number;
    bookActionSpacing: number;
    bookActionWidth: number;
    bookActionHeight: number;
    powerActionY: number;
    powerActionStartX: number;
    powerActionSpacing: number;
    powerActionWidth: number;
    powerActionHeight: number;
};

const props = withDefaults(defineProps<Props>(), {
    currentRound: null,
    roundScoringTiles: () => [],
    finalRoundScoringTile: null,
    bookActions: () => [],
    usedBookActionIds: () => [],
    bookActionStates: () => [],
    powerActions: () => [],
    players: () => [],
    selectableHexIds: () => [],
    pendingHexId: null,
    canUsePowerActions: false,
    canUseBookActions: false,
    upgradeableBuildingHexIds: () => [],
    pendingBridge: null,
});

const emit = defineEmits<{
    hexClick: [hexId: string];
    powerActionClick: [action: PowerActionState];
    bookActionClick: [action: BookActionState];
    buildingClick: [hexId: string];
}>();

const boardWidth = 2004;
const boardHeight = 1285;
const rowOffset = 64;
const hexRadiusX = 62;
const hexRadiusY = 72;
const cornerRatio = 0.1;
const boardLayouts: Record<MapVariant, BoardLayout> = {
    one_to_three_players: {
        hexOriginX: 361,
        columnSpacing: 129.5,
        boardOriginY: 142,
        rowSpacing: 112.3,
        roundScoringTileX: 38,
        firstRoundScoringTileY: 1018,
        roundScoringTileSpacing: 127,
        roundScoringTileWidth: 200,
        roundScoringTileHeight: 126,
        bookActionY: 1175,
        bookActionStartX: 20,
        bookActionSpacing: 213,
        bookActionWidth: 185,
        bookActionHeight: 90,
        powerActionY: 1175,
        powerActionStartX: 705,
        powerActionSpacing: 217,
        powerActionWidth: 185,
        powerActionHeight: 90,
    },
    three_to_five_players: {
        hexOriginX: 327,
        rowSpacing: 111,
        boardOriginY: 135,
        columnSpacing: 128,
        roundScoringTileX: 31,
        firstRoundScoringTileY: 1006,
        roundScoringTileSpacing: 125,
        roundScoringTileWidth: 203,
        roundScoringTileHeight: 126,
        bookActionY: 1178,
        bookActionStartX: 20,
        bookActionSpacing: 213,
        bookActionWidth: 185,
        bookActionHeight: 90,
        powerActionY: 1176,
        powerActionStartX: 717,
        powerActionSpacing: 215,
        powerActionWidth: 185,
        powerActionHeight: 90,
    },
};

const roundScoringTileImages = import.meta.glob<string>('../../../images/round_scoring_tiles/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
});
const finalRoundScoringTileImages = import.meta.glob<string>('../../../images/final_round_scoring_tiles/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
});
const bookActionImages = import.meta.glob<string>('../../../images/book_actions/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
});
const buildingImages = import.meta.glob<string>(
    '../../../images/buildings/*/{workshop,guild,school,university,palace,tower,monument}.png',
    {
        eager: true,
        import: 'default',
        query: '?url',
    },
);
const townTileImages = import.meta.glob<string>('../../../images/cities/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
});

const boardImageUrl = computed(() =>
    props.board.variant === 'one_to_three_players' ? gameBoardTwoPlayerUrl : gameBoardUrl,
);
const boardLayout = computed(() => boardLayouts[props.board.variant]);

const playerColors = computed(() => new Map(props.players.map((player) => [player.id, player.color])));

function buildingImage(ownerPlayerId: number, type: string, isNeutral: boolean): string {
    const color = isNeutral ? 'white' : (playerColors.value.get(ownerPlayerId) ?? 'white');

    return buildingImages[`../../../images/buildings/${color}/${type}.png`] ?? '';
}

function townTileImage(townTileId: string): string {
    return townTileImages[`../../../images/cities/${townTileId}.png`] ?? '';
}

const roundScoringTileNames: Record<RoundScoringTile, string> = {
    workshop_law: 'Мастерская и право',
    workshop_banking: 'Мастерская и банковское дело',
    guild_law: 'Гильдия и право',
    guild_medicine: 'Гильдия и медицина',
    school_banking: 'Школа и банковское дело',
    palace_university_medicine: 'Дворец, университет и медицина',
    palace_university_banking: 'Дворец, университет и банковское дело',
    spade_engineering: 'Лопаты и инженерное дело',
    knowledge_medicine: 'Знания и медицина',
    town_engineering: 'Города и инженерное дело',
    track_engineering: 'Шкалы и инженерное дело',
    innovation_law: 'Изобретения и право',
};

const finalRoundScoringTileNames: Record<FinalRoundScoringTile, string> = {
    workshop: 'Мастерские',
    guild: 'Гильдии',
    school: 'Школы',
    edge_workshop: 'Мастерские на краю карты',
};

const bookActionNames: Record<BookAction, string> = {
    gain_power: 'Получить силу',
    advance_knowledge: 'Продвинуть знания',
    gain_coins: 'Получить монеты',
    upgrade_to_guild: 'Улучшить до гильдии',
    score_guilds: 'Получить очки за гильдии',
    terraform_three_spades: 'Преобразовать тремя лопатами',
};

const hexVertices = [
    { x: 0, y: -hexRadiusY },
    { x: hexRadiusX, y: -hexRadiusY / 2 },
    { x: hexRadiusX, y: hexRadiusY / 2 },
    { x: 0, y: hexRadiusY },
    { x: -hexRadiusX, y: hexRadiusY / 2 },
    { x: -hexRadiusX, y: -hexRadiusY / 2 },
];

const pointTowards = (from: { x: number; y: number }, to: { x: number; y: number }) => ({
    x: from.x + (to.x - from.x) * cornerRatio,
    y: from.y + (to.y - from.y) * cornerRatio,
});

const roundedHexPath = hexVertices
    .map((vertex, index) => {
        const previous = hexVertices.at(index - 1) ?? hexVertices.at(-1)!;
        const next = hexVertices[(index + 1) % hexVertices.length];
        const start = pointTowards(vertex, previous);
        const end = pointTowards(vertex, next);

        return `${index === 0 ? `M ${start.x},${start.y}` : `L ${start.x},${start.y}`} Q ${vertex.x},${vertex.y} ${end.x},${end.y}`;
    })
    .join(' ')
    .concat(' Z');

const visibleHexes = computed(() =>
    props.board.hexes
        .filter((hex) => hex.terrain !== 'water')
        .map((hex) => ({
            ...hex,
            x: boardLayout.value.hexOriginX + boardLayout.value.columnSpacing * hex.q + rowOffset * hex.r,
            y: boardLayout.value.boardOriginY + boardLayout.value.rowSpacing * hex.r,
        })),
);

const waterTownTokens = computed(() =>
    props.board.hexes
        .filter((hex) => hex.terrain === 'water' && hex.townTileId !== null)
        .map((hex) => ({
            ...hex,
            x: boardLayout.value.hexOriginX + boardLayout.value.columnSpacing * hex.q + rowOffset * hex.r,
            y: boardLayout.value.boardOriginY + boardLayout.value.rowSpacing * hex.r,
        })),
);

function roundScoringTileImage(tile: RoundScoringTile, index: number): string {
    if (props.currentRound !== null && index + 1 < props.currentRound) {
        return finishedRoundScoringTileUrl;
    }

    return roundScoringTileImages[`../../../images/round_scoring_tiles/${tile}.png`] ?? '';
}

function finalRoundScoringTileImage(tile: FinalRoundScoringTile): string {
    return finalRoundScoringTileImages[`../../../images/final_round_scoring_tiles/${tile}.png`] ?? '';
}

function bookActionImage(action: BookAction): string {
    return bookActionImages[`../../../images/book_actions/${action}.png`] ?? '';
}

function roundScoringTileY(index: number): number {
    return boardLayout.value.firstRoundScoringTileY - index * boardLayout.value.roundScoringTileSpacing;
}

function powerActionX(index: number): number {
    return boardLayout.value.powerActionStartX + index * boardLayout.value.powerActionSpacing;
}

function canSelectPowerAction(action: PowerActionState): boolean {
    return props.canUsePowerActions && !action.isUsed;
}

function bookActionState(action: BookAction): BookActionState | undefined {
    return props.bookActionStates.find((state) => state.id === action);
}

function canSelectBookAction(action: BookAction): boolean {
    const state = bookActionState(action);

    return props.canUseBookActions && state !== undefined && !state.isUsed;
}

function selectBookAction(action: BookAction): void {
    const state = bookActionState(action);

    if (state !== undefined && canSelectBookAction(action)) {
        emit('bookActionClick', state);
    }
}

const closedActionTokenSize = 82;

function closedActionTokenX(actionX: number, actionWidth: number): number {
    return actionX + (actionWidth - closedActionTokenSize) - 10;
}
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-border bg-black shadow-inner">
        <svg
            :viewBox="`0 0 ${boardWidth} ${boardHeight}`"
            class="block h-auto w-full min-w-[48rem]"
            role="img"
            aria-label="Игровая карта"
        >
            <image
                :href="boardImageUrl"
                x="0"
                y="0"
                :width="boardWidth"
                :height="boardHeight"
                preserveAspectRatio="xMidYMid meet"
            />

            <g v-for="(tile, index) in roundScoringTiles" :key="`round-${index}-${tile}`">
                <title>Раунд {{ index + 1 }}: {{ roundScoringTileNames[tile] }}</title>
                <image
                    :href="roundScoringTileImage(tile, index)"
                    :x="boardLayout.roundScoringTileX"
                    :y="roundScoringTileY(index)"
                    :width="boardLayout.roundScoringTileWidth"
                    :height="boardLayout.roundScoringTileHeight"
                    preserveAspectRatio="xMidYMid meet"
                />
                <image
                    v-if="index === 5 && finalRoundScoringTile"
                    :href="finalRoundScoringTileImage(finalRoundScoringTile)"
                    :x="boardLayout.roundScoringTileX"
                    :y="roundScoringTileY(index)"
                    :width="boardLayout.roundScoringTileWidth"
                    :height="boardLayout.roundScoringTileHeight"
                    preserveAspectRatio="xMidYMid meet"
                >
                    <title>
                        Дополнительная цель:
                        {{ finalRoundScoringTileNames[finalRoundScoringTile] }}
                    </title>
                </image>
            </g>

            <g
                v-for="(action, index) in bookActions"
                :key="`book-${action}`"
                class="book-action-group"
                :class="canSelectBookAction(action) ? 'cursor-pointer' : ''"
                @click="selectBookAction(action)"
            >
                <title>Действие за книги: {{ bookActionNames[action] }}</title>
                <rect
                    :x="boardLayout.bookActionStartX + index * boardLayout.bookActionSpacing"
                    :y="boardLayout.bookActionY"
                    :width="boardLayout.bookActionWidth"
                    :height="boardLayout.bookActionHeight"
                    class="book-action-hitbox"
                    rx="12"
                />
                <image
                    :href="bookActionImage(action)"
                    :x="boardLayout.bookActionStartX + index * boardLayout.bookActionSpacing"
                    :y="boardLayout.bookActionY"
                    :width="boardLayout.bookActionWidth"
                    :height="boardLayout.bookActionHeight"
                    class="book-action-image"
                    preserveAspectRatio="xMidYMid meet"
                />
                <image
                    v-if="usedBookActionIds.includes(action)"
                    :href="goldCrossUrl"
                    :x="
                        closedActionTokenX(
                            boardLayout.bookActionStartX + index * boardLayout.bookActionSpacing,
                            boardLayout.bookActionWidth,
                        )
                    "
                    :y="boardLayout.bookActionY + (boardLayout.bookActionHeight - closedActionTokenSize) / 2"
                    :width="closedActionTokenSize"
                    :height="closedActionTokenSize"
                    class="pointer-events-none"
                    preserveAspectRatio="xMidYMid meet"
                />
            </g>

            <g
                v-for="(action, index) in powerActions"
                :key="`power-${action.id}`"
                class="power-action-group"
                :class="[{ 'power-action-used': action.isUsed }, canSelectPowerAction(action) ? 'cursor-pointer' : '']"
                @click="canSelectPowerAction(action) && emit('powerActionClick', action)"
            >
                <title>
                    {{ action.description }}
                </title>
                <rect
                    :x="powerActionX(index)"
                    :y="boardLayout.powerActionY"
                    :width="boardLayout.powerActionWidth"
                    :height="boardLayout.powerActionHeight"
                    class="power-action-hitbox"
                    rx="12"
                />
                <image
                    v-if="action.isUsed"
                    :href="goldCrossUrl"
                    :x="closedActionTokenX(powerActionX(index), boardLayout.powerActionWidth)"
                    :y="boardLayout.powerActionY + (boardLayout.powerActionHeight - closedActionTokenSize) / 2"
                    :width="closedActionTokenSize"
                    :height="closedActionTokenSize"
                    class="pointer-events-none"
                    preserveAspectRatio="xMidYMid meet"
                />
            </g>

            <TerraformedTerrainTokens :hexes="visibleHexes" />
            <BridgeTokens
                :hexes="visibleHexes"
                :bridges="board.bridges ?? []"
                :players="players"
                :pending-bridge="pendingBridge"
            />

            <g
                v-for="hex in visibleHexes"
                :key="hex.id"
                :transform="`translate(${hex.x} ${hex.y})`"
                class="board-hex-group"
                :class="
                    selectableHexIds.includes(hex.id) || upgradeableBuildingHexIds.includes(hex.id)
                        ? 'cursor-pointer'
                        : ''
                "
                @click="
                    upgradeableBuildingHexIds.includes(hex.id)
                        ? emit('buildingClick', hex.id)
                        : selectableHexIds.includes(hex.id) && emit('hexClick', hex.id)
                "
            >
                <title>{{ terrainNames[hex.terrain] }} ({{ hex.q }}, {{ hex.r }})</title>
                <path
                    :d="roundedHexPath"
                    :fill="terrainColors[hex.terrain]"
                    class="board-hex"
                    :class="{
                        'board-hex-selectable':
                            selectableHexIds.includes(hex.id) || upgradeableBuildingHexIds.includes(hex.id),
                        'board-hex-pending': pendingHexId === hex.id,
                    }"
                    stroke-opacity="0.8"
                    stroke-width="2"
                    stroke-linejoin="round"
                />
                <image
                    v-if="hex.townTileId"
                    :href="townTileImage(hex.townTileId)"
                    x="-64"
                    y="-72.5"
                    width="128"
                    height="145"
                    class="pointer-events-none drop-shadow-md"
                    preserveAspectRatio="xMidYMid meet"
                />
                <image
                    v-if="hex.building?.hasAnnex"
                    :href="annexUrl"
                    x="8"
                    y="-41"
                    width="72"
                    height="81"
                    class="pointer-events-none drop-shadow-md"
                    preserveAspectRatio="xMidYMid meet"
                />
                <image
                    v-if="hex.building"
                    :href="buildingImage(hex.building.ownerPlayerId, hex.building.type, hex.building.isNeutral)"
                    x="-38"
                    y="-46"
                    width="76"
                    height="85"
                    class="building-image pointer-events-none"
                    preserveAspectRatio="xMidYMid meet"
                />
            </g>

            <image
                v-for="hex in waterTownTokens"
                :key="`water-town-${hex.id}`"
                :href="townTileImage(hex.townTileId!)"
                :x="hex.x - 64"
                :y="hex.y - 72.5"
                width="128"
                height="145"
                class="pointer-events-none drop-shadow-md"
                preserveAspectRatio="xMidYMid meet"
            />
        </svg>
    </div>
</template>

<style scoped>
.board-hex {
    fill-opacity: 0;
    transition: fill-opacity 150ms ease-in-out;
}

.board-hex-selectable {
    fill-opacity: 0.28;
    stroke: rgb(24 129 24 / 0.75);
    stroke-width: 8;
}

.board-hex-pending {
    fill-opacity: 0.5;
    stroke: #facc15;
    stroke-width: 8;
}

.building-image {
    filter: drop-shadow(0 4px 3px rgb(0 0 0 / 0.5));
}

.book-action-hitbox {
    fill: transparent;
    pointer-events: all;
}

.book-action-image {
    pointer-events: none;
}

.power-action-hitbox {
    fill: transparent;
    pointer-events: all;
    stroke: transparent;
    stroke-width: 5;
    transition:
        fill 150ms ease-in-out,
        stroke 150ms ease-in-out;
}
</style>
