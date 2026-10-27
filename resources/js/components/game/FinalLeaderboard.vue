<script setup lang="ts">
import Trophy from '@lucide/vue/dist/esm/icons/trophy.mjs';
import { computed } from 'vue';
import VictoryPointSourceIcon from '@/components/game/VictoryPointSourceIcon.vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { factionNames, playerColorValues } from '@/lib/gameDisplay';
import type { GamePlayerBoardState, GamePlayerSummary } from '@/types';

const props = defineProps<{
    players: GamePlayerSummary[];
    playerStates: GamePlayerBoardState[];
}>();

const standings = computed(() => {
    const sortedPlayers = props.players
        .flatMap((player) => {
            const state = props.playerStates.find((candidate) => candidate.playerId === player.id);

            return state === undefined
                ? []
                : [{ player, victoryPoints: state.victoryPoints, sources: state.victoryPointSources ?? {} }];
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

const scoreCategories = computed(() => [
    { id: 'starting', label: 'Стартовые очки' },
    { id: 'round_scoring', label: 'Цели раундов' },
    { id: 'final_objectives', label: 'Финальные цели игры' },
    { id: 'final_knowledge', label: 'Финальные места на шкалах знаний' },
    { id: 'town', label: 'Федерации' },
    { id: 'innovation', label: 'Инновации' },
    { id: 'competency', label: 'Компетенции' },
    { id: 'round_bonus', label: 'Бонусы раундов' },
    { id: 'palace', label: 'Бонусы дворца' },
    { id: 'development', label: 'Судоходство и преобразование' },
    { id: 'faction', label: 'Бонусы расы' },
    { id: 'knowledge_income', label: 'Доход от знаний' },
    { id: 'book_actions', label: 'Действия за книги' },
    { id: 'end_game_resources', label: 'Оставшиеся ресурсы' },
    { id: 'power_offers', label: 'Потери за принятую Силу' },
    ...(standings.value.some((entry) => (entry.sources.untracked ?? 0) !== 0)
        ? [{ id: 'untracked', label: 'Очки до начала учёта статистики' }]
        : []),
]);
</script>

<template>
    <section
        class="w-full max-w-[88rem] min-w-0 justify-self-center overflow-hidden rounded-xl border border-amber-800/20 bg-linear-to-br from-amber-50 via-background to-orange-50 shadow-md shadow-amber-950/10 dark:border-amber-600/30 dark:from-amber-950/40 dark:via-background dark:to-stone-950"
        aria-labelledby="final-leaderboard-title"
    >
        <header
            class="flex items-center justify-center gap-3 border-b border-amber-800/15 px-4 py-3 dark:border-amber-400/20"
        >
            <Trophy class="size-5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
            <div class="text-center">
                <p class="text-[0.625rem] font-bold tracking-[0.18em] text-amber-700 uppercase dark:text-amber-300">
                    Партия завершена
                </p>
                <h2 id="final-leaderboard-title" class="text-lg font-black tracking-tight">Итоговый лидерборд</h2>
            </div>
        </header>
        <div class="overflow-x-auto">
            <ol class="grid min-w-max gap-3 p-4">
                <li
                    v-for="entry in standings"
                    :key="entry.player.id"
                    class="grid grid-cols-[4rem_minmax(0,1fr)_5rem] items-center gap-x-2 gap-y-1 rounded-l-full rounded-r-2xl border bg-background/70 px-2 py-2 shadow-sm"
                    :style="{
                        '--player-color':
                            entry.player.color === null ? '#a1a1aa' : playerColorValues[entry.player.color],
                        borderColor: 'color-mix(in srgb, var(--player-color) 65%, var(--border))',
                        backgroundImage:
                            'linear-gradient(90deg, color-mix(in srgb, var(--player-color) 18%, transparent), transparent)',
                    }"
                >
                    <div class="relative row-span-2 self-center">
                        <VictoryPointSourceIcon
                            source="faction"
                            portrait
                            :faction="entry.player.faction"
                            class="size-30"
                        />
                    </div>
                    <div
                        class="ml-12 flex items-stretch tabular-nums"
                        :aria-label="`Статистика победных очков: ${entry.player.name}`"
                    >
                        <TooltipProvider :delay-duration="150">
                            <Tooltip v-for="category in scoreCategories" :key="category.id">
                                <TooltipTrigger as-child>
                                    <span
                                        class="grid min-w-18 flex-1 justify-items-center gap-1 border-l border-amber-900/15 px-1 outline-none first:border-l-0 focus-visible:ring-2 focus-visible:ring-ring dark:border-amber-200/15"
                                        :class="
                                            (entry.sources[category.id] ?? 0) < 0
                                                ? 'text-red-600 dark:text-red-400'
                                                : (entry.sources[category.id] ?? 0) === 0
                                                  ? 'text-muted-foreground/50'
                                                  : 'text-foreground'
                                        "
                                        :data-score-category="category.id"
                                        :aria-label="`${category.label}: ${entry.sources[category.id] ?? 0}`"
                                        tabindex="0"
                                    >
                                        <VictoryPointSourceIcon
                                            :source="category.id"
                                            :faction="entry.player.faction"
                                            class="size-15"
                                        />
                                        <span class="text-base leading-none font-bold" aria-hidden="true">{{
                                            (entry.sources[category.id] ?? 0) === 0 ? '—' : entry.sources[category.id]
                                        }}</span>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ category.label }}: {{ entry.sources[category.id] ?? 0 }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                    <div
                        class="flex h-full items-center justify-center border-l border-amber-900/20 text-3xl font-black tabular-nums dark:border-amber-200/20"
                        :aria-label="`Итоговые победные очки: ${entry.victoryPoints}`"
                    >
                        {{ entry.victoryPoints }}
                    </div>
                    <div class="col-span-2 col-start-2 ml-10 flex items-baseline gap-2 px-1 text-sm">
                        <strong>{{ entry.player.name }}</strong>
                        <span v-if="entry.player.faction !== null" class="text-xs text-muted-foreground"
                            >({{ factionNames[entry.player.faction] }})</span
                        >
                    </div>
                </li>
            </ol>
        </div>
    </section>
</template>
