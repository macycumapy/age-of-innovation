<script setup lang="ts">
import { Form, Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import GamePlayerController from '@/actions/App/Http/Controllers/GamePlayerController';
import GamePlayerReadinessController from '@/actions/App/Http/Controllers/GamePlayerReadinessController';
import GameStartController from '@/actions/App/Http/Controllers/GameStartController';
import PlanningBundleController from '@/actions/App/Http/Controllers/PlanningBundleController';
import StartingResourcesController from '@/actions/App/Http/Controllers/StartingResourcesController';
import BoardMap from '@/components/game/BoardMap.vue';
import PlayerBoards from '@/components/game/PlayerBoards.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index } from '@/routes/games';
import type {
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
    props.game.data.players.find(
        (player) => player.user.id === page.props.auth.user.id,
    ),
);

const activePlayer = computed(() =>
    props.game.data.players.find(
        (player) => player.user.id === props.game.data.activePlayerId,
    ),
);

const orderedPlayers = computed(() =>
    props.game.data.turnOrder
        .map((playerId) =>
            props.game.data.players.find((player) => player.id === playerId),
        )
        .filter(
            (player): player is GamePlayerSummary => player !== undefined,
        ),
);

const nextPlayer = computed(() => {
    const activeIndex = orderedPlayers.value.findIndex(
        (player) => player.user.id === props.game.data.activePlayerId,
    );

    if (activeIndex < 0) {
        return undefined;
    }

    for (let offset = 1; offset < orderedPlayers.value.length; offset++) {
        const player =
            orderedPlayers.value[
                (activeIndex + offset) % orderedPlayers.value.length
            ];

        if (player.faction === null) {
            return player;
        }
    }

    return undefined;
});

const canChoosePlanningBundle = computed(
    () =>
        props.game.data.status === 'active' &&
        props.game.data.activePlayerId === page.props.auth.user.id &&
        currentPlayer.value?.faction === null,
);

const canChooseStartingResources = computed(
    () =>
        props.game.data.pendingInteraction?.type ===
            'choose_starting_resources' &&
        props.game.data.pendingInteraction.playerId === currentPlayer.value?.id &&
        props.game.data.activePlayerId === page.props.auth.user.id,
);

const allPlanningBundlesChosen = computed(() =>
    props.game.data.players.every((player) => player.faction !== null),
);

const setupChoicesCompleted = computed(
    () =>
        allPlanningBundlesChosen.value &&
        props.game.data.pendingInteraction === null,
);

defineOptions({
    layout: {
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

const knowledgeDisciplineNames: Record<KnowledgeDiscipline, string> = {
    banking: 'Банковское дело',
    law: 'Право',
    engineering: 'Инженерное дело',
    medicine: 'Медицина',
};

function planningBundleButtonLabel(processing: boolean): string {
    if (processing) {
        return 'Выбор…';
    }

    if (allPlanningBundlesChosen.value) {
        return 'Выбор завершён';
    }

    return canChoosePlanningBundle.value
        ? 'Выбрать комплект'
        : 'Сейчас выбирает другой игрок';
}

function planningSelectionFor(playerId: number) {
    return props.game.data.planningSelections.find(
        (selection) => selection.playerId === playerId,
    );
}

function planningSelectionFactionName(playerId: number): string {
    const selection = planningSelectionFor(playerId);

    return selection ? factionNames[selection.bundle.faction] : '';
}

function planningSelectionDetails(playerId: number): string {
    const selection = planningSelectionFor(playerId);

    if (!selection) {
        return '';
    }

    return `${terrainNames[selection.bundle.homeland]} · ${roundBonusNames[selection.bundle.roundBonus]}`;
}
</script>

<template>
    <Head :title="`Подготовка игры №${game.data.id}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">
                    Подготовка игры №{{ game.data.id }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Поле {{ mapVariantNames[game.data.mapVariant] }} ·
                    {{ game.data.playersCount }} из
                    {{ game.data.maxPlayers }} игроков
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link :href="index()">Вернуться к играм</Link>
            </Button>
        </div>

        <Card v-if="game.data.status === 'lobby'">
            <CardHeader>
                <CardTitle>Участники</CardTitle>
                <CardDescription>
                    Игроки занимают места в порядке присоединения.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-3">
                <div
                    v-for="player in game.data.players"
                    :key="player.id"
                    class="flex items-center justify-between gap-4 rounded-lg border p-3"
                >
                    <div>
                        <p class="font-medium">{{ player.user.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            Место {{ player.seat }}
                        </p>
                    </div>

                    <span
                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium"
                    >
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
            </CardContent>
        </Card>

        <Card v-if="game.data.status === 'lobby'">
            <CardHeader>
                <CardTitle>Присоединение</CardTitle>
                <CardDescription v-if="currentPlayer">
                    Подтвердите готовность к началу партии.
                </CardDescription>
                <CardDescription
                    v-else-if="game.data.playersCount >= game.data.maxPlayers"
                >
                    Все места уже заняты.
                </CardDescription>
                <CardDescription v-else>
                    Займите свободное место в этой партии.
                </CardDescription>
            </CardHeader>
            <CardContent v-if="currentPlayer">
                <Form
                    v-bind="
                        GamePlayerReadinessController.update.form({
                            game: game.data.id,
                            gamePlayer: currentPlayer.id,
                        })
                    "
                    #default="{ errors, processing }"
                    class="grid gap-3"
                >
                    <input
                        type="hidden"
                        name="is_ready"
                        :value="currentPlayer.isReady ? '0' : '1'"
                    />
                    <InputError :message="errors.is_ready" />
                    <Button
                        type="submit"
                        :variant="currentPlayer.isReady ? 'outline' : 'default'"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Сохранение…'
                                : currentPlayer.isReady
                                  ? 'Отменить готовность'
                                  : 'Я готов'
                        }}
                    </Button>
                </Form>
            </CardContent>
            <CardContent
                v-if="
                    !currentPlayer &&
                    game.data.playersCount < game.data.maxPlayers
                "
            >
                <Form
                    v-bind="GamePlayerController.store.form(game.data.id)"
                    #default="{ errors, processing }"
                    class="grid gap-3"
                >
                    <InputError :message="errors.game" />
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Присоединение…' : 'Присоединиться' }}
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <Card v-if="game.data.status === 'lobby' && game.data.isOwner">
            <CardHeader>
                <CardTitle>Начало партии</CardTitle>
                <CardDescription v-if="game.data.canStart">
                    Все участники готовы. После запуска первый игрок выберет
                    стартовый комплект.
                </CardDescription>
                <CardDescription v-else>
                    Для запуска нужны минимум два готовых игрока.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="GameStartController.form(game.data.id)"
                    #default="{ errors, processing }"
                    class="grid gap-3"
                >
                    <InputError :message="errors.game" />
                    <Button
                        type="submit"
                        :disabled="processing || !game.data.canStart"
                    >
                        {{ processing ? 'Запуск…' : 'Начать игру' }}
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <Card
            v-if="game.data.status === 'active' && !setupChoicesCompleted"
        >
            <CardHeader>
                <CardTitle>Выбор стартового комплекта</CardTitle>
                <CardDescription v-if="canChooseStartingResources">
                    Завершите распределение стартовых ресурсов.
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
                <div class="mb-6 grid gap-3">
                    <div class="flex items-center justify-between gap-4">
                        <h3 class="text-base font-semibold">Порядок выбора</h3>
                        <p
                            v-if="nextPlayer"
                            class="text-sm text-muted-foreground"
                        >
                            Следующий: {{ nextPlayer.user.name }}
                        </p>
                    </div>

                    <ol
                        class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <li
                            v-for="(player, index) in orderedPlayers"
                            :key="player.id"
                            :class="[
                                'flex items-start gap-3 rounded-lg border p-3',
                                player.user.id === game.data.activePlayerId &&
                                (player.faction === null ||
                                    game.data.pendingInteraction?.playerId ===
                                        player.id)
                                    ? 'border-primary bg-primary/5'
                                    : 'bg-muted/30',
                            ]"
                        >
                            <span
                                class="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold"
                            >
                                {{ index + 1 }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ player.user.name }}
                                </p>
                                <p
                                    v-if="
                                        player.user.id ===
                                            game.data.activePlayerId &&
                                        (player.faction === null ||
                                            game.data.pendingInteraction
                                                ?.playerId === player.id)
                                    "
                                    class="text-xs font-medium text-primary"
                                >
                                    {{
                                        game.data.pendingInteraction?.playerId ===
                                        player.id
                                            ? 'Распределяет ресурсы'
                                            : 'Выбирает сейчас'
                                    }}
                                </p>
                                <p
                                    v-else-if="player.id === nextPlayer?.id"
                                    class="text-xs text-muted-foreground"
                                >
                                    Следующий
                                </p>
                                <p
                                    v-else-if="player.faction"
                                    class="text-xs text-muted-foreground"
                                >
                                    Комплект выбран
                                </p>
                                <p
                                    v-else
                                    class="text-xs text-muted-foreground"
                                >
                                    Ожидает
                                </p>

                                <div
                                    v-if="planningSelectionFor(player.id)"
                                    class="mt-3 grid gap-1 border-t pt-3 text-xs"
                                >
                                    <p class="font-medium text-foreground">
                                        {{
                                            planningSelectionFactionName(
                                                player.id,
                                            )
                                        }}
                                    </p>
                                    <p class="text-muted-foreground">
                                        {{ planningSelectionDetails(player.id) }}
                                    </p>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>

                <Form
                    v-if="canChooseStartingResources"
                    v-bind="StartingResourcesController.store.form(game.data.id)"
                    #default="{ errors, processing }"
                    class="mb-6 grid gap-5 rounded-xl border border-primary/40 bg-primary/5 p-5"
                >
                    <div class="grid gap-1">
                        <h3 class="font-semibold">
                            Распределите стартовые ресурсы
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Этот выбор завершает получение вашего стартового
                            комплекта.
                        </p>
                    </div>

                    <label
                        v-if="
                            (game.data.pendingInteraction?.context.bookCount ??
                                0) > 0
                        "
                        class="grid gap-2 text-sm font-medium"
                    >
                        Дисциплина дополнительной книги
                        <select
                            name="book_discipline"
                            required
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm shadow-xs"
                        >
                            <option value="" disabled selected>
                                Выберите дисциплину
                            </option>
                            <option
                                v-for="discipline in game.data
                                    .pendingInteraction?.optionIds"
                                :key="discipline"
                                :value="discipline"
                            >
                                {{ knowledgeDisciplineNames[discipline] }}
                            </option>
                        </select>
                        <InputError :message="errors.book_discipline" />
                    </label>

                    <div
                        v-if="
                            (game.data.pendingInteraction?.context
                                .knowledgeStepCount ?? 0) > 0
                        "
                        class="grid gap-3"
                    >
                        <p class="text-sm font-medium">
                            Распределение шагов знаний
                        </p>
                        <label
                            v-for="step in game.data.pendingInteraction?.context
                                .knowledgeStepCount"
                            :key="step"
                            class="grid gap-2 text-sm"
                        >
                            Шаг {{ step }}
                            <select
                                name="knowledge_disciplines[]"
                                required
                                class="border-input bg-background h-9 rounded-md border px-3 text-sm shadow-xs"
                            >
                                <option value="" disabled selected>
                                    Выберите дисциплину
                                </option>
                                <option
                                    v-for="discipline in game.data
                                        .pendingInteraction?.optionIds"
                                    :key="discipline"
                                    :value="discipline"
                                >
                                    {{ knowledgeDisciplineNames[discipline] }}
                                </option>
                            </select>
                        </label>
                        <InputError
                            :message="
                                errors.knowledge_disciplines ??
                                errors['knowledge_disciplines.0'] ??
                                errors['knowledge_disciplines.1']
                            "
                        />
                    </div>

                    <InputError :message="errors.game" />
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Сохранение…' : 'Подтвердить выбор' }}
                    </Button>
                </Form>

                <div
                    class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
                >
                    <Form
                        v-for="bundle in game.data.planningBundles"
                        :key="bundle.homeland"
                        v-bind="PlanningBundleController.store.form(game.data.id)"
                        #default="{ errors, processing }"
                        class="flex min-h-52 flex-col gap-4 rounded-xl border bg-card p-5 shadow-sm"
                    >
                        <input
                            type="hidden"
                            name="homeland"
                            :value="bundle.homeland"
                        />

                        <div class="grid gap-1">
                            <p class="text-sm text-muted-foreground">
                                {{ terrainNames[bundle.homeland] }}
                            </p>
                            <h2 class="text-lg font-semibold">
                                {{ factionNames[bundle.faction] }}
                            </h2>
                        </div>

                        <div class="rounded-lg bg-muted p-3 text-sm">
                            <span class="text-muted-foreground">
                                Бонус раунда:
                            </span>
                            {{ roundBonusNames[bundle.roundBonus] }}
                        </div>

                        <InputError
                            :message="errors.homeland ?? errors.game"
                        />
                        <Button
                            type="submit"
                            class="mt-auto w-full"
                            :disabled="processing || !canChoosePlanningBundle"
                        >
                            {{ planningBundleButtonLabel(processing) }}
                        </Button>
                    </Form>
                </div>
            </CardContent>
        </Card>

        <section
            v-if="game.data.status === 'active' && setupChoicesCompleted"
            class="grid gap-4"
        >
            <div>
                <h2 class="text-xl font-semibold">Игровая карта</h2>
                <p class="text-sm text-muted-foreground">
                    Все игроки выбрали стартовые комплекты.
                </p>
            </div>

            <BoardMap
                :board="game.data.board"
                :round-scoring-tiles="game.data.roundScoringTiles"
                :final-round-scoring-tile="game.data.finalRoundScoringTile"
                :book-actions="game.data.bookActions"
            />

            <PlayerBoards
                :players="orderedPlayers"
                :current-user-id="page.props.auth.user.id"
            />
        </section>
    </div>
</template>
