<script setup lang="ts">
import { Crown, Medal, Trophy } from '@lucide/vue';
import { computed } from 'vue';
import { factionNames, playerColorValues } from '@/lib/gameDisplay';
import type { GamePlayerBoardState, GamePlayerSummary } from '@/types';
import victoryPointsUrl from '../../../images/token_parts/sunflower.png';

const props = defineProps<{
    players: GamePlayerSummary[];
    playerStates: GamePlayerBoardState[];
}>();

const standings = computed(() => {
    const sortedPlayers = props.players
        .flatMap((player) => {
            const state = props.playerStates.find((candidate) => candidate.playerId === player.id);

            return state === undefined ? [] : [{ player, victoryPoints: state.victoryPoints }];
        })
        .sort((first, second) => second.victoryPoints - first.victoryPoints || first.player.seat - second.player.seat);

    return sortedPlayers.map((entry, index) => ({
        ...entry,
        place:
            index > 0 && sortedPlayers[index - 1]?.victoryPoints === entry.victoryPoints
                ? sortedPlayers.findIndex((candidate) => candidate.victoryPoints === entry.victoryPoints) + 1
                : index + 1,
    }));
});

function placeLabel(place: number): string {
    return `${place}-е место`;
}
</script>

<template>
    <section
        class="w-full max-w-2xl justify-self-center overflow-hidden rounded-xl border border-amber-300/60 bg-linear-to-br from-amber-50 via-background to-orange-50 shadow-md shadow-amber-950/10 dark:border-yellow-700/50 dark:from-amber-950/40 dark:via-background dark:to-orange-950/30"
        aria-labelledby="final-leaderboard-title"
    >
        <header class="flex items-center justify-center gap-2 border-b border-amber-300/40 px-4 py-2">
            <Trophy class="size-5 text-amber-500 drop-shadow-sm" aria-hidden="true" />
            <div class="text-center">
                <p class="text-[0.625rem] font-bold tracking-[0.18em] text-amber-700 uppercase dark:text-amber-300">
                    Партия завершена
                </p>
                <h2 id="final-leaderboard-title" class="text-lg font-black tracking-tight">Итоговый лидерборд</h2>
            </div>
            <Trophy class="size-5 scale-x-[-1] text-amber-500 drop-shadow-sm" aria-hidden="true" />
        </header>

        <ol class="grid gap-1.5 p-2">
            <li
                v-for="entry in standings"
                :key="entry.player.id"
                class="relative grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 overflow-hidden rounded-lg border bg-background/80 px-3 py-1.5 shadow-sm backdrop-blur-sm"
                :class="entry.place === 1 ? 'border-amber-400/80 ring-1 ring-amber-300/50' : 'border-border/70'"
            >
                <span
                    class="absolute inset-y-0 left-0 w-1.5"
                    :style="{
                        backgroundColor:
                            entry.player.color === null ? '#a1a1aa' : playerColorValues[entry.player.color],
                    }"
                    aria-hidden="true"
                />

                <span
                    class="grid size-8 place-items-center rounded-full text-xs font-black shadow-inner"
                    :class="{
                        'bg-amber-400 text-amber-950': entry.place === 1,
                        'bg-slate-300 text-slate-800 dark:bg-slate-600 dark:text-slate-50': entry.place === 2,
                        'bg-orange-300 text-orange-950 dark:bg-orange-700 dark:text-orange-50': entry.place === 3,
                        'bg-muted text-muted-foreground': entry.place > 3,
                    }"
                    :aria-label="placeLabel(entry.place)"
                >
                    <Crown v-if="entry.place === 1" class="size-4" aria-hidden="true" />
                    <Medal v-else-if="entry.place <= 3" class="size-4" aria-hidden="true" />
                    <span v-else>{{ entry.place }}</span>
                </span>

                <div class="min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                        <strong class="truncate text-sm">{{ entry.player.user.name }}</strong>
                        <span class="text-[0.625rem] font-bold text-muted-foreground uppercase">
                            {{ placeLabel(entry.place) }}
                        </span>
                    </div>
                    <p v-if="entry.player.faction !== null" class="truncate text-xs text-muted-foreground">
                        {{ factionNames[entry.player.faction] }}
                    </p>
                </div>

                <div
                    class="relative grid size-11 place-items-center"
                    :aria-label="`Итоговые победные очки: ${entry.victoryPoints}`"
                >
                    <img :src="victoryPointsUrl" alt="" class="absolute size-full object-contain drop-shadow-md" />
                    <span class="relative z-10 text-sm font-black text-yellow-500">{{ entry.victoryPoints }}</span>
                </div>
            </li>
        </ol>
    </section>
</template>
