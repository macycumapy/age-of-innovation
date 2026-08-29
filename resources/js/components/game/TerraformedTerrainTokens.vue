<script setup lang="ts">
import { computed } from 'vue';
import { terrainNames } from '@/lib/gameDisplay';
import type { BoardHexState, TerrainType } from '@/types';

type PositionedBoardHex = BoardHexState & {
    x: number;
    y: number;
};

const props = defineProps<{
    hexes: PositionedBoardHex[];
}>();

const terrainTokenImages = import.meta.glob<string>('../../../images/terrain_tokens/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
});

const terraformedHexes = computed(() =>
    props.hexes.filter((hex) => hex.terrain !== 'water' && hex.terrain !== hex.initialTerrain),
);

function terrainTokenImage(terrain: TerrainType): string {
    if (terrain === 'water') {
        return '';
    }

    return terrainTokenImages[`../../../images/terrain_tokens/${terrain}.png`] ?? '';
}
</script>

<template>
    <g class="pointer-events-none" aria-label="Терраформированные земли">
        <defs>
            <clipPath id="terrain-token-circle" clipPathUnits="userSpaceOnUse">
                <circle cx="0" cy="0" r="64" />
            </clipPath>
        </defs>

        <g v-for="hex in terraformedHexes" :key="`terrain-token-${hex.id}`" :transform="`translate(${hex.x} ${hex.y})`">
            <title>{{ terrainNames[hex.terrain] }} ({{ hex.q }}, {{ hex.r }})</title>
            <image
                :href="terrainTokenImage(hex.terrain)"
                x="-64"
                y="-64"
                width="128"
                height="128"
                clip-path="url(#terrain-token-circle)"
                preserveAspectRatio="xMidYMid meet"
            />
        </g>
    </g>
</template>
