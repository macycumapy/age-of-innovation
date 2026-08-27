<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { GamePlayerBoardState, GamePlayerSummary } from '@/types';
import annexUrl from '../../../images/buildings/white/annex.png';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import toolUrl from '../../../images/token_parts/cube.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import townKeyUrl from '../../../images/token_parts/key.png';
import coinUrl from '../../../images/token_parts/gold_medallion.png';
import handUrl from '../../../images/token_parts/hand.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import manaUrl from '../../../images/token_parts/mana.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';
import bowlOneUrl from '../../../images/token_parts/roman_one.png';
import bowlThreeUrl from '../../../images/token_parts/roman_three.png';
import bowlTwoUrl from '../../../images/token_parts/roman_two.png';
import scholarUrl from '../../../images/token_parts/scholar.png';
import shovelUrl from '../../../images/token_parts/shovel.png';
import victoryPointsUrl from '../../../images/token_parts/sunflower.png';
import shippingUrl from '../../../images/token_parts/ship.png';

type StatCounter = {
    label: string;
    image: string;
    value: number;
};

const props = defineProps<{
    players: GamePlayerSummary[];
    playerStates: GamePlayerBoardState[];
}>();

const isOpen = ref(false);

const playersWithStats = computed(() =>
    props.players.flatMap((player) => {
        const state = props.playerStates.find(
            (candidate) => candidate.playerId === player.id,
        );

        return state === undefined ? [] : [{ player, state }];
    }),
);

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
            label: 'Активные пристройки',
            image: annexUrl,
            value: state.activeAnnexes,
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
        { label: 'Доход монет', image: coinUrl, value: state.income.coins },
        {
            label: 'Доход учёных',
            image: scholarUrl,
            value: state.income.scholars,
        },
        { label: 'Доход маны', image: manaUrl, value: state.income.power },
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
    ];
}
</script>

<template>
    <aside
        class="fixed inset-y-0 right-0 z-50 w-[min(20rem,calc(100vw-3rem))] border-l bg-background shadow-2xl transition-transform duration-300"
        :class="isOpen ? 'translate-x-0' : 'translate-x-full'"
        aria-label="Баланс и статистика игроков"
    >
        <button
            type="button"
            class="absolute top-1/2 left-0 grid size-11 -translate-x-full -translate-y-1/2 place-items-center rounded-l-xl border border-r-0 bg-background shadow-lg"
            :aria-label="isOpen ? 'Скрыть статистику' : 'Показать статистику'"
            :aria-expanded="isOpen"
            @click="isOpen = !isOpen"
        >
            <ChevronRight v-if="isOpen" class="size-5" />
            <ChevronLeft v-else class="size-5" />
        </button>

        <div class="grid h-full grid-rows-[auto_1fr]">
            <div class="grid content-start gap-4 overflow-y-auto p-4">
                <article
                    v-for="entry in playersWithStats"
                    :key="entry.player.id"
                    class="grid gap-3 rounded-xl border bg-muted/50 p-3 shadow-sm"
                >
                    <h3 class="flex items-center gap-2 font-semibold">
                        <span>{{ entry.player.user.name }}</span>
                        <span
                            class="relative grid size-8 place-items-center"
                            :title="'Победные очки'"
                            :aria-label="`Победные очки: ${entry.state.victoryPoints}`"
                        >
                            <img
                                :src="victoryPointsUrl"
                                alt=""
                                class="absolute object-contain drop-shadow-md"
                            />
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
                                        counter.label === 'Активные пристройки'
                                            ? 'h-full w-[60%]'
                                            : 'h-full w-[50%]'
                                    "
                                />
                                <span
                                    class="absolute top-1/2 right-3 grid min-w-6 -translate-y-1/2 place-items-center rounded-full px-1 text-sm font-bold shadow"
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
                                    class="absolute top-1/2 right-3 grid min-w-6 -translate-y-1/2 place-items-center rounded-full px-1 text-sm font-bold shadow"
                                    >{{ counter.value }}</span
                                >
                            </div>

                            <div
                                v-for="counter in incomeCounters(entry.state)"
                                :key="counter.label"
                                class="relative grid aspect-square rounded-lg"
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
                                    class="absolute top-1/2 right-3 grid min-w-6 -translate-y-1/2 place-items-center rounded-full px-1 text-sm font-bold shadow"
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
                                    class="absolute top-1/2 right-3 grid min-w-6 -translate-y-1/2 place-items-center rounded-full px-1 text-sm font-bold shadow"
                                    >{{ counter.value }}</span
                                >
                            </div>
                        </div>
                    </template>
                </article>
            </div>
        </div>
    </aside>
</template>
