<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { Check } from '@lucide/vue';
import { ref } from 'vue';
import TownController from '@/actions/App/Http/Controllers/TownController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
    <div class="contents">
        <Form
            v-if="game.data.pendingInteraction?.type === 'choose_town'"
            v-bind="TownController.form(game.data.id)"
            #default="{ errors, processing }"
            class="flex min-w-0 items-center gap-2"
        >
            <span class="shrink-0 text-sm font-medium">Выберите жетон города:</span>
            <input type="hidden" name="town_tile" :value="selectedTownTile ?? ''" />
            <div class="flex min-w-0 gap-1 overflow-x-auto py-1">
                <button
                    v-for="townTile in game.data.pendingInteraction.optionIds as TownTile[]"
                    :key="townTile"
                    type="button"
                    class="shrink-0 rounded-md border p-0.5 transition hover:border-primary"
                    :class="selectedTownTile === townTile ? 'border-primary ring-2 ring-primary' : 'border-border'"
                    :aria-label="game.data.townTileDescriptions[townTile]"
                    :aria-pressed="selectedTownTile === townTile"
                    :title="game.data.townTileDescriptions[townTile]"
                    @click="selectedTownTile = townTile"
                >
                    <img :src="townTileImage(townTile)" alt="" class="h-20 w-[4.5rem] object-contain" />
                </button>
            </div>
            <Button type="submit" size="sm" :disabled="processing || selectedTownTile === null">
                <Check class="size-4" />
                Подтвердить
            </Button>
            <InputError :message="errors.town_tile" class="shrink-0" />
        </Form>
    </div>
</template>
