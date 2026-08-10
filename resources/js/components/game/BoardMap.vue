<script setup lang="ts">
import { computed } from 'vue';
import type {
    BoardState,
    BookAction,
    FinalRoundScoringTile,
    RoundScoringTile,
    TerrainType,
} from '@/types';
import gameBoardUrl from '../../../images/game_board.webp';

type Props = {
    board: BoardState;
    roundScoringTiles?: RoundScoringTile[];
    finalRoundScoringTile?: FinalRoundScoringTile | null;
    bookActions?: BookAction[];
};

const props = withDefaults(defineProps<Props>(), {
    roundScoringTiles: () => [],
    finalRoundScoringTile: null,
    bookActions: () => [],
});

const boardWidth = 2004;
const boardHeight = 1285;
const boardOriginX = 327;
const boardOriginY = 135;
const columnSpacing = 128;
const rowOffset = 64;
const rowSpacing = 111;
const hexRadiusX = 62;
const hexRadiusY = 72;
const cornerRatio = 0.1;
const roundScoringTileX = 31;
const firstRoundScoringTileY = 1005;
const roundScoringTileSpacing = 124;
const roundScoringTileWidth = 203;
const roundScoringTileHeight = 126;
const bookActionY = 1178;
const bookActionStartX = 20;
const bookActionSpacing = 213;
const bookActionWidth = 185;
const bookActionHeight = 90;

const roundScoringTileImages = import.meta.glob<string>(
    '../../../images/round_scoring_tiles/*.png',
    { eager: true, import: 'default', query: '?url' },
);
const finalRoundScoringTileImages = import.meta.glob<string>(
    '../../../images/final_round_scoring_tiles/*.png',
    { eager: true, import: 'default', query: '?url' },
);
const bookActionImages = import.meta.glob<string>(
    '../../../images/book_actions/*.png',
    { eager: true, import: 'default', query: '?url' },
);

const terrainColors: Record<TerrainType, string> = {
    desert: '#e9c65c',
    plains: '#9c6339',
    swamp: '#334c62',
    lake: '#39a6bd',
    forest: '#477c48',
    mountain: '#87909a',
    wasteland: '#b8513e',
};

const terrainNames: Record<TerrainType, string> = {
    desert: 'Пустыня',
    plains: 'Равнина',
    swamp: 'Болото',
    lake: 'Озеро',
    forest: 'Лес',
    mountain: 'Горы',
    wasteland: 'Пустошь',
};

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

const pointTowards = (
    from: { x: number; y: number },
    to: { x: number; y: number },
) => ({
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

const rawHexes = computed(() =>
    props.board.hexes.map((hex) => ({
        ...hex,
        x: boardOriginX + columnSpacing * hex.q + rowOffset * hex.r,
        y: boardOriginY + rowSpacing * hex.r,
    })),
);

function roundScoringTileImage(tile: RoundScoringTile): string {
    return (
        roundScoringTileImages[
            `../../../images/round_scoring_tiles/${tile}.png`
        ] ?? ''
    );
}

function finalRoundScoringTileImage(tile: FinalRoundScoringTile): string {
    return (
        finalRoundScoringTileImages[
            `../../../images/final_round_scoring_tiles/${tile}.png`
        ] ?? ''
    );
}

function bookActionImage(action: BookAction): string {
    return (
        bookActionImages[`../../../images/book_actions/${action}.png`] ?? ''
    );
}

function roundScoringTileY(index: number): number {
    return firstRoundScoringTileY - index * roundScoringTileSpacing;
}
</script>

<template>
    <div
        class="overflow-x-auto rounded-xl border border-border bg-black shadow-inner"
    >
        <svg
            :viewBox="`0 0 ${boardWidth} ${boardHeight}`"
            class="block h-auto w-full min-w-[48rem]"
            role="img"
            aria-label="Игровая карта"
        >
            <image
                :href="gameBoardUrl"
                x="0"
                y="0"
                :width="boardWidth"
                :height="boardHeight"
                preserveAspectRatio="xMidYMid meet"
            />

            <g
                v-for="(tile, index) in roundScoringTiles"
                :key="`round-${index}-${tile}`"
            >
                <title>
                    Раунд {{ index + 1 }}: {{ roundScoringTileNames[tile] }}
                </title>
                <image
                    :href="roundScoringTileImage(tile)"
                    :x="roundScoringTileX"
                    :y="roundScoringTileY(index)"
                    :width="roundScoringTileWidth"
                    :height="roundScoringTileHeight"
                    preserveAspectRatio="xMidYMid meet"
                />
                <image
                    v-if="index === 5 && finalRoundScoringTile"
                    :href="finalRoundScoringTileImage(finalRoundScoringTile)"
                    :x="roundScoringTileX"
                    :y="roundScoringTileY(index)"
                    :width="roundScoringTileWidth"
                    :height="roundScoringTileHeight"
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
            >
                <title>Действие за книги: {{ bookActionNames[action] }}</title>
                <image
                    :href="bookActionImage(action)"
                    :x="bookActionStartX + index * bookActionSpacing"
                    :y="bookActionY"
                    :width="bookActionWidth"
                    :height="bookActionHeight"
                    preserveAspectRatio="xMidYMid meet"
                />
            </g>

            <g
                v-for="hex in rawHexes"
                :key="hex.id"
                :transform="`translate(${hex.x} ${hex.y})`"
                class="board-hex-group cursor-pointer"
            >
                <title>
                    {{ terrainNames[hex.terrain] }} ({{ hex.q }}, {{ hex.r }})
                </title>
                <path
                    :d="roundedHexPath"
                    :fill="terrainColors[hex.terrain]"
                    class="board-hex"
                    stroke-opacity="0.8"
                    stroke-width="2"
                    stroke-linejoin="round"
                />
                <text
                    y="4"
                    text-anchor="middle"
                    fill="rgba(255, 255, 255, 0.9)"
                    font-size="18"
                    font-weight="600"
                    paint-order="stroke"
                    stroke="rgba(0, 0, 0, 0.6)"
                    stroke-width="3"
                >
                    {{ hex.q }}:{{ hex.r }}
                </text>
            </g>
        </svg>
    </div>
</template>

<style scoped>
.board-hex {
    fill-opacity: 0;
    transition: fill-opacity 150ms ease-in-out;
}

.board-hex-group:hover .board-hex {
    fill-opacity: 0.42;
}
</style>
