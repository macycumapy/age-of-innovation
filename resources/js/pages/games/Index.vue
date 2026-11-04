<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import CreateGameDialog from '@/components/game/CreateGameDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { index, show } from '@/routes/games';
import type { GameCollection, GameStatus, GameSummary, MapVariant } from '@/types';
import gamesBackgroundImage from '../../../images/background.jpg';

const props = defineProps<{
    games: GameCollection;
}>();

defineOptions({
    layout: {
        backgroundImage: gamesBackgroundImage,
        breadcrumbs: [
            {
                title: 'Игры',
                href: index(),
            },
        ],
    },
});

const highlightedGameIds = ref(new Set<number>());
const highlightTimers = new Map<number, ReturnType<typeof setTimeout>>();

watch(
    () => props.games.data.map((game) => game.id),
    (gameIds, previousGameIds) => {
        const currentGameIds = new Set(gameIds);

        for (const [gameId, timer] of highlightTimers) {
            if (!currentGameIds.has(gameId)) {
                clearTimeout(timer);
                highlightTimers.delete(gameId);
                highlightedGameIds.value.delete(gameId);
            }
        }

        for (const gameId of gameIds) {
            if (previousGameIds.includes(gameId)) {
                continue;
            }

            highlightedGameIds.value.add(gameId);
            highlightTimers.set(
                gameId,
                setTimeout(() => {
                    highlightedGameIds.value.delete(gameId);
                    highlightTimers.delete(gameId);
                }, 3000),
            );
        }
    },
);

onBeforeUnmount(() => {
    highlightTimers.forEach((timer) => clearTimeout(timer));
    highlightTimers.clear();
});

const statusNames: Record<GameStatus, string> = {
    lobby: 'Ожидает игроков',
    active: 'Идёт игра',
    finished: 'Завершена',
    abandoned: 'Прервана',
};

const mapVariantNames: Record<MapVariant, string> = {
    one_to_three_players: '1–3 игрока',
    three_to_five_players: '3–5 игроков',
};

const formatDate = (date: string | null): string =>
    date
        ? new Intl.DateTimeFormat('ru-RU', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(date))
        : '—';

function gameButtonLabel(game: GameSummary): string {
    if (game.status === 'lobby') {
        return game.isJoined ? 'Открыть подготовку' : 'Присоединиться';
    }

    if (game.status === 'finished') {
        return 'Посмотреть результаты';
    }

    if (game.status === 'abandoned') {
        return 'Посмотреть партию';
    }

    return 'Продолжить игру';
}

function prepareListItemLeave(element: Element): void {
    const item = element as HTMLElement;
    const { width, height } = item.getBoundingClientRect();

    item.style.width = `${width}px`;
    item.style.height = `${height}px`;
    item.style.left = `${item.offsetLeft}px`;
    item.style.top = `${item.offsetTop}px`;
}

function resetListItemPosition(element: Element): void {
    const item = element as HTMLElement;

    item.style.width = '';
    item.style.height = '';
    item.style.left = '';
    item.style.top = '';
}
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Head title="Игры" />

        <div
            class="sticky top-0 z-20 flex flex-wrap items-start justify-between gap-4 rounded-xl bg-background/80 p-4 shadow-sm backdrop-blur-sm"
        >
            <div>
                <h1 class="text-2xl font-semibold">Игры</h1>
                <p class="text-sm text-muted-foreground">Создайте новую партию или вернитесь к существующей.</p>
            </div>

            <CreateGameDialog />
        </div>

        <section class="grid gap-3 rounded-xl p-4 shadow-sm">
            <TransitionGroup
                name="game-list"
                tag="div"
                class="relative grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                @before-leave="prepareListItemLeave"
                @after-leave="resetListItemPosition"
                @leave-cancelled="resetListItemPosition"
            >
                <div
                    v-if="games.data.length === 0"
                    key="empty"
                    class="col-span-full rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    У вас пока нет игр.
                </div>

                <Card
                    v-for="game in games.data"
                    :key="game.id"
                    class="gap-3 transition-colors duration-700 motion-reduce:transition-none"
                    :class="
                        highlightedGameIds.has(game.id)
                            ? 'border-amber-400 bg-amber-50/90 dark:bg-amber-950/70'
                            : 'bg-card/70'
                    "
                >
                    <CardHeader>
                        <CardTitle>Игра №{{ game.id }}</CardTitle>
                        <CardDescription>
                            {{ statusNames[game.status] }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-1 text-sm">
                        <p>
                            Поле:
                            <span class="font-medium">
                                {{ mapVariantNames[game.mapVariant] }}
                            </span>
                        </p>
                        <p>
                            Игроков:
                            <span class="font-medium">
                                {{ game.playersCount }}
                            </span>
                        </p>
                        <p v-if="game.currentRound !== null">
                            Текущий раунд:
                            <span class="font-medium">
                                {{ game.currentRound }}
                            </span>
                        </p>
                        <p class="text-muted-foreground" data-allow-mismatch="text">
                            Создана {{ formatDate(game.createdAt) }}
                        </p>

                        <Button as-child class="mt-3">
                            <Link :href="show(game.id)">
                                {{ gameButtonLabel(game) }}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            </TransitionGroup>
        </section>
    </div>
</template>

<style scoped>
.game-list-move,
.game-list-enter-active,
.game-list-leave-active {
    transition:
        opacity 300ms ease,
        transform 300ms ease;
}

.game-list-enter-from {
    opacity: 0;
    transform: translateY(0.75rem);
}

.game-list-leave-to {
    opacity: 0;
    transform: translateY(0.5rem) scale(0.97);
}

.game-list-leave-active {
    position: absolute;
    pointer-events: none;
}

@media (prefers-reduced-motion: reduce) {
    .game-list-move,
    .game-list-enter-active,
    .game-list-leave-active {
        transition: none;
    }

    .game-list-enter-from,
    .game-list-leave-to {
        transform: none;
    }
}
</style>
