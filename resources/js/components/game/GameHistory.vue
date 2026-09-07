<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { History, LoaderCircle, RotateCcw } from '@lucide/vue';
import { ref, watch } from 'vue';
import GameHistoryController from '@/actions/App/Http/Controllers/GameHistoryController';
import GameHistoryUndoController from '@/actions/App/Http/Controllers/GameHistoryUndoController';
import Form from '@/components/game/GameActionForm.vue';
import { Button } from '@/components/ui/button';
import { playerColorValues, terrainNames } from '@/lib/gameDisplay';
import type { GameActionType, GameHistoryEntry, GameHistoryPage, GamePlayerSummary, TerrainType } from '@/types';

const props = defineProps<{
    gameId: number;
    history: GameHistoryPage;
    players: GamePlayerSummary[];
    canUndoLastAction: boolean;
}>();

const actionDescriptions: Record<GameActionType, string> = {
    phase_checkpoint: 'начал новую фазу',
    start_game: 'начал партию',
    choose_planning_bundle: 'выбрал стартовый комплект',
    choose_starting_resources: 'распределил стартовые ресурсы',
    choose_income_resources: 'завершил фазу дохода',
    place_starting_building: 'установил стартовый дом',
    undo_starting_building: 'отменил установку стартового дома',
    finish_starting_building_turn: 'завершил ход выставления дома',
    spend_starting_spade: 'использовал лопату',
    terraform_and_build: 'преобразовал местность и построил здание',
    build_workshop: 'построил дом',
    finish_turn: 'завершил ход',
    upgrade_building: 'улучшил здание',
    advance_shipping: 'улучшил судоходство',
    advance_terraforming: 'улучшил преобразование',
    make_innovation: 'создал изобретение',
    send_scholar: 'отправил учёного',
    power_action: 'выполнил действие силы',
    sacrifice_power: 'пожертвовал Силу',
    book_action: 'выполнил действие за книги',
    special_action: 'выполнил особое действие',
    exchange_resources: 'обменял ресурсы',
    pass: 'спасовал',
    choose_science_bonus_books: 'выбрал книги научного бонуса',
    accept_power: 'принял силу',
    decline_power: 'отказался от силы',
    choose_town: 'выбрал жетон города',
    choose_town_books: 'распределил книги города',
    accept_palace_water_town: 'решил основать город через воду',
    decline_palace_water_town: 'отказался основывать город через воду',
    choose_palace: 'выбрал жетон Дворца',
    place_palace_guild: 'разместил бесплатный рынок',
    place_annex: 'поставил пристройку',
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
        const retainedOlderEntries = entries.value.filter((entry) => entry.sequence < oldestLatestSequence);
        const entriesById = new Map([...latestEntries, ...retainedOlderEntries].map((entry) => [entry.id, entry]));

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

    const distanceToEnd =
        event.currentTarget.scrollHeight - event.currentTarget.scrollTop - event.currentTarget.clientHeight;

    if (distanceToEnd < 48) {
        void loadMore();
    }
}

function playerColor(entry: GameHistoryEntry): string {
    if (isSharedIncomeEntry(entry)) {
        return '#a1a1aa';
    }

    const color = props.players.find((player) => player.user.id === entry.player?.id)?.color;

    return color === null || color === undefined ? '#a1a1aa' : playerColorValues[color];
}

function isSharedIncomeEntry(entry: GameHistoryEntry): boolean {
    return entry.type === 'choose_income_resources';
}

function payloadString(entry: GameHistoryEntry, key: string): string | null {
    const value = entry.payload[key];

    return typeof value === 'string' ? value : null;
}

function incomeDetails(entry: GameHistoryEntry): string[] {
    const receipts = entry.payload.income_receipts;

    if (!Array.isArray(receipts)) {
        return [];
    }

    return receipts.flatMap((receipt) => {
        if (typeof receipt !== 'object' || receipt === null || !('player_id' in receipt)) {
            return [];
        }

        const incomeReceipt = receipt as Record<string, unknown>;
        const playerId = Number(incomeReceipt.player_id);
        const playerName = props.players.find((player) => player.id === playerId)?.user.name ?? `Игрок ${playerId}`;
        const resources = [
            ['tools', 'инстр.'],
            ['coins', 'золота'],
            ['power', 'Силы'],
            ['scholars', 'учёных'],
            ['books', 'книг'],
            ['knowledge_steps', 'шагов знаний'],
            ['victory_points', 'ПО'],
        ]
            .map(([key, label]) => {
                const amount = Number(incomeReceipt[key] ?? 0);

                return Number.isFinite(amount) && amount > 0 ? `${amount} ${label}` : null;
            })
            .filter((resource): resource is string => resource !== null);

        return resources.length > 0 ? [`${playerName}: ${resources.join(', ')}`] : [];
    });
}

