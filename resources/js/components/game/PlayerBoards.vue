<script setup lang="ts">
import { computed } from 'vue';
import type { GamePlayerSummary, PlayerColor } from '@/types';

const props = defineProps<{
    players: GamePlayerSummary[];
    currentUserId: number;
}>();

const boardImages = import.meta.glob(
    '../../../images/terrain_boards/*.webp',
    {
        eager: true,
        import: 'default',
        query: '?url',
    },
) as Record<string, string>;

const playersWithBoards = computed(() => {
    const players = props.players.filter(
        (player): player is GamePlayerSummary & { color: PlayerColor } =>
            player.color !== null,
    );
    const currentPlayerIndex = players.findIndex(
        (player) => player.user.id === props.currentUserId,
    );

    if (currentPlayerIndex <= 0) {
        return players;
    }

    return [
        players[currentPlayerIndex],
        ...players.slice(0, currentPlayerIndex),
        ...players.slice(currentPlayerIndex + 1),
    ];
});

function boardImage(color: PlayerColor): string {
    return boardImages[
        `../../../images/terrain_boards/${color}.webp`
    ];
}
</script>

<template>
    <section v-if="playersWithBoards.length" class="grid gap-4">
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <figure
                v-for="(player, index) in playersWithBoards"
                :key="player.id"
                class="overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <img
                    :src="boardImage(player.color)"
                    :alt="`Планшет игрока ${player.user.name}`"
                    class="block h-auto w-full"
                />
            </figure>
        </div>
    </section>
</template>
