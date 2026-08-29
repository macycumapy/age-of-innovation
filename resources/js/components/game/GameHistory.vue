<script setup lang="ts">
import { Form, useHttp } from '@inertiajs/vue3';
import { History, LoaderCircle, RotateCcw } from '@lucide/vue';
import { ref, watch } from 'vue';
import GameHistoryController from '@/actions/App/Http/Controllers/GameHistoryController';
import GameHistoryUndoController from '@/actions/App/Http/Controllers/GameHistoryUndoController';
import { Button } from '@/components/ui/button';
import { playerColorValues, terrainNames } from '@/lib/gameDisplay';
import type {
    GameActionType,
    GameHistoryEntry,
    GameHistoryPage,
    GamePlayerSummary,
    TerrainType,
} from '@/types';

const props = defineProps<{
    gameId: number;
    history: GameHistoryPage;
    players: GamePlayerSummary[];
    canUndoLastAction: boolean;
}>();

const actionDescriptions: Record<GameActionType, string> = {
    start_game: 'начал партию',
    choose_planning_bundle: 'выбрал стартовый комплект',
    choose_starting_resources: 'распределил стартовые ресурсы',
    place_starting_building: 'установил стартовый дом',
    undo_starting_building: 'отменил установку стартового дома',
    finish_starting_building_turn: 'завершил ход выставления дома',
    spend_starting_spade: 'использовал стартовую лопату',
    terraform_and_build: 'преобразовал местность и построил здание',
    upgrade_building: 'улучшил здание',
    advance_shipping: 'улучшил судоходство',
    advance_terraforming: 'улучшил преобразование',
    make_innovation: 'создал изобретение',
    send_scholar: 'отправил учёного',
    power_action: 'выполнил действие силы',
    book_action: 'выполнил действие за книги',
    special_action: 'выполнил особое действие',
    exchange_resources: 'обменял ресурсы',
    pass: 'спасовал',
    accept_power: 'принял силу',
    decline_power: 'отказался от силы',
    choose_town: 'выбрал жетон города',
    choose_palace: 'выбрал дворец',
    choose_competency: 'выбрал компетенцию',
};

const entries = ref<GameHistoryEntry[]>([...props.history.data]);
const hasMore = ref(props.history.hasMore);
const loadError = ref(false);
const historyRequest = useHttp({});

watch(
    () => props.history.data,
    (latestEntries) => {
        if (latestEntries.length === 0) {
            entries.value = [];

            return;
        }

        const oldestLatestSequence = latestEntries.at(-1)?.sequence ?? 0;
        const retainedOlderEntries = entries.value.filter(
            (entry) => entry.sequence < oldestLatestSequence,
        );
        const entriesById = new Map(
            [...latestEntries, ...retainedOlderEntries].map((entry) => [entry.id, entry]),
        );

        entries.value = [...entriesById.values()].sort((first, second) => second.sequence - first.sequence);
    },
);

function confirmUndo(event: SubmitEvent): void {
    if (!window.confirm('Откатить последнее действие и удалить его из истории?')) {
        event.preventDefault();
    }
}

async function loadMore(): Promise<void> {
    if (!hasMore.value || historyRequest.processing || entries.value.length === 0) {
        return;
    }

    loadError.value = false;
    const oldestSequence = entries.value.at(-1)?.sequence;

    if (oldestSequence === undefined) {
        return;
    }

    try {
        const page = (await historyRequest.submit(
            GameHistoryController(props.gameId, {
                query: { before_sequence: oldestSequence },
            }),
        )) as GameHistoryPage;
        const entryIds = new Set(entries.value.map((entry) => entry.id));

        entries.value.push(...page.data.filter((entry) => !entryIds.has(entry.id)));
        hasMore.value = page.hasMore;
    } catch {
        loadError.value = true;
    }
}

function handleScroll(event: Event): void {
    if (!(event.currentTarget instanceof HTMLElement)) {
        return;
    }

    const distanceToEnd = event.currentTarget.scrollHeight
        - event.currentTarget.scrollTop
        - event.currentTarget.clientHeight;

    if (distanceToEnd < 48) {
        void loadMore();
    }
}

