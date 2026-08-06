<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import GamePlayerController from '@/actions/App/Http/Controllers/GamePlayerController';
import GamePlayerReadinessController from '@/actions/App/Http/Controllers/GamePlayerReadinessController';
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
import type { GameResource, MapVariant } from '@/types';

const props = defineProps<{
    game: GameResource;
}>();

const page = usePage();

const currentPlayer = computed(() =>
    props.game.data.players.find(
        (player) => player.user.id === page.props.auth.user.id,
    ),
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

        <Card>
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
    </div>
</template>
