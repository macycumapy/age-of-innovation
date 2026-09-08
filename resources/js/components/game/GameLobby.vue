<script setup lang="ts">
import GamePlayerController from '@/actions/App/Http/Controllers/GamePlayerController';
import GamePlayerReadinessController from '@/actions/App/Http/Controllers/GamePlayerReadinessController';
import GameStartController from '@/actions/App/Http/Controllers/GameStartController';
import Form from '@/components/game/GameActionForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { GamePlayerSummary, GameResource } from '@/types';

defineProps<{
    game: GameResource;
    currentPlayer?: GamePlayerSummary;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Участники</CardTitle>
            <CardDescription>Игроки занимают места в порядке присоединения.</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-3">
            <div
                v-for="player in game.data.players"
                :key="player.id"
                class="flex items-center justify-between gap-4 rounded-lg border p-3"
            >
                <div>
                    <p class="font-medium">{{ player.user.name }}</p>
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
                        {{ currentPlayer.isReady ? 'Отменить готовность' : 'Я готов' }}
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
                        {{ 'Присоединиться' }}
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
</template>
