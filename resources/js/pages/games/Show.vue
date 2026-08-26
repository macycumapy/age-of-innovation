<script setup lang="ts">
import { Form, Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import GamePlayerController from '@/actions/App/Http/Controllers/GamePlayerController';
import GamePlayerReadinessController from '@/actions/App/Http/Controllers/GamePlayerReadinessController';
import GameStartController from '@/actions/App/Http/Controllers/GameStartController';
import PlanningBundleController from '@/actions/App/Http/Controllers/PlanningBundleController';
import StartingResourcesController from '@/actions/App/Http/Controllers/StartingResourcesController';
import BoardMap from '@/components/game/BoardMap.vue';
import CultBoard from '@/components/game/CultBoard.vue';
import InnovationBoard from '@/components/game/InnovationBoard.vue';
import PalaceBoard from '@/components/game/PalaceBoard.vue';
import PlayerBoards from '@/components/game/PlayerBoards.vue';
import PlayerStatsPanel from '@/components/game/PlayerStatsPanel.vue';
import RoundBonusBoard from '@/components/game/RoundBonusBoard.vue';
import TownTileBoard from '@/components/game/TownTileBoard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { index } from '@/routes/games';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import bankingRoundUrl from '../../../images/token_parts/coin_round.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import engineeringRoundUrl from '../../../images/token_parts/engineering_round.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import lawRoundUrl from '../../../images/token_parts/law_round.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';
import medicineRoundUrl from '../../../images/token_parts/medicine_round.png';
import type {
    Competency,
    Faction,
    GamePlayerSummary,
    GameResource,
    KnowledgeDiscipline,
    MapVariant,
    RoundBonus,
    TerrainType,
} from '@/types';

const props = defineProps<{
    game: GameResource;
}>();

const page = usePage();

usePoll(3000, { only: ['game'] });

const currentPlayer = computed(() =>
    props.game.data.players.find((player) => player.user.id === page.props.auth.user.id),
);

const activePlayer = computed(() =>
    props.game.data.players.find((player) => player.user.id === props.game.data.activePlayerId),
);

const pendingInteractionPlayer = computed(() =>
    props.game.data.players.find((player) => player.id === props.game.data.pendingInteraction?.playerId),
);

const orderedPlayers = computed(() =>
    props.game.data.turnOrder
        .map((playerId) => props.game.data.players.find((player) => player.id === playerId))
        .filter((player): player is GamePlayerSummary => player !== undefined),
);

const canChoosePlanningBundle = computed(
    () =>
        props.game.data.status === 'active' &&
        props.game.data.activePlayerId === page.props.auth.user.id &&
        currentPlayer.value?.faction === null,
);

const canChooseStartingResources = computed(
    () =>
        props.game.data.pendingInteraction?.type === 'choose_starting_resources' &&
        props.game.data.pendingInteraction.playerId === currentPlayer.value?.id &&
        props.game.data.activePlayerId === page.props.auth.user.id,
);

const allPlanningBundlesChosen = computed(() => props.game.data.players.every((player) => player.faction !== null));

const setupChoicesCompleted = computed(
    () => allPlanningBundlesChosen.value && props.game.data.pendingInteraction === null,
);

const startingBookCounts = reactive<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const startingKnowledgeCounts = reactive<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const selectedStartingCompetency = ref<Competency | null>(null);

const availableStartingBookCount = computed(
    () => props.game.data.pendingInteraction?.context.bookCount ?? 0,
);

const assignedStartingBookCount = computed(() =>
    Object.values(startingBookCounts).reduce((total, count) => total + count, 0),
);

const remainingStartingBookCount = computed(() =>
    Math.max(0, availableStartingBookCount.value - assignedStartingBookCount.value),
);

const availableStartingKnowledgeStepCount = computed(
    () => props.game.data.pendingInteraction?.context.knowledgeStepCount ?? 0,
);

const assignedStartingKnowledgeStepCount = computed(() =>
    Object.values(startingKnowledgeCounts).reduce((total, count) => total + count, 0),
);

const remainingStartingKnowledgeStepCount = computed(() =>
    Math.max(0, availableStartingKnowledgeStepCount.value - assignedStartingKnowledgeStepCount.value),
);

const requiresStartingCompetency = computed(
    () => (props.game.data.pendingInteraction?.context.competencyIds?.length ?? 0) > 0,
);

watch(
    () => props.game.data.pendingInteraction?.playerId,
    () => {
        for (const discipline of Object.keys(startingBookCounts) as KnowledgeDiscipline[]) {
            startingBookCounts[discipline] = 0;
            startingKnowledgeCounts[discipline] = 0;
        }

        selectedStartingCompetency.value = null;
    },
);

defineOptions({
    layout: {
        fullWidth: true,
        breadcrumbs: [
            {
                title: 'Игры',
                href: index(),
            },
        ],
    },
});

const mapVariantNames: Record<MapVariant, string> = {
    one_to_three_players: '1–3 игрока',
    three_to_five_players: '3–5 игроков',
};

const terrainNames: Record<TerrainType, string> = {
    desert: 'Пустыня',
    plains: 'Равнина',
    swamp: 'Болото',
    lake: 'Озеро',
    forest: 'Лес',
    mountain: 'Горы',
    wasteland: 'Пустошь',
};

const terrainBundleClasses: Record<TerrainType, string> = {
    desert: 'border-yellow-500/60 bg-yellow-400/25 dark:bg-yellow-400/20',
    plains: 'border-amber-800/60 bg-amber-800/20 dark:bg-amber-600/20',
    swamp: 'border-zinc-700/60 bg-zinc-900/20 dark:bg-zinc-400/15',
    lake: 'border-blue-500/60 bg-blue-500/20 dark:bg-blue-500/20',
    forest: 'border-green-600/60 bg-green-600/20 dark:bg-green-500/20',
    mountain: 'border-gray-500/60 bg-gray-500/20 dark:bg-gray-400/15',
    wasteland: 'border-red-500/60 bg-red-500/20 dark:bg-red-500/20',
};

const factionNames: Record<Faction, string> = {
    blessed: 'Благословенные',
    felines: 'Кошачьи',
    goblins: 'Гоблины',
    illusionists: 'Иллюзионисты',
    inventors: 'Изобретатели',
    lizards: 'Ящеры',
    moles: 'Кроты',
    monks: 'Монахи',
    navigators: 'Навигаторы',
    omar: 'Омар',
    philosophers: 'Философы',
    psychics: 'Провидцы',
};

const roundBonusNames: Record<RoundBonus, string> = {
    river_workshop: 'Речная мастерская',
    send_scholar: 'Отправка учёного',
    build_guild: 'Строительство гильдии',
    pass_palace_university: 'Дворцы и университеты',
    spade: 'Бесплатная лопата',
    bridge: 'Бесплатный мост',
    knowledge: 'Шаг знания',
    pass_school: 'Школы при пасе',
    power_coins: 'Сила и монеты',
    coins: 'Монеты',
};

const terrainTileImages = import.meta.glob('../../../images/terrain_tiles/*.webp', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const factionImages = import.meta.glob('../../../images/factions/*.jpg', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const roundBonusImages = import.meta.glob('../../../images/round_bonus_cards/*_top.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const competencyImages = import.meta.glob('../../../images/competencies/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const knowledgeDisciplineNames: Record<KnowledgeDiscipline, string> = {
    banking: 'Банковское дело',
    law: 'Право',
    engineering: 'Инженерное дело',
    medicine: 'Медицина',
};

const bookImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};

const knowledgeRoundImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingRoundUrl,
    law: lawRoundUrl,
    engineering: engineeringRoundUrl,
    medicine: medicineRoundUrl,
};

function planningBundleButtonLabel(processing: boolean): string {
    if (processing) {
        return 'Выбор…';
    }

    if (allPlanningBundlesChosen.value) {
        return 'Выбор завершён';
    }

    return canChoosePlanningBundle.value ? 'Выбрать комплект' : 'Сейчас выбирает другой игрок';
}

function terrainTileImage(terrain: TerrainType): string {
    const fileName = terrain === 'wasteland' ? 'westland' : terrain;

    return terrainTileImages[`../../../images/terrain_tiles/${fileName}.webp`];
}

function factionImage(faction: Faction): string {
    return factionImages[`../../../images/factions/${faction}.jpg`];
}

function roundBonusImage(roundBonus: RoundBonus): string {
    return roundBonusImages[`../../../images/round_bonus_cards/${roundBonus}_top.png`];
}

function competencyImage(competency: Competency): string {
    return competencyImages[`../../../images/competencies/${competency}.png`];
}

function selectedPlayerForHomeland(homeland: TerrainType): GamePlayerSummary | undefined {
    const selection = props.game.data.planningSelections.find((selection) => selection.bundle.homeland === homeland);

    return props.game.data.players.find((player) => player.id === selection?.playerId);
}

function selectedCompetencyForHomeland(homeland: TerrainType): Competency | undefined {
    const player = selectedPlayerForHomeland(homeland);
    const playerState = props.game.data.playerBoardStates.find((state) => state.playerId === player?.id);

    return playerState?.competencyIds[0];
}

function updateStartingBookCount(discipline: KnowledgeDiscipline, event: Event): void {
    if (! (event.target instanceof HTMLInputElement)) {
        return;
    }

    const requestedCount = Number.parseInt(event.target.value, 10);
    const otherBookCount = assignedStartingBookCount.value - startingBookCounts[discipline];
    const maximumCount = Math.max(0, availableStartingBookCount.value - otherBookCount);
    const normalizedCount = Math.max(0, Math.min(Number.isNaN(requestedCount) ? 0 : requestedCount, maximumCount));

    startingBookCounts[discipline] = normalizedCount;
    event.target.value = String(normalizedCount);
}

function updateStartingKnowledgeCount(discipline: KnowledgeDiscipline, event: Event): void {
    if (! (event.target instanceof HTMLInputElement)) {
        return;
    }

    const requestedCount = Number.parseInt(event.target.value, 10);
    const otherStepCount = assignedStartingKnowledgeStepCount.value - startingKnowledgeCounts[discipline];
    const maximumCount = Math.max(0, availableStartingKnowledgeStepCount.value - otherStepCount);
    const normalizedCount = Math.max(0, Math.min(Number.isNaN(requestedCount) ? 0 : requestedCount, maximumCount));

    startingKnowledgeCounts[discipline] = normalizedCount;
    event.target.value = String(normalizedCount);
}
</script>

<template>
    <Head :title="`Подготовка игры №${game.data.id}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Card v-if="game.data.status === 'lobby'">
            <CardHeader>
                <CardTitle>Участники</CardTitle>
                <CardDescription> Игроки занимают места в порядке присоединения. </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-3">
                <div
                    v-for="player in game.data.players"
                    :key="player.id"
                    class="flex items-center justify-between gap-4 rounded-lg border p-3"
                >
                    <div>
                        <p class="font-medium">{{ player.user.name }}</p>
                        <p class="text-sm text-muted-foreground">Место {{ player.seat }}</p>
                    </div>

                    <span class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium">
                        {{ player.isReady ? 'Готов' : 'Не готов' }}
                    </span>
                </div>

                <div
                    v-for="seat in game.data.maxPlayers - game.data.playersCount"
                    :key="`empty-${seat}`"
                    class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
                >
                    Свободное место
                </div>

                <div
                    v-if="currentPlayer || game.data.playersCount < game.data.maxPlayers || game.data.isOwner"
                    class="flex flex-wrap items-start justify-end gap-3 border-t pt-3"
                >
                    <Form
                        v-if="currentPlayer"
                        v-bind="
                            GamePlayerReadinessController.update.form({
                                game: game.data.id,
                                gamePlayer: currentPlayer.id,
                            })
                        "
                        #default="{ errors, processing }"
                        class="grid gap-2"
                    >
                        <input type="hidden" name="is_ready" :value="currentPlayer.isReady ? '0' : '1'" />
                        <InputError :message="errors.is_ready" />
                        <Button
                            type="submit"
                            :variant="currentPlayer.isReady ? 'outline' : 'default'"
                            :disabled="processing"
                        >
                            {{
                                processing ? 'Сохранение…' : currentPlayer.isReady ? 'Отменить готовность' : 'Я готов'
                            }}
                        </Button>
                    </Form>

                    <Form
                        v-if="!currentPlayer && game.data.playersCount < game.data.maxPlayers"
                        v-bind="GamePlayerController.store.form(game.data.id)"
                        #default="{ errors, processing }"
                        class="grid gap-2"
                    >
                        <InputError :message="errors.game" />
                        <Button type="submit" :disabled="processing">
                            {{ processing ? 'Присоединение…' : 'Присоединиться' }}
                        </Button>
                    </Form>

                    <Form
                        v-if="game.data.isOwner"
                        v-bind="GameStartController.form(game.data.id)"
                        #default="{ errors, processing }"
                        class="grid gap-2"
                    >
                        <InputError :message="errors.game" />
                        <Button type="submit" :disabled="processing || !game.data.canStart">
                            {{ processing ? 'Запуск…' : 'Начать игру' }}
                        </Button>
                    </Form>
                </div>
            </CardContent>
        </Card>

        <Card v-if="game.data.status === 'active' && !setupChoicesCompleted">
            <CardHeader>
                <CardTitle>Выбор стартового комплекта</CardTitle>
                <ol class="flex flex-wrap items-center gap-2 text-sm font-medium">
                    <template v-for="(player, index) in orderedPlayers" :key="player.id">
                        <li :class="player.user.id === game.data.activePlayerId ? 'text-primary' : ''">
                            {{ player.user.name }}
                        </li>
                        <li v-if="index < orderedPlayers.length - 1" aria-hidden="true" class="text-muted-foreground">
                            →
                        </li>
                    </template>
                </ol>
                <CardDescription v-if="game.data.pendingInteraction?.type === 'choose_starting_resources'">
                    Сейчас стартовые ресурсы распределяет
                    {{ pendingInteractionPlayer?.user.name ?? 'игрок' }}.
                </CardDescription>
                <CardDescription v-else-if="canChoosePlanningBundle">
                    Выберите родную местность, сообщество и бонус раунда.
                </CardDescription>
                <CardDescription v-else-if="allPlanningBundlesChosen">
                    Все игроки выбрали стартовые комплекты.
                </CardDescription>
                <CardDescription v-else-if="currentPlayer?.faction">
                    Ваш комплект выбран. Ожидаем остальных игроков.
                </CardDescription>
                <CardDescription v-else>
                    Сейчас выбирает
                    {{ activePlayer?.user.name ?? 'другой игрок' }}.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <Form
                    v-if="canChooseStartingResources"
                    v-bind="StartingResourcesController.store.form(game.data.id)"
                    id="starting-resources-form"
                    #default="{ errors, processing }"
                    class="grid gap-5 rounded-xl border border-primary/40 bg-primary/5 p-5 w-xl mb-6"
                >
                    <div class="grid gap-1">
                        <h3 class="font-semibold">Распределите стартовые ресурсы</h3>
                        <p class="text-sm text-muted-foreground">
                            Этот выбор завершает получение вашего стартового комплекта.
                        </p>
                    </div>

                    <div
                        v-if="(game.data.pendingInteraction?.context.bookCount ?? 0) > 0"
                        class="grid gap-3"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <p class="font-medium">Распределение стартовых книг</p>
                            <p class="rounded-md bg-background/75 px-3 py-1.5 font-medium">
                                Доступно: {{ availableStartingBookCount }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <label
                                v-for="discipline in game.data.pendingInteraction?.optionIds"
                                :key="discipline"
                                class="grid h-full grid-rows-[auto_minmax(2.5rem,1fr)_auto_auto] justify-items-center gap-2 rounded-lg border bg-background/70 p-3 text-center text-sm font-medium"
                            >
                                <img
                                    :src="bookImages[discipline]"
                                    :alt="`Книга: ${knowledgeDisciplineNames[discipline]}`"
                                    class="h-16 w-auto object-contain drop-shadow-md"
                                />
                                <span>{{ knowledgeDisciplineNames[discipline] }}</span>
                                <input
                                    type="number"
                                    :name="`book_counts[${discipline}]`"
                                    :value="startingBookCounts[discipline]"
                                    min="0"
                                    :max="startingBookCounts[discipline] + remainingStartingBookCount"
                                    required
                                    class="h-9 w-20 self-end rounded-md border border-input bg-background px-3 text-center text-sm shadow-xs"
                                    @input="updateStartingBookCount(discipline, $event)"
                                />
                                <InputError :message="errors[`book_counts.${discipline}`]" />
                            </label>
                        </div>
                        <InputError :message="errors.book_counts" />
                    </div>

                    <div
                        v-if="(game.data.pendingInteraction?.context.competencyIds?.length ?? 0) > 0"
                        class="grid gap-3"
                    >
                        <p class="text-sm font-medium">Выберите стартовую компетенцию</p>
                        <TooltipProvider :delay-duration="150">
                            <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                <label
                                    v-for="competency in game.data.pendingInteraction?.context.competencyIds ?? []"
                                    :key="competency"
                                    class="cursor-pointer"
                                >
                                    <input
                                        v-model="selectedStartingCompetency"
                                        type="radio"
                                        name="competency_id"
                                        :value="competency"
                                        required
                                        class="peer sr-only"
                                    />
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span
                                                tabindex="0"
                                                class="grid cursor-help rounded-lg border bg-background/70 p-2 transition peer-checked:border-primary peer-checked:ring-2 peer-checked:ring-primary/40"
                                            >
                                                <img
                                                    :src="competencyImage(competency)"
                                                    :alt="`Компетенция ${competency}`"
                                                    class="aspect-square w-full object-contain drop-shadow-md"
                                                />
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent class="max-w-xs">
                                            <p class="font-semibold">Компетенция {{ competency.slice(-2) }}</p>
                                            <p>{{ game.data.competencyDescriptions[competency] }}</p>
                                        </TooltipContent>
                                    </Tooltip>
                                </label>
                            </div>
                        </TooltipProvider>
                        <InputError :message="errors.competency_id" />
                    </div>

                    <div v-if="(game.data.pendingInteraction?.context.knowledgeStepCount ?? 0) > 0" class="grid gap-3">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <p class="font-medium">Распределение шагов знаний</p>
                            <p class="rounded-md bg-background/75 px-3 py-1.5 font-medium">
                                Доступно: {{ availableStartingKnowledgeStepCount }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <label
                                v-for="discipline in game.data.pendingInteraction?.optionIds"
                                :key="discipline"
                                class="grid h-full grid-rows-[auto_minmax(2.5rem,1fr)_auto_auto] justify-items-center gap-2 rounded-lg border bg-background/70 p-3 text-center text-sm font-medium"
                            >
                                <img
                                    :src="knowledgeRoundImages[discipline]"
                                    :alt="`Дисциплина: ${knowledgeDisciplineNames[discipline]}`"
                                    class="h-16 w-auto object-contain drop-shadow-md"
                                />
                                <span>{{ knowledgeDisciplineNames[discipline] }}</span>
                                <input
                                    type="number"
                                    :name="`knowledge_counts[${discipline}]`"
                                    :value="startingKnowledgeCounts[discipline]"
                                    min="0"
                                    :max="startingKnowledgeCounts[discipline] + remainingStartingKnowledgeStepCount"
                                    required
                                    class="h-9 w-20 self-end rounded-md border border-input bg-background px-3 text-center text-sm shadow-xs"
                                    @input="updateStartingKnowledgeCount(discipline, $event)"
                                />
                                <InputError :message="errors[`knowledge_counts.${discipline}`]" />
                            </label>
                        </div>
                        <InputError :message="errors.knowledge_counts" />
                    </div>

                    <InputError :message="errors.game" />
                    <Dialog>
                        <DialogTrigger as-child>
                            <Button
                                type="button"
                                :disabled="
                                    processing ||
                                    remainingStartingBookCount !== 0 ||
                                    remainingStartingKnowledgeStepCount !== 0 ||
                                    (requiresStartingCompetency && selectedStartingCompetency === null)
                                "
                            >
                                Подтвердить выбор
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Подтвердите распределение ресурсов</DialogTitle>
                                <DialogDescription>
                                    Проверьте выбранные стартовые ресурсы перед сохранением.
                                </DialogDescription>
                            </DialogHeader>

                            <div class="grid gap-4 rounded-lg bg-muted p-4 text-sm">
                                <div v-if="availableStartingBookCount > 0" class="grid gap-1">
                                    <p class="font-medium">Книги</p>
                                    <p
                                        v-for="discipline in game.data.pendingInteraction?.optionIds ?? []"
                                        v-show="startingBookCounts[discipline] > 0"
                                        :key="`book-${discipline}`"
                                        class="text-muted-foreground"
                                    >
                                        {{ knowledgeDisciplineNames[discipline] }}:
                                        {{ startingBookCounts[discipline] }}
                                    </p>
                                </div>

                                <div v-if="availableStartingKnowledgeStepCount > 0" class="grid gap-1">
                                    <p class="font-medium">Шаги знаний</p>
                                    <p
                                        v-for="discipline in game.data.pendingInteraction?.optionIds ?? []"
                                        v-show="startingKnowledgeCounts[discipline] > 0"
                                        :key="`knowledge-${discipline}`"
                                        class="text-muted-foreground"
                                    >
                                        {{ knowledgeDisciplineNames[discipline] }}:
                                        {{ startingKnowledgeCounts[discipline] }}
                                    </p>
                                </div>

                                <div v-if="selectedStartingCompetency" class="grid gap-2">
                                    <p class="font-medium">Компетенция</p>
                                    <div class="flex items-center gap-3 text-muted-foreground">
                                        <img
                                            :src="competencyImage(selectedStartingCompetency)"
                                            :alt="`Компетенция ${selectedStartingCompetency}`"
                                            class="h-14 w-14 object-contain drop-shadow-md"
                                        />
                                        <span>Компетенция {{ selectedStartingCompetency.slice(-2) }}</span>
                                    </div>
                                </div>
                            </div>

                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button type="button" variant="outline">Отмена</Button>
                                </DialogClose>
                                <Button type="submit" form="starting-resources-form" :disabled="processing">
                                    {{ processing ? 'Сохранение…' : 'Подтвердить' }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </Form>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <Form
                        v-for="bundle in game.data.planningBundles"
                        :key="bundle.homeland"
                        v-bind="PlanningBundleController.store.form(game.data.id)"
                        :id="`planning-bundle-${bundle.homeland}`"
                        #default="{ errors, processing }"
                        :class="[
                            'flex flex-col gap-4 rounded-xl border p-4 shadow-sm',
                            terrainBundleClasses[bundle.homeland],
                        ]"
                    >
                        <input type="hidden" name="homeland" :value="bundle.homeland" />

                        <TooltipProvider :delay-duration="150">
                            <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <img
                                            :src="terrainTileImage(bundle.homeland)"
                                            :alt="`Родная местность: ${terrainNames[bundle.homeland]}`"
                                            tabindex="0"
                                            class="h-48 w-auto cursor-help rounded-md object-contain shadow-sm"
                                        />
                                    </TooltipTrigger>
                                    <TooltipContent class="max-w-xs">
                                        <p class="font-semibold">{{ terrainNames[bundle.homeland] }}</p>
                                        <p>{{ game.data.planningBundleDescriptions.homelands[bundle.homeland] }}</p>
                                    </TooltipContent>
                                </Tooltip>

                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <div
                                            tabindex="0"
                                            class="relative aspect-[592/338] w-full max-w-[21rem] min-w-0 justify-self-center cursor-help"
                                        >
                                            <img
                                                :src="factionImage(bundle.faction)"
                                                :alt="`Сообщество: ${factionNames[bundle.faction]}`"
                                                class="size-full rounded-md object-cover shadow-sm"
                                            />
                                            <img
                                                v-if="selectedCompetencyForHomeland(bundle.homeland)"
                                                :src="competencyImage(selectedCompetencyForHomeland(bundle.homeland)!)"
                                                :alt="`Выбранная компетенция ${selectedCompetencyForHomeland(bundle.homeland)}`"
                                                class="absolute top-0 right-0 size-16 rounded-md object-contain p-1 shadow-md"
                                            />
                                        </div>
                                    </TooltipTrigger>
                                    <TooltipContent class="max-w-xs">
                                        <p class="font-semibold">{{ factionNames[bundle.faction] }}</p>
                                        <p>{{ game.data.planningBundleDescriptions.factions[bundle.faction] }}</p>
                                    </TooltipContent>
                                </Tooltip>

                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <img
                                            :src="roundBonusImage(bundle.roundBonus)"
                                            :alt="`Бонус раунда: ${roundBonusNames[bundle.roundBonus]}`"
                                            tabindex="0"
                                            class="h-48 w-auto cursor-help object-contain drop-shadow-sm"
                                        />
                                    </TooltipTrigger>
                                    <TooltipContent class="max-w-xs">
                                        <p class="font-semibold">{{ roundBonusNames[bundle.roundBonus] }}</p>
                                        <p>{{ game.data.planningBundleDescriptions.roundBonuses[bundle.roundBonus] }}</p>
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                        </TooltipProvider>

                        <InputError :message="errors.homeland ?? errors.game" />
                        <div
                            v-if="selectedPlayerForHomeland(bundle.homeland)"
                            class="mt-auto flex min-h-10 items-center justify-center gap-3 rounded-md bg-background/75 px-4 py-2 text-center text-sm font-medium shadow-xs"
                        >
                            {{ selectedPlayerForHomeland(bundle.homeland)?.user.name }}
                        </div>
                        <Dialog v-else>
                            <DialogTrigger as-child>
                                <Button
                                    type="button"
                                    class="mt-auto w-full"
                                    :disabled="processing || !canChoosePlanningBundle"
                                >
                                    {{ planningBundleButtonLabel(processing) }}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Подтвердите выбор комплекта</DialogTitle>
                                    <DialogDescription>
                                        После подтверждения этот комплект будет закреплён за вами.
                                    </DialogDescription>
                                </DialogHeader>

                                <div class="grid gap-2 rounded-lg bg-muted p-4 text-sm">
                                    <p><span class="text-muted-foreground">Земля:</span> {{ terrainNames[bundle.homeland] }}</p>
                                    <p><span class="text-muted-foreground">Раса:</span> {{ factionNames[bundle.faction] }}</p>
                                    <p>
                                        <span class="text-muted-foreground">Бонус раунда:</span>
                                        {{ roundBonusNames[bundle.roundBonus] }}
                                    </p>
                                </div>

                                <DialogFooter class="gap-2">
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline">Отмена</Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        :form="`planning-bundle-${bundle.homeland}`"
                                        :disabled="processing"
                                    >
                                        {{ processing ? 'Выбор…' : 'Подтвердить' }}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </Form>
                </div>
            </CardContent>
        </Card>

        <section v-if="game.data.status === 'active' && setupChoicesCompleted" class="grid gap-4">
            <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,7fr)_minmax(16rem,3fr)]">
                <div class="grid gap-4">
                    <BoardMap
                        :board="game.data.board"
                        :round-scoring-tiles="game.data.roundScoringTiles"
                        :final-round-scoring-tile="game.data.finalRoundScoringTile"
                        :book-actions="game.data.bookActions"
                    />

                    <PlayerBoards
                        :players="orderedPlayers"
                        :player-states="game.data.playerBoardStates"
                        :current-user-id="page.props.auth.user.id"
                    />
                </div>

                <aside class="grid gap-4">
                    <CultBoard :players="orderedPlayers" :player-states="game.data.playerBoardStates" />
                    <RoundBonusBoard :offers="game.data.roundBonusOffers" />
                    <InnovationBoard
                        :player-count="game.data.playersCount"
                        :innovations="game.data.innovations"
                        :competencies="game.data.competencies"
                    />
                    <PalaceBoard :palaces="game.data.availablePalaceIds" />
                    <TownTileBoard :town-tiles="game.data.availableTownTileIds" />
                </aside>
            </div>
        </section>

        <PlayerStatsPanel
            v-if="game.data.status === 'active' && setupChoicesCompleted"
            :players="orderedPlayers"
            :player-states="game.data.playerBoardStates"
        />
    </div>
</template>
