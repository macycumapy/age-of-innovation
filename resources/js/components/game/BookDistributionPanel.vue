<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed, reactive, ref, watch } from 'vue';
import BookDistributionController from '@/actions/App/Http/Controllers/BookDistributionController';
import InputError from '@/components/InputError.vue';
import KnowledgeStepDistributionPanel from '@/components/game/KnowledgeStepDistributionPanel.vue';
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
    | 'choose_town_books'
    | 'choose_feline_town_bonus';

const props = withDefaults(
    defineProps<{
        gameId: number;
        bookCount: number;
        knowledgeStepCount?: number;
        type?: BookDistributionType;
        disciplineNames: Record<KnowledgeDiscipline, string>;
        embedded?: boolean;
        errors?: Record<string, string>;
    }>(),
    { knowledgeStepCount: 0 },
);
const emit = defineEmits<{
    change: [counts: Record<KnowledgeDiscipline, number>];
}>();
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const counts = reactive<Record<KnowledgeDiscipline, number>>({ banking: 0, law: 0, engineering: 0, medicine: 0 });
const knowledgeCounts = ref<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const images: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};
const assignedCount = computed(() => Object.values(counts).reduce((sum, count) => sum + count, 0));
const remainingCount = computed(() => props.bookCount - assignedCount.value);
const title = computed(() =>
    props.knowledgeStepCount > 0 ? 'Распределите полученную награду' : 'Выберите получаемые книги',
);
const isComplete = computed(
    () =>
        remainingCount.value === 0 &&
        Object.values(knowledgeCounts.value).reduce((total, count) => total + count, 0) === props.knowledgeStepCount,
);

function maximumFor(discipline: KnowledgeDiscipline): number {
    return counts[discipline] + remainingCount.value;
}

watch(counts, () => emit('change', { ...counts }), { deep: true, immediate: true });
</script>

<template>
    <Card
        class="mx-auto w-full max-w-3xl border-none bg-background/0 p-0 shadow-none"
        :class="{ 'bg-background/50 shadow-none': !embedded }"
    >
        <CardHeader v-if="bookCount > 0" class="gap-0.5 px-4 py-3">
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
                        class="grid justify-items-center gap-1 rounded-md bg-background/40 px-2 py-1.5 text-xs font-medium"
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
                <template v-for="discipline in disciplines" :key="`values-${discipline}`">
                    <input type="hidden" :name="`book_counts[${discipline}]`" :value="counts[discipline]" />
                    <input
                        type="hidden"
                        :name="`knowledge_counts[${discipline}]`"
                        :value="knowledgeCounts[discipline]"
                    />
                </template>
                <div v-if="bookCount > 0" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div
                        v-for="discipline in disciplines"
                        :key="discipline"
                        class="grid justify-items-center gap-1 rounded-md border bg-background/40 px-2 py-1.5 text-xs font-medium"
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
                <KnowledgeStepDistributionPanel
                    v-if="knowledgeStepCount > 0"
                    :step-count="knowledgeStepCount"
                    :discipline-names="disciplineNames"
                    :errors="errors"
                    @change="knowledgeCounts = $event"
                />
                <div class="grid justify-items-end gap-1.5">
                    <InputError :message="errors.book_counts ?? errors.game" />
                    <Button type="submit" size="sm" :disabled="processing || !isComplete">
                        {{ processing ? 'Подтверждение…' : 'Подтвердить распределение' }}
                    </Button>
                </div>
            </Form>
        </CardContent>
    </Card>
</template>
