<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
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

const collapsedPlayersWithStats = computed(() =>
    [...playersWithStats.value].sort((firstEntry, secondEntry) => {
        if (firstEntry.state.passOrder === null) {
            if (secondEntry.state.passOrder === null) {
                return firstEntry.turnOrder - secondEntry.turnOrder;
            }

            return -1;
        }

        if (secondEntry.state.passOrder === null) {
            return 1;
        }

        return firstEntry.state.passOrder - secondEntry.state.passOrder;
    }),
);

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
            value: Math.max(0, state.shippingLevel),
        },
        {
            label: 'Уровень лопаты',
            image: shovelUrl,
            value: Math.max(0, state.terraformingLevel),
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
    <Teleport to="#right-sidebar-portal" defer>
        <aside
            class="sticky top-0 z-40 order-last h-svh shrink-0 overflow-hidden transition-[width] duration-300 ease-in-out motion-reduce:transition-none"
            :class="isOpen ? 'w-[min(20rem,calc(100vw-3rem))]' : 'w-12'"
            aria-label="Баланс и статистика игроков"
        >
            <button
                type="button"
                class="absolute inset-y-0 right-0 z-20 flex w-12 cursor-pointer flex-col items-center gap-1 overflow-y-auto rounded-r-lg bg-sidebar p-1 text-sidebar-foreground shadow-sm transition-[opacity,background-color] duration-150 ease-out hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none motion-reduce:transition-none"
                :class="isOpen ? 'pointer-events-none opacity-0' : 'opacity-100'"
                aria-label="Показать статистику игроков"
                :aria-expanded="isOpen"
                :aria-hidden="isOpen"
                :tabindex="isOpen ? -1 : 0"
                @click="isOpen = true"
            >
                <TransitionGroup name="player-order" tag="div" class="contents">
                    <div
                        v-for="entry in collapsedPlayersWithStats"
                        :key="entry.player.id"
                        class="grid w-full justify-items-center gap-2 rounded-md border border-sidebar-border py-2"
                        :style="{ backgroundColor: playerBackgroundColor(entry.player) }"
                        :title="`${entry.player.user.name}: ${entry.state.passOrder === null ? `порядок хода ${entry.turnOrder}` : `порядок паса ${entry.state.passOrder}`}, ${entry.state.victoryPoints} победных очков`"
                    >
                        <span
                            v-if="entry.state.passOrder === null"
                            class="relative grid size-6 place-items-center rounded-full border border-sidebar-border bg-sidebar/70 text-xs font-bold shadow-sm"
                            :class="{ 'ring-2 ring-emerald-400/80': entry.player.id === activePlayerId }"
                            :aria-label="`Порядок хода: ${entry.turnOrder}`"
                        >
                            <span
                                v-if="entry.player.id === activePlayerId"
                                class="absolute inset-0 rounded-full border-2 border-emerald-400/70 motion-safe:animate-ping"
                                aria-hidden="true"
                            />
                            <span class="relative translate-y-px leading-none">{{ entry.turnOrder }}</span>
                        </span>
                        <span
                            v-else
                            class="relative grid size-6 place-items-center"
                            :aria-label="`Порядок паса: ${entry.state.passOrder}`"
                        >
                            <img :src="endOfTurnUrl" alt="" class="absolute size-full object-contain drop-shadow-md" />
                            <span class="relative z-10 translate-y-px text-xs leading-none font-bold">
                                {{ entry.state.passOrder }}
                            </span>
                        </span>

                        <span
                            class="relative grid size-7 place-items-center"
                            :aria-label="`Победные очки: ${entry.state.victoryPoints}`"
                        >
                            <img
                                :src="victoryPointsUrl"
                                alt=""
                                class="absolute size-full object-contain drop-shadow-md"
                            />
                            <span class="relative z-10 text-xs font-bold text-white">
                                {{ entry.state.victoryPoints }}
                            </span>
                        </span>
                    </div>
                </TransitionGroup>
            </button>

            <div
                class="absolute inset-y-0 right-0 w-[min(20rem,calc(100vw-3rem))] text-sidebar-foreground"
                :class="{ 'pointer-events-none': !isOpen }"
                :aria-hidden="!isOpen"
                :inert="!isOpen"
            >
                <button
                    type="button"
                    class="absolute inset-y-0 left-0 z-10 flex w-3 items-center justify-center border-sidebar-border bg-sidebar/80 text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none focus-visible:ring-inset"
                    aria-label="Скрыть статистику"
                    :aria-expanded="isOpen"
                    :tabindex="isOpen ? 0 : -1"
                    @click="isOpen = false"
                >
                    <ChevronRight class="size-3" />
                </button>

                <div class="grid h-full grid-rows-[auto_1fr] overflow-hidden rounded-lg bg-sidebar/70 shadow-sm">
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
                                    <span class="relative translate-y-px leading-none">{{ entry.turnOrder }}</span>
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
                                    <span class="relative z-10 translate-y-px text-xs leading-none font-bold">
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
                                    <img
                                        :src="victoryPointsUrl"
                                        alt=""
                                        class="absolute object-contain drop-shadow-md"
                                    />
                                    <span class="relative z-10 text-xs font-bold text-white">
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
    </Teleport>
</template>

<style scoped>
.player-order-move {
    transition: transform 300ms ease-in-out;
}

@media (prefers-reduced-motion: reduce) {
    .player-order-move {
        transition: none;
    }
}
</style>
