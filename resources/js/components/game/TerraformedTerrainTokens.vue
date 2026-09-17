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

        <TransitionGroup name="terrain-token">
            <g
                v-for="hex in terraformedHexes"
                :key="`terrain-token-${hex.id}-${hex.terrain}`"
                :transform="`translate(${hex.x} ${hex.y})`"
            >
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
        </TransitionGroup>
    </g>
</template>

<style scoped>
.terrain-token-enter-active,
.terrain-token-leave-active {
    transition: opacity 320ms ease-in-out;
}

.terrain-token-enter-active image,
.terrain-token-leave-active image {
    transform-box: fill-box;
    transform-origin: center;
    transition: transform 320ms cubic-bezier(0.22, 1, 0.36, 1);
}

.terrain-token-enter-from,
.terrain-token-leave-to {
    opacity: 0;
}

.terrain-token-enter-from image {
    transform: scale(0.72) rotate(-8deg);
}

.terrain-token-leave-to image {
    transform: scale(1.08);
}

@media (prefers-reduced-motion: reduce) {
    .terrain-token-enter-active,
    .terrain-token-leave-active,
    .terrain-token-enter-active image,
    .terrain-token-leave-active image {
        transition-duration: 0.01ms;
    }
}
</style>
