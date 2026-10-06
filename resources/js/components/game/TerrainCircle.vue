<script setup lang="ts">
import { terrainNames } from '@/lib/gameDisplay';
import type { GamePlayerSummary, TerrainType } from '@/types';

defineProps<{ players: GamePlayerSummary[] }>();

const terrains: TerrainType[] = ['desert', 'plains', 'swamp', 'lake', 'forest', 'mountain', 'wasteland'];
const images = import.meta.glob<string>('../../../images/terrain_tokens/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
});

function terrainPosition(index: number): { left: string; top: string } {
    const angle = (index * 2 * Math.PI) / terrains.length - Math.PI / 2;
    return { left: `${50 + 36 * Math.cos(angle)}%`, top: `${50 + 36 * Math.sin(angle)}%` };
}
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <div class="relative size-80 max-w-full" aria-label="Круг земель">
            <div class="absolute inset-[14%] rounded-full border-2 border-border" aria-hidden="true" />
            <p class="absolute inset-[32%] grid place-items-center text-center text-xs text-muted-foreground">
                Один шаг по кругу — одна лопата
            </p>
            <div
                v-for="(terrain, index) in terrains"
                :key="terrain"
                class="absolute flex w-24 -translate-x-1/2 -translate-y-1/2 flex-col items-center text-center"
                :style="terrainPosition(index)"
            >
                <img
                    :src="images[`../../../images/terrain_tokens/${terrain}.png`]"
                    :alt="terrainNames[terrain]"
                    :title="terrainNames[terrain]"
                    class="size-18 rounded-full object-contain drop-shadow-sm"
                />
                <span
                    v-for="player in players.filter((player) => player.homeland === terrain)"
                    :key="player.id"
                    class="max-w-full truncate text-xs font-semibold"
                    :title="player.name"
                    >{{ player.name }}</span
                >
            </div>
        </div>
    </div>
</template>
