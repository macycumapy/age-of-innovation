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

const polygonPoints = [
    `0,${-hexRadiusY}`,
    `${hexRadiusX},${-hexRadiusY / 2}`,
    `${hexRadiusX},${hexRadiusY / 2}`,
    `0,${hexRadiusY}`,
    `${-hexRadiusX},${hexRadiusY / 2}`,
    `${-hexRadiusX},${-hexRadiusY / 2}`,
].join(' ');

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
            >
                <title>
                    {{ terrainNames[hex.terrain] }} ({{ hex.q }}, {{ hex.r }})
                </title>
                <polygon
                    :points="polygonPoints"
                    :fill="terrainColors[hex.terrain]"
                    fill-opacity="0"
                    stroke="#f5eed9"
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
