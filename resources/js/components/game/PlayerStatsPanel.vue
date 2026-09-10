<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { GameHistoryPage, GamePlayerBoardState, GamePlayerSummary } from '@/types';
import GameHistory from '@/components/game/GameHistory.vue';
import { playerColorValues } from '@/lib/gameDisplay';
import annexUrl from '../../../images/buildings/white/annex.png';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import toolUrl from '../../../images/token_parts/cube.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import townKeyUrl from '../../../images/token_parts/key.png';
import coinUrl from '../../../images/token_parts/gold_medallion.png';
import handUrl from '../../../images/token_parts/hand.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import manaUrl from '../../../images/token_parts/mana.png';
import networkUrl from '../../../images/token_parts/network.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';
import bowlOneUrl from '../../../images/token_parts/roman_one.png';
import bowlThreeUrl from '../../../images/token_parts/roman_three.png';
import bowlTwoUrl from '../../../images/token_parts/roman_two.png';
import bookUrl from '../../../images/token_parts/gray_book.png';
import knowledgeUrl from '../../../images/token_parts/gear.png';
import scholarUrl from '../../../images/token_parts/scholar.png';
import shovelUrl from '../../../images/token_parts/shovel.png';
import victoryPointsUrl from '../../../images/token_parts/sunflower.png';
import endOfTurnUrl from '../../../images/token_parts/end_of_turn.png';
import shippingUrl from '../../../images/token_parts/ship.png';

type StatCounter = {
    label: string;
    image: string;
    value: number;
};

const props = defineProps<{
    players: GamePlayerSummary[];
    playerStates: GamePlayerBoardState[];
    currentPlayerId: number | null;
    activePlayerId: number | null;
    gameId: number;
    history: GameHistoryPage;
    canUndoLastAction: boolean;
}>();

const isOpen = ref(false);

const playersWithStats = computed(() => {
    const activePlayerIds = props.players
        .filter((player) => {
            const state = props.playerStates.find((candidate) => candidate.playerId === player.id);

            return state?.passOrder === null;
        })
        .map((player) => player.id);
    const orderedPlayers = [...props.players].sort((firstPlayer, secondPlayer) => {
        if (firstPlayer.id === props.currentPlayerId) {
            return -1;
        }

        if (secondPlayer.id === props.currentPlayerId) {
            return 1;
        }

        return 0;
    });

    return orderedPlayers.flatMap((player) => {
        const state = props.playerStates.find((candidate) => candidate.playerId === player.id);
        const turnOrder = activePlayerIds.indexOf(player.id) + 1;

        return state === undefined ? [] : [{ player, state, turnOrder }];
    });
});

function playerBackgroundColor(player: GamePlayerSummary): string {
    const color = player.color === null ? '#a1a1aa' : playerColorValues[player.color];

    return `color-mix(in srgb, ${color} 35%, transparent)`;
}

function balanceCounters(state: GamePlayerBoardState): StatCounter[] {
    return [
        {
            label: 'Мана в чаше I',
            image: bowlOneUrl,
            value: state.power.bowlOne,
        },
        {
            label: 'Мана в чаше II',
            image: bowlTwoUrl,
            value: state.power.bowlTwo,
        },
        {
            label: 'Мана в чаше III',
            image: bowlThreeUrl,
            value: state.power.bowlThree,
        },
        {
            label: 'Активные ключи',
            image: townKeyUrl,
            value: state.activeTownKeys,
        },
        { label: 'Инструменты', image: toolUrl, value: state.tools },
        { label: 'Золото', image: coinUrl, value: state.coins },
        { label: 'Учёные', image: scholarUrl, value: state.scholars },
        {
            label: 'Доступные пристройки',
            image: annexUrl,
            value: state.availableAnnexes,
        },
    ];
}

function bookCounters(state: GamePlayerBoardState): StatCounter[] {
    return [
        {
            label: 'Книги банковского дела',
            image: bankingBookUrl,
            value: state.books.banking,
        },
        { label: 'Книги права', image: lawBookUrl, value: state.books.law },
        {
            label: 'Книги инженерного дела',
            image: engineeringBookUrl,
            value: state.books.engineering,
        },
        {
            label: 'Книги медицины',
            image: medicineBookUrl,
            value: state.books.medicine,
        },
    ];
}