function developmentTrackRewardDetails(entry: GameHistoryEntry): string | null {
    if (
        !['advance_shipping', 'advance_terraforming'].includes(entry.type) ||
        typeof entry.payload.reward !== 'object' ||
        entry.payload.reward === null
    ) {
        return null;
    }

    const reward = entry.payload.reward as Record<string, unknown>;
    const victoryPoints = Number(reward.victoryPoints ?? 0);
    const books = Number(reward.books ?? 0);
    const rewards: string[] = [];

    if (Number.isFinite(victoryPoints) && victoryPoints > 0) {
        rewards.push(`${victoryPoints} ПО`);
    }

    if (Number.isFinite(books) && books > 0) {
        const disciplineNames: Record<string, string> = {
            banking: 'банковское дело',
            law: 'право',
            engineering: 'инженерия',
            medicine: 'медицина',
        };
        const selectedBooks =
            typeof entry.payload.reward_book_counts === 'object' && entry.payload.reward_book_counts !== null
                ? Object.entries(entry.payload.reward_book_counts)
                      .filter(([, count]) => Number(count) > 0)
                      .map(([discipline, count]) => `${disciplineNames[discipline] ?? discipline} ×${Number(count)}`)
                : [];

        rewards.push(
            selectedBooks.length > 0 ? `${books} книги (${selectedBooks.join(', ')})` : `${books} книги на выбор`,
        );
    }

    return rewards.length > 0 ? `получено ${rewards.join(', ')}` : null;
}

function finalScoringDetails(entry: GameHistoryEntry): string[] {
    if (entry.type !== 'phase_checkpoint') {
        return [];
    }

    const scoring = entry.payload.final_scoring;

    if (!Array.isArray(scoring)) {
        return [];
    }

    const disciplineNames: Record<string, string> = {
        banking: 'Банковское дело',
        law: 'Право',
        engineering: 'Инженерия',
        medicine: 'Медицина',
    };

    return scoring.flatMap((playerScoring) => {
        if (typeof playerScoring !== 'object' || playerScoring === null) {
            return [];
        }

        const result = playerScoring as Record<string, unknown>;
        const playerId = Number(result.playerId);
        const victoryPoints = Number(result.victoryPoints);

        if (!Number.isFinite(playerId) || !Number.isFinite(victoryPoints) || victoryPoints <= 0) {
            return [];
        }

        const playerName = props.players.find((player) => player.id === playerId)?.user.name ?? `Игрок ${playerId}`;
        const sources = Array.isArray(result.sources)
            ? result.sources.flatMap((source) => {
                  if (typeof source !== 'object' || source === null) {
                      return [];
                  }

                  const scoringSource = source as Record<string, unknown>;
                  const value = Number(scoringSource.value);
                  const rank = Number(scoringSource.rank);
                  const points = Number(scoringSource.points);

                  if (![value, rank, points].every(Number.isFinite) || points <= 0) {
                      return [];
                  }

                  if (scoringSource.source === 'network') {
                      return [`Сеть: ${value} зданий, ${rank}-е место — +${points} ПО`];
                  }

                  if (scoringSource.source === 'knowledge' && typeof scoringSource.id === 'string') {
                      return [
                          `${disciplineNames[scoringSource.id] ?? scoringSource.id}: уровень ${value}, ${rank}-е место — +${points} ПО`,
                      ];
                  }

                  return [];
              })
            : [];

        return [`${playerName}: ${sources.join('; ')}. Итого +${victoryPoints} ПО`];
    });
}

function actionDescription(entry: GameHistoryEntry): string {
    if (entry.type === 'terraform_and_build' && entry.payload.built === false) {
        return 'отказался от строительства после преобразования';
    }

    return actionDescriptions[entry.type];
}

function checkpointDescription(entry: GameHistoryEntry): string {
    const phase = payloadString(entry, 'phase');
    const phaseNames: Record<string, string> = {
        setup: 'Фаза подготовки',
        income: 'Фаза дохода',
        actions: 'Фаза действия',
        science_bonus: 'Научный бонус',
        finished: 'Завершение игры',
    };

    return `${phaseNames[phase ?? ''] ?? phase ?? 'неизвестно'}`;
}

