<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { Check } from '@lucide/vue';
import { ref } from 'vue';
import TownController from '@/actions/App/Http/Controllers/TownController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { GameResource, TownTile } from '@/types';

const props = defineProps<{ game: GameResource }>();
const selectedTownTile = ref<TownTile | null>(null);
const townTileImages = import.meta.glob<string>('../../../images/cities/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
});

function townTileImage(townTile: TownTile): string {
    return townTileImages[`../../../images/cities/${townTile}.png`] ?? '';
}
</script>

<template>
    <Card v-if="game.data.pendingInteraction?.type === 'choose_town'" class="mx-auto w-full max-w-5xl border-0 p-2">
        <CardContent>
        <Form
            v-bind="TownController.form(game.data.id)"
            #default="{ errors, processing }"
            class="grid min-w-0 gap-3"
        >
            <input type="hidden" name="town_tile" :value="selectedTownTile ?? ''" />
            <div class="flex min-w-0 justify-center gap-2 overflow-x-auto py-1">
                <button
                    v-for="townTile in game.data.pendingInteraction.optionIds as TownTile[]"
                    :key="townTile"
                    type="button"
                    class="shrink-0 rounded-md border border-transparent p-1 transition hover:border-secondary"
                    :class="selectedTownTile === townTile ? 'border-primary ring-2 ring-primary' : 'border-border'"
                    :aria-label="game.data.townTileDescriptions[townTile]"
                    :aria-pressed="selectedTownTile === townTile"
                    :title="game.data.townTileDescriptions[townTile]"
                    @click="selectedTownTile = townTile"
                >
                    <img :src="townTileImage(townTile)" alt="" class="h-20 w-[4.5rem] object-contain" />
                </button>
            </div>
            <div class="grid justify-items-end gap-1.5">
                <InputError :message="errors.town_tile" />
                <Button type="submit" size="sm" :disabled="processing || selectedTownTile === null">
                    <Check class="size-4" />
                    Подтвердить
                </Button>
            </div>
        </Form>
        </CardContent>
    </Card>
</template>
