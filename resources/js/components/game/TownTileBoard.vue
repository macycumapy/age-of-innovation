<script setup lang="ts">
import type { CSSProperties } from 'vue';
import type { TownTile } from '@/types';

defineProps<{
    townTiles: TownTile[];
}>();

const townTileStackSize = 3;
const townTileLayerOffset = 3;

const townTileImages = import.meta.glob('../../../images/cities/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

function townTileImage(townTile: TownTile): string {
    return townTileImages[`../../../images/cities/${townTile}.png`];
}

function townTileLayerStyle(layer: number): CSSProperties {
    const offset = (layer - 1) * townTileLayerOffset;

    return {
        transform: `translate(${offset}px, ${-offset}px)`,
    };
}
</script>

<template>
    <section
        v-if="townTiles.length"
        class="grid grid-cols-7 gap-3 rounded-xl bg-card p-3 shadow-sm"
    >
        <span
            v-for="townTile in townTiles"
            :key="townTile"
            class="relative aspect-[128/145]"
            :aria-label="`Стопка из ${townTileStackSize} жетонов города ${townTile}`"
        >
            <img
                v-for="layer in townTileStackSize"
                :key="layer"
                :src="townTileImage(townTile)"
                :style="townTileLayerStyle(layer)"
                alt=""
                class="absolute inset-0 size-full object-contain drop-shadow-[-2px_2px_2px_rgba(0,0,0,0.45)]"
            />
        </span>
    </section>
</template>
