<script setup lang="ts">
import { computed } from 'vue';
import type { BoardState, TerrainType } from '@/types';

type Props = {
    board: BoardState;
};

const props = defineProps<Props>();

const hexSize = 38;
const hexWidth = Math.sqrt(3) * hexSize;
const hexHeight = hexSize * 2;
const padding = 12;

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

const polygonPoints = Array.from({ length: 6 }, (_, index) => {
    const angle = ((60 * index - 30) * Math.PI) / 180;

    return `${hexSize * Math.cos(angle)},${hexSize * Math.sin(angle)}`;
}).join(' ');

const rawHexes = computed(() =>
    props.board.hexes.map((hex) => ({
        ...hex,
        x: hexWidth * (hex.q + hex.r / 2),
        y: hexSize * 1.5 * hex.r,
    })),
);

const bounds = computed(() => {
    const xCoordinates = rawHexes.value.map((hex) => hex.x);
    const yCoordinates = rawHexes.value.map((hex) => hex.y);

    return {
        minX: Math.min(...xCoordinates) - hexWidth / 2 - padding,
        minY: Math.min(...yCoordinates) - hexHeight / 2 - padding,
        width:
            Math.max(...xCoordinates) -
            Math.min(...xCoordinates) +
            hexWidth +
            padding * 2,
        height:
            Math.max(...yCoordinates) -
            Math.min(...yCoordinates) +
            hexHeight +
            padding * 2,
    };
});

const viewBox = computed(
    () =>
        `${bounds.value.minX} ${bounds.value.minY} ${bounds.value.width} ${bounds.value.height}`,
);
</script>

<template>
    <div
        class="overflow-x-auto rounded-xl border border-sky-950/30 bg-sky-800 p-3 shadow-inner dark:border-sky-300/20 dark:bg-sky-950"
    >
        <svg
            :viewBox="viewBox"
            class="block h-auto w-full min-w-[48rem]"
            role="img"
            aria-label="Игровая карта"
        >
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
                    stroke="#f5eed9"
                    stroke-width="3"
                    stroke-linejoin="round"
                />
                <text
                    y="4"
                    text-anchor="middle"
                    fill="rgba(255, 255, 255, 0.78)"
                    font-size="10"
                    font-weight="600"
                >
                    {{ hex.q }}:{{ hex.r }}
                </text>
            </g>
        </svg>
    </div>
</template>
