<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Check, RotateCcw, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import TownBooksController from '@/actions/App/Http/Controllers/TownBooksController';
import TownChoiceUndoController from '@/actions/App/Http/Controllers/TownChoiceUndoController';
import TownController from '@/actions/App/Http/Controllers/TownController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { NumberStepper } from '@/components/ui/number-stepper';
import type { GameResource, KnowledgeDiscipline, TownTile } from '@/types';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';

const props = defineProps<{ game: GameResource }>();
const selectedTownTile = ref<TownTile | null>(null);
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const bookCounts = reactive<Record<KnowledgeDiscipline, number>>({ banking: 0, law: 0, engineering: 0, medicine: 0 });
const assignedBookCount = computed(() => Object.values(bookCounts).reduce((sum, count) => sum + count, 0));
const remainingBookCount = computed(() => 2 - assignedBookCount.value);
const townTileImages = import.meta.glob<string>('../../../images/cities/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
});
const bookImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};

function townTileImage(townTile: TownTile): string {
    return townTileImages[`../../../images/cities/${townTile}.png`] ?? '';
}

function maximumBooksFor(discipline: KnowledgeDiscipline): number {
    return bookCounts[discipline] + remainingBookCount.value;
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

        <div
            v-else-if="game.data.pendingInteraction?.type === 'choose_town_books'"
            class="flex min-w-0 items-center gap-2"
        >
            <Form v-bind="TownChoiceUndoController.form(game.data.id)" #default="{ processing }">
                <Button type="submit" variant="outline" size="sm" :disabled="processing">
                    <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                    Изменить жетон
                </Button>
            </Form>
            <Form
                v-bind="TownBooksController.form(game.data.id)"
                #default="{ errors, processing }"
                class="flex min-w-0 items-center gap-2"
            >
                <span class="shrink-0 text-sm font-medium">Распределите две книги:</span>
                <label
                    v-for="discipline in disciplines"
                    :key="discipline"
                    class="flex shrink-0 items-center gap-1 rounded-md border px-1.5 py-1"
                    :title="game.data.knowledgeDisciplineNames[discipline]"
                >
                    <img :src="bookImages[discipline]" alt="" class="size-7 object-contain" />
                    <NumberStepper v-model="bookCounts[discipline]" :min="0" :max="maximumBooksFor(discipline)" />
                    <input type="hidden" :name="`book_counts[${discipline}]`" :value="bookCounts[discipline]" />
                </label>
                <Button type="submit" size="sm" :disabled="processing || remainingBookCount !== 0">
                    <Check class="size-4" />
                    Подтвердить
                </Button>
                <InputError :message="errors.book_counts ?? errors.game" class="shrink-0" />
            </Form>
        </div>
    </div>
</template>
