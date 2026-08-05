<script setup lang="ts">
import { computed } from 'vue';
import type { BoardState, TerrainType } from '@/types';
import gameBoardUrl from '../../../images/game_board.webp';

type Props = {
    board: BoardState;
};

const props = defineProps<Props>();

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