function actionDetails(entry: GameHistoryEntry): string | null {
    const details: string[] = [];
    const hexId = payloadString(entry, 'hex_id');

    if (
        hexId !== null &&
        [
            'place_starting_building',
            'undo_starting_building',
            'spend_starting_spade',
            'terraform_and_build',
            'build_workshop',
        ].includes(entry.type)
    ) {
        details.push(`ячейка ${hexId}`);
    }

    const homeland = payloadString(entry, 'homeland') as TerrainType | null;

    if (entry.type === 'choose_planning_bundle' && homeland !== null && homeland in terrainNames) {
        details.push(terrainNames[homeland].toLocaleLowerCase('ru-RU'));
    }

    details.push(...incomeDetails(entry));

    if (
        entry.type === 'pass' &&
        typeof entry.payload.final_resource_conversion === 'object' &&
        entry.payload.final_resource_conversion !== null
    ) {
        const conversion = entry.payload.final_resource_conversion as Record<string, unknown>;
        const bowlTwoSpent = Number(conversion.bowlTwoSpent ?? 0);
        const movedToBowlThree = Number(conversion.movedToBowlThree ?? 0);
        const convertedToCoins = Number(conversion.convertedToCoins ?? 0);
        const totalCoins = Number(conversion.totalCoins ?? 0);
        const victoryPoints = Number(conversion.victoryPoints ?? 0);
        const remainingCoins = Number(conversion.remainingCoins ?? 0);

        if (bowlTwoSpent > 0) {
            details.push(`из чаши II сброшено ${bowlTwoSpent}, в чашу III переведено ${movedToBowlThree} Силы`);
        }

        details.push(
            `ресурсы дали ${convertedToCoins} золота · всего ${totalCoins} золота → ${victoryPoints} ПО · осталось ${remainingCoins} золота`,
        );
    }

    if (entry.type === 'sacrifice_power' && typeof entry.payload.amount === 'number') {
        details.push(`сброшено ${entry.payload.amount} · переведено в чашу III ${entry.payload.amount}`);
    }

    if (entry.type === 'accept_power' && typeof entry.payload.received_power === 'number') {
        details.push(
            `получено ${entry.payload.received_power} Силы · потеряно ${String(entry.payload.victory_points_spent ?? 0)} ПО`,
        );
    }

    if (entry.type === 'decline_power' && typeof entry.payload.offered_power === 'number') {
        details.push(`предложено ${entry.payload.offered_power} Силы`);
    }

    if (entry.type === 'spend_starting_spade' && Number(entry.payload.tunnel_tools ?? 0) > 0) {
        details.push(
            `Туннель: потрачен 1 инструмент · получено ${String(entry.payload.tunnel_victory_points ?? 0)} ПО`,
        );
    }

    const developmentTrackReward = developmentTrackRewardDetails(entry);

    if (developmentTrackReward !== null) {
        details.push(developmentTrackReward);
    }

    if (typeof entry.payload.victory_points === 'number' && entry.payload.victory_points > 0) {
        details.push(`получено ${entry.payload.victory_points} ПО`);
    }

    if (typeof entry.payload.bonus_coins === 'number' && entry.payload.bonus_coins > 0) {
        details.push(`получено ${entry.payload.bonus_coins} золота`);
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
                        <template v-if="entry.type === 'phase_checkpoint'">
                            <span class="font-bold">{{ checkpointDescription(entry) }}</span>
                        </template>
                        <template v-else-if="isSharedIncomeEntry(entry)">
                            <span class="font-bold">Фаза дохода завершена</span>
                        </template>
                        <template v-else>
                            <span class="font-bold">{{ entry.player?.name ?? 'Система' }}</span>
                            {{ actionDescription(entry) }}
                        </template>
                        <span v-if="actionDetails(entry)" class="text-muted-foreground">
                            — {{ actionDetails(entry) }}
                        </span>
                        <span
                            v-for="detail in finalScoringDetails(entry)"
                            :key="detail"
                            class="mt-1 block rounded-md bg-amber-500/15 px-2 py-1 text-xs font-medium text-amber-900 dark:text-amber-200"
                        >
                            {{ detail }}
                        </span>
                    </p>
                    <time
                        v-if="entry.createdAt"
                        :datetime="entry.createdAt"
                        data-allow-mismatch="text"
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