function incomeCounters(state: GamePlayerBoardState): StatCounter[] {
    return [
        {
            label: 'Доход инструментов',
            image: toolUrl,
            value: state.income.tools,
        },
        { label: 'Доход золота', image: coinUrl, value: state.income.coins },
        {
            label: 'Доход учёных',
            image: scholarUrl,
            value: state.income.scholars,
        },
        { label: 'Доход силы', image: manaUrl, value: state.income.power },
        { label: 'Доход книг', image: bookUrl, value: state.income.books },
        {
            label: 'Доход шагов культа',
            image: knowledgeUrl,
            value: state.income.knowledgeSteps,
        },
        {
            label: 'Доход победных очков',
            image: victoryPointsUrl,
            value: state.income.victoryPoints,
        },
    ];
}

function levelCounters(state: GamePlayerBoardState): StatCounter[] {
    return [
        {
            label: 'Уровень навигации',
            image: shippingUrl,
            value: Math.max(0, state.shippingLevel) + 1,
        },
        {
            label: 'Уровень лопаты',
            image: shovelUrl,
            value: Math.max(0, state.terraformingLevel) + 1,
        },
        {
            label: 'Максимальная сеть зданий',
            image: networkUrl,
            value: state.largestNetworkSize,
        },
    ];
}
</script>

