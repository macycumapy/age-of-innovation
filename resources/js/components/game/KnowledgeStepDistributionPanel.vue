<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { NumberStepper } from '@/components/ui/number-stepper';
import type { KnowledgeDiscipline } from '@/types';
import bankingUrl from '../../../images/token_parts/coin_round.png';
import engineeringUrl from '../../../images/token_parts/engineering_round.png';
import lawUrl from '../../../images/token_parts/law_round.png';
import medicineUrl from '../../../images/token_parts/medicine_round.png';

const props = defineProps<{
    stepCount: number;
    disciplineNames: Record<KnowledgeDiscipline, string>;
    errors?: Record<string, string>;
}>();
const emit = defineEmits<{
    change: [counts: Record<KnowledgeDiscipline, number>];
}>();
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const counts = reactive<Record<KnowledgeDiscipline, number>>({ banking: 0, law: 0, engineering: 0, medicine: 0 });
const images: Record<KnowledgeDiscipline, string> = {
    banking: bankingUrl,
    law: lawUrl,
    engineering: engineeringUrl,
    medicine: medicineUrl,
};
const assignedCount = computed(() => Object.values(counts).reduce((sum, count) => sum + count, 0));
const remainingCount = computed(() => props.stepCount - assignedCount.value);

function maximumFor(discipline: KnowledgeDiscipline): number {
    return counts[discipline] + remainingCount.value;
}

watch(counts, () => emit('change', { ...counts }), { deep: true, immediate: true });
</script>

<template>
    <Card class="mx-auto w-full max-w-3xl border-none p-0 bg-card/0">
        <CardHeader class="gap-0.5 px-4 py-3">
            <CardTitle class="text-base">Выберите продвижения по дисциплинам</CardTitle>
            <CardDescription class="text-xs">
                Распределите все полученные шаги знаний. Осталось: {{ remainingCount }}
            </CardDescription>
        </CardHeader>
        <CardContent class="grid gap-3 px-4 pb-3">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div
                    v-for="discipline in disciplines"
                    :key="discipline"
                    class="grid justify-items-center gap-1 rounded-md border px-2 py-1.5 text-xs font-medium bg-background/40"
                    :title="disciplineNames[discipline]"
                >
                    <img
                        :src="images[discipline]"
                        :alt="disciplineNames[discipline]"
                        class="size-8 shrink-0 object-contain"
                    />
                    <NumberStepper v-model="counts[discipline]" :min="0" :max="maximumFor(discipline)" />
                </div>
            </div>
            <InputError :message="errors?.knowledge_counts ?? errors?.game" />
        </CardContent>
    </Card>
</template>
