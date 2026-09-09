<script setup lang="ts">
import { computed, ref } from 'vue';
import BookDistributionController from '@/actions/App/Http/Controllers/BookDistributionController';
import BookDistributionPanel from '@/components/game/BookDistributionPanel.vue';
import Form from '@/components/game/GameActionForm.vue';
import KnowledgeStepDistributionPanel from '@/components/game/KnowledgeStepDistributionPanel.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { KnowledgeDiscipline } from '@/types';

const props = defineProps<{
    gameId: number;
    bookCount: number;
    knowledgeStepCount: number;
    disciplineNames: Record<KnowledgeDiscipline, string>;
    canRestartCurrentTurn: boolean;
}>();

const emptyCounts = (): Record<KnowledgeDiscipline, number> => ({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const bookCounts = ref(emptyCounts());
const knowledgeCounts = ref(emptyCounts());
const isComplete = computed(
    () =>
        Object.values(bookCounts.value).reduce((total, count) => total + count, 0) === props.bookCount &&
        Object.values(knowledgeCounts.value).reduce((total, count) => total + count, 0) === props.knowledgeStepCount,
);
</script>

<template>
    <Card class="mx-auto w-full max-w-5xl border-none p-0">
        <CardContent class="pt-4">
            <Form v-bind="BookDistributionController.form(gameId)" class="grid gap-4" #default="{ errors, processing }">
                <template v-for="discipline in disciplines" :key="discipline">
                    <input type="hidden" :name="`book_counts[${discipline}]`" :value="bookCounts[discipline]" />
                    <input
                        type="hidden"
                        :name="`knowledge_counts[${discipline}]`"
                        :value="knowledgeCounts[discipline]"
                    />
                </template>

                <BookDistributionPanel
                    embedded
                    :game-id="gameId"
                    :book-count="bookCount"
                    :discipline-names="disciplineNames"
                    :errors="errors"
                    @change="bookCounts = $event"
                />
                <KnowledgeStepDistributionPanel
                    :step-count="knowledgeStepCount"
                    :discipline-names="disciplineNames"
                    :errors="errors"
                    @change="knowledgeCounts = $event"
                />

                <Button type="submit" class="justify-self-end" :disabled="processing || !isComplete">
                    {{ processing ? 'Подтверждение…' : 'Подтвердить распределение' }}
                </Button>
            </Form>
        </CardContent>
    </Card>
</template>