<template>
    <aside
        class="sticky top-0 z-40 h-svh shrink-0 transition-[width] duration-300"
        :class="isOpen ? 'w-[min(20rem,calc(100vw-3rem))]' : 'w-0'"
        aria-label="Баланс и статистика игроков"
    >
        <div
            class="absolute inset-y-0 right-0 w-[min(20rem,calc(100vw-3rem))] text-sidebar-foreground transition-transform duration-300"
            :class="isOpen ? 'translate-x-0' : 'translate-x-full'"
        >
            <button
                type="button"
                class="absolute top-4 grid size-9 -translate-x-full place-items-center rounded-l-lg border border-r-0 border-sidebar-border bg-sidebar text-sidebar-foreground shadow-sm transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none"
                :aria-label="isOpen ? 'Скрыть статистику' : 'Показать статистику'"
                :aria-expanded="isOpen"
                @click="isOpen = !isOpen"
            >
                <ChevronRight v-if="isOpen" class="size-5" />
                <ChevronLeft v-else class="size-5" />
            </button>

            <div
                class="grid h-full grid-rows-[auto_1fr] overflow-hidden rounded-lg border border-sidebar-border bg-sidebar/70 shadow-sm"
            >
                <div class="grid min-w-0 content-start gap-4 overflow-y-auto p-4">
                    <article
                        v-for="entry in playersWithStats"
                        :key="entry.player.id"
                        class="grid max-w-full min-w-0 gap-3 rounded-lg border border-sidebar-border p-3"
                        :style="{ backgroundColor: playerBackgroundColor(entry.player) }"
                    >
                        <h3 class="flex min-w-0 items-center gap-2 font-semibold">
                            <span
                                v-if="entry.state.passOrder === null"
                                class="relative grid size-6 shrink-0 place-items-center rounded-full border border-sidebar-border bg-sidebar/70 text-xs font-bold shadow-sm"
                                :class="{ 'ring-2 ring-emerald-400/80': entry.player.id === activePlayerId }"
                                :title="
                                    entry.player.id === activePlayerId
                                        ? `Сейчас ходит. Порядок хода в текущем раунде: ${entry.turnOrder}`
                                        : `Порядок хода в текущем раунде: ${entry.turnOrder}`
                                "
                                :aria-label="
                                    entry.player.id === activePlayerId
                                        ? `Сейчас ходит. Порядок хода в текущем раунде: ${entry.turnOrder}`
                                        : `Порядок хода в текущем раунде: ${entry.turnOrder}`
                                "
                            >
                                <span
                                    v-if="entry.player.id === activePlayerId"
                                    class="absolute inset-0 rounded-full border-2 border-emerald-400/70 motion-safe:animate-ping"
                                    aria-hidden="true"
                                />
                                <span class="relative">{{ entry.turnOrder }}</span>
                            </span>
                            <span
                                v-else
                                class="relative grid size-6 shrink-0 place-items-center"
                                :title="`Порядок паса: ${entry.state.passOrder}`"
                                :aria-label="`Порядок паса: ${entry.state.passOrder}`"
                            >
                                <img
                                    :src="endOfTurnUrl"
                                    alt=""
                                    class="absolute size-full object-contain drop-shadow-md"
                                />
                                <span class="relative z-10 text-xs font-bold">
                                    {{ entry.state.passOrder }}
                                </span>
                            </span>
                            <span class="min-w-0 flex-1 truncate" :title="entry.player.user.name">
                                {{ entry.player.user.name }}
                            </span>
                            <span
                                class="relative grid size-8 shrink-0 place-items-center"
                                :title="'Победные очки'"
                                :aria-label="`Победные очки: ${entry.state.victoryPoints}`"
                            >
                                <img :src="victoryPointsUrl" alt="" class="absolute object-contain drop-shadow-md" />
                                <span class="relative z-10 text-xs font-bold">
                                    {{ entry.state.victoryPoints }}
                                </span>
                            </span>
                        </h3>

                        <template v-if="entry.state">
                            <div class="grid grid-cols-4 gap-1">
                                <div
                                    v-for="counter in balanceCounters(entry.state)"
                                    :key="counter.label"
                                    class="relative grid rounded-lg"
                                    :title="counter.label"
                                    :aria-label="`${counter.label}: ${counter.value}`"
                                >
                                    <img
                                        :src="counter.image"
                                        alt=""
                                        class="object-contain drop-shadow-md"
                                        :class="
                                            counter.label === 'Доступные пристройки'
                                                ? 'h-full w-[60%]'
                                                : 'h-full w-[50%]'
                                        "
                                    />
                                    <span
                                        class="absolute top-1/2 right-2 grid min-w-6 -translate-y-1/2 place-items-center rounded-full px-1 text-sm font-bold shadow"
                                    >
                                        {{ counter.value }}
                                    </span>
                                </div>

                                <div
                                    v-for="counter in bookCounters(entry.state)"
                                    :key="counter.label"
                                    class="relative grid rounded-lg"
                                    :title="counter.label"
                                    :aria-label="`${counter.label}: ${counter.value}`"
                                >
                                    <img
                                        :src="counter.image"
                                        alt=""
                                        class="h-full w-[40%] object-contain drop-shadow-md"
                                    />
                                    <span
                                        class="absolute top-1/2 right-2 grid min-w-6 -translate-y-1/2 place-items-center rounded-full px-1 text-sm font-bold shadow"
                                        >{{ counter.value }}</span
                                    >
                                </div>

                                <div
                                    v-for="counter in incomeCounters(entry.state)"
                                    :key="counter.label"
                                    class="relative -my-2 grid aspect-square rounded-lg"
                                    :title="counter.label"
                                    :aria-label="`${counter.label}: ${counter.value}`"
                                >
                                    <img
                                        :src="handUrl"
                                        alt=""
                                        class="absolute bottom-4 w-[50%] object-contain drop-shadow-md"
                                    />
                                    <img
                                        :src="counter.image"
                                        alt=""
                                        class="absolute top-5 left-2 h-[25%] w-[25%] object-contain drop-shadow-md"
                                    />
                                    <span
                                        class="absolute top-1/2 right-2 grid min-w-6 -translate-y-1/2 place-items-center rounded-full px-1 text-sm font-bold shadow"
                                        >{{ counter.value }}</span
                                    >
                                </div>

                                <div
                                    v-for="counter in levelCounters(entry.state)"
                                    :key="counter.label"
                                    class="relative grid rounded-lg"
                                    :title="counter.label"
                                    :aria-label="`${counter.label}: ${counter.value}`"
                                >
                                    <img
                                        :src="counter.image"
                                        alt=""
                                        class="h-full w-[50%] object-contain drop-shadow-md"
                                    />
                                    <span
                                        class="absolute top-1/2 right-2 grid min-w-6 -translate-y-1/2 place-items-center rounded-full px-1 text-sm font-bold shadow"
                                        >{{ counter.value }}</span
                                    >
                                </div>
                            </div>
                        </template>
                    </article>
                    <GameHistory
                        :game-id="gameId"
                        :history="history"
                        :players="players"
                        :can-undo-last-action="canUndoLastAction"
                    />
                </div>
            </div>
        </div>
    </aside>
</template>
