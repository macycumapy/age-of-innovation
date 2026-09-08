<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CreateGameDialog from '@/components/game/CreateGameDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { index, show } from '@/routes/games';
import type { GameCollection, GameStatus, GameSummary, MapVariant } from '@/types';

defineProps<{
    games: GameCollection;
}>();

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
</script>

<template>
    <Head title="Игры" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Игры</h1>
                <p class="text-sm text-muted-foreground">Создайте новую партию или вернитесь к существующей.</p>
            </div>

            <CreateGameDialog />
        </div>

        <section class="grid gap-3">
            <h2 class="text-lg font-semibold">Мои игры</h2>

            <div
                v-if="games.data.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                У вас пока нет игр.
            </div>

            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="game in games.data" :key="game.id" class="gap-3">
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
            </div>
        </section>
    </div>
</template>
