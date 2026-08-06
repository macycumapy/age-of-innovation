<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import GameController from '@/actions/App/Http/Controllers/GameController';
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
import type { GameCollection, GameStatus, MapVariant } from '@/types';

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
</script>

<template>
    <Head title="Игры" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-2xl font-semibold">Игры</h1>
            <p class="text-sm text-muted-foreground">
                Создайте новую партию или вернитесь к существующей.
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Новая игра</CardTitle>
                <CardDescription>
                    Выберите сторону игрового поля.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="GameController.store.form()"
                    reset-on-success
                    class="flex flex-col gap-4 sm:flex-row sm:items-start"
                    #default="{ errors, processing }"
                >
                    <div class="grid flex-1 gap-2">
                        <label for="map_variant" class="text-sm font-medium">
                            Количество игроков
                        </label>
                        <select
                            id="map_variant"
                            name="map_variant"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        >
                            <option value="three_to_five_players">
                                3–5 игроков
                            </option>
                            <option value="one_to_three_players">
                                1–3 игрока
                            </option>
                        </select>
                        <InputError :message="errors.map_variant" />
                    </div>

                    <Button
                        type="submit"
                        class="sm:mt-7"
                        :disabled="processing"
                    >
                        {{ processing ? 'Создание…' : 'Создать игру' }}
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <section class="grid gap-3">
            <h2 class="text-lg font-semibold">Мои игры</h2>

            <div
                v-if="games.data.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                У вас пока нет игр.
            </div>

            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <Card
                    v-for="game in games.data"
                    :key="game.id"
                    class="gap-3"
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
                        <p class="text-muted-foreground">
                            Создана {{ formatDate(game.createdAt) }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>
    </div>
</template>
