<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed, reactive, watch } from 'vue';
import BookDistributionController from '@/actions/App/Http/Controllers/BookDistributionController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { NumberStepper } from '@/components/ui/number-stepper';
import type { KnowledgeDiscipline } from '@/types';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';

type BookDistributionType =
    | 'choose_science_bonus_books'
    | 'choose_innovation_books'
    | 'choose_shipping_books'
    | 'choose_terraforming_books'
    | 'choose_palace_books'
    | 'choose_town_books';

const props = defineProps<{
    gameId: number;
    bookCount: number;
    type?: BookDistributionType;
    disciplineNames: Record<KnowledgeDiscipline, string>;
    embedded?: boolean;
    errors?: Record<string, string>;
}>();
const emit = defineEmits<{
    change: [counts: Record<KnowledgeDiscipline, number>];
}>();
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const counts = reactive<Record<KnowledgeDiscipline, number>>({ banking: 0, law: 0, engineering: 0, medicine: 0 });
const images: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};
const assignedCount = computed(() => Object.values(counts).reduce((sum, count) => sum + count, 0));
const remainingCount = computed(() => props.bookCount - assignedCount.value);
const title = computed(() => 'Выберите получаемые книги');

function maximumFor(discipline: KnowledgeDiscipline): number {
    return counts[discipline] + remainingCount.value;
}

watch(counts, () => emit('change', { ...counts }), { deep: true, immediate: true });
</script>

<template>
    <Card class="mx-auto w-full max-w-3xl border-none p-0">
        <CardHeader class="gap-0.5 px-4 py-3">
            <CardTitle class="text-base">{{ title }}</CardTitle>
            <CardDescription class="text-xs">
                Распределите все полученные книги по дисциплинам. Осталось: {{ remainingCount }}
            </CardDescription>
        </CardHeader>
        <CardContent class="px-4 pb-3">
            <div v-if="embedded" class="grid gap-3">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div
                        v-for="discipline in disciplines"
                        :key="discipline"
                        class="grid justify-items-center gap-1 rounded-md px-2 py-1.5 text-xs font-medium"
                        :title="disciplineNames[discipline]"
                    >
                        <img
                            :src="images[discipline]"
                            :alt="disciplineNames[discipline]"
                            class="size-12 shrink-0 object-contain"
                        />
                        <NumberStepper v-model="counts[discipline]" :min="0" :max="maximumFor(discipline)" />
                    </div>
                </div>
                <InputError :message="errors?.book_counts ?? errors?.game" />
            </div>
            <Form
                v-else
                v-bind="BookDistributionController.form(gameId)"
                class="grid gap-3"
                #default="{ errors, processing }"
            >
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div
                        v-for="discipline in disciplines"
                        :key="discipline"
                        class="grid justify-items-center gap-1 rounded-md border px-2 py-1.5 text-xs font-medium"
                        :title="disciplineNames[discipline]"
                    >
                        <img
                            :src="images[discipline]"
                            :alt="disciplineNames[discipline]"
                            class="size-12 shrink-0 object-contain"
                        />
                        <NumberStepper v-model="counts[discipline]" :min="0" :max="maximumFor(discipline)" />
                        <input type="hidden" :name="`book_counts[${discipline}]`" :value="counts[discipline]" />
                    </div>
                </div>
                <div class="grid justify-items-end gap-1.5">
                    <InputError :message="errors.book_counts ?? errors.game" />
                    <Button type="submit" size="sm" :disabled="processing || remainingCount !== 0">
                        {{ processing ? 'Подтверждение…' : 'Подтвердить выбор' }}
                    </Button>
                </div>
            </Form>
        </CardContent>
    </Card>
</template>