function playerColor(entry: GameHistoryEntry): string {
    const color = props.players.find((player) => player.user.id === entry.player?.id)?.color;

    return color === null || color === undefined ? '#a1a1aa' : playerColorValues[color];
}

function payloadString(entry: GameHistoryEntry, key: string): string | null {
    const value = entry.payload[key];

    return typeof value === 'string' ? value : null;
}

function actionDetails(entry: GameHistoryEntry): string | null {
    const details: string[] = [];
    const hexId = payloadString(entry, 'hex_id');

    if (hexId !== null && [
        'place_starting_building',
        'undo_starting_building',
        'spend_starting_spade',
    ].includes(entry.type)) {
        details.push(`ячейка ${hexId}`);
    }

    const homeland = payloadString(entry, 'homeland') as TerrainType | null;

    if (entry.type === 'choose_planning_bundle' && homeland !== null && homeland in terrainNames) {
        details.push(terrainNames[homeland].toLocaleLowerCase('ru-RU'));
    }

    if (entry.payload.income_started === true) {
        const round = entry.payload.round;
        details.push(
            round === 1
                ? 'началась фаза дохода первого раунда'
                : `началась фаза дохода раунда ${String(round)}`,
        );
    }

    return details.length > 0 ? details.join(' · ') : null;
}

function actionTime(createdAt: string | null): string {
    if (createdAt === null) {
        return '';
    }

    return new Intl.DateTimeFormat('ru-RU', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(createdAt));
}
</script>

<template>
    <section
        class="grid h-80 grid-rows-[auto_minmax(0,1fr)] gap-3 rounded-lg border border-sidebar-border bg-sidebar-accent/30 p-3"
    >
        <div class="flex items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 font-semibold">
                <History class="size-4" aria-hidden="true" />
                История
            </h3>

            <Form
                v-if="canUndoLastAction"
                v-bind="GameHistoryUndoController.form(gameId)"
                #default="{ processing }"
                @submit="confirmUndo"
            >
                <Button
                    type="submit"
                    variant="ghost"
                    size="icon"
                    :disabled="processing"
                    title="Откатить последнее действие"
                    aria-label="Откатить последнее действие"
                >
                    <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                </Button>
            </Form>
        </div>

        <div class="overflow-y-auto overscroll-contain pr-1" @scroll.passive="handleScroll">
            <ol v-if="entries.length" class="grid gap-3" aria-label="История действий партии">
                <li
                    v-for="entry in entries"
                    :key="entry.id"
                    class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-2 text-sm"
                >
                    <span
                        class="mt-1 size-2.5 rounded-full ring-2 ring-sidebar"
                        :style="{ backgroundColor: playerColor(entry) }"
                        aria-hidden="true"
                    />
                    <p class="min-w-0 leading-snug">
                        <span class="font-bold">{{ entry.player?.name ?? 'Система' }}</span>
                        {{ actionDescriptions[entry.type] }}
                        <span v-if="actionDetails(entry)" class="text-muted-foreground">
                            — {{ actionDetails(entry) }}
                        </span>
                    </p>
                    <time
                        v-if="entry.createdAt"
                        :datetime="entry.createdAt"
                        class="text-xs text-muted-foreground"
                    >
                        {{ actionTime(entry.createdAt) }}
                    </time>
                </li>
            </ol>

            <div v-if="historyRequest.processing" class="grid place-items-center py-3" role="status">
                <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
                <span class="sr-only">Загрузка истории</span>
            </div>
            <button
                v-else-if="loadError"
                type="button"
                class="mt-3 w-full rounded-md py-2 text-sm text-muted-foreground hover:bg-sidebar-accent"
                @click="loadMore"
            >
                Повторить загрузку
            </button>
            <p v-else-if="!entries.length" class="text-sm text-muted-foreground">Действий пока нет.</p>
        </div>
    </section>
</template>
