<script setup lang="ts">
import { computed, type CSSProperties } from 'vue';
import type { TownTile } from '@/types';

const props = defineProps<{
    townTiles: TownTile[];
}>();

const townTileLayerOffset = 3;
const townTileStacks = computed(() => [...new Set(props.townTiles)].map((townTile) => ({
    townTile,
    count: props.townTiles.filter((candidate) => candidate === townTile).length,
})));

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
        class="grid grid-cols-7 gap-3 rounded-xl bg-card/50 p-3 shadow-sm"
    >
        <span
            v-for="stack in townTileStacks"
            :key="stack.townTile"
            class="relative aspect-[128/145]"
            :aria-label="`Стопка из ${stack.count} жетонов города ${stack.townTile}`"
        >
            <img
                v-for="layer in stack.count"
                :key="layer"
                :src="townTileImage(stack.townTile)"
                :style="townTileLayerStyle(layer)"
                alt=""
                class="absolute inset-0 size-full object-contain drop-shadow-[-2px_2px_2px_rgba(0,0,0,0.45)]"
            />
        </span>
    </section>
</template>
