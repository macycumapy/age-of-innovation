<script setup lang="ts">
import { computed, ref } from 'vue';
import StartingResourcesController from '@/actions/App/Http/Controllers/StartingResourcesController';
import BookDistributionPanel from '@/components/game/BookDistributionPanel.vue';
import Form from '@/components/game/GameActionForm.vue';
import KnowledgeStepDistributionPanel from '@/components/game/KnowledgeStepDistributionPanel.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { KnowledgeDiscipline, PendingInteraction } from '@/types';

type StartingResourcesInteraction = Extract<PendingInteraction, { type: 'choose_starting_resources' }>;

const props = defineProps<{
    gameId: number;
    interaction: StartingResourcesInteraction;
    disciplineNames: Record<KnowledgeDiscipline, string>;
}>();

const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const emptyCounts = (): Record<KnowledgeDiscipline, number> => ({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const bookCounts = ref(emptyCounts());
const knowledgeCounts = ref(emptyCounts());
const assignedBookCount = computed(() => Object.values(bookCounts.value).reduce((total, count) => total + count, 0));
const remainingBookCount = computed(() => Math.max(0, props.interaction.context.bookCount - assignedBookCount.value));
const assignedKnowledgeCount = computed(() =>
    Object.values(knowledgeCounts.value).reduce((total, count) => total + count, 0),
);
const remainingKnowledgeCount = computed(() =>
    Math.max(0, props.interaction.context.knowledgeStepCount - assignedKnowledgeCount.value),
);
</script>

<template>
    <Form
        v-bind="StartingResourcesController.store.form(gameId)"
        #default="{ errors, processing }"
        class="mb-6 grid w-xl gap-5 rounded-xl"
    >
        <template v-for="discipline in disciplines" :key="discipline">
            <input
                v-if="interaction.context.bookCount > 0"
                type="hidden"
                :name="`book_counts[${discipline}]`"
                :value="bookCounts[discipline]"
            />
            <input
                v-if="interaction.context.knowledgeStepCount > 0"
                type="hidden"
                :name="`knowledge_counts[${discipline}]`"
                :value="knowledgeCounts[discipline]"
            />
        </template>

        <BookDistributionPanel
            v-if="interaction.context.bookCount > 0"
            embedded
            :game-id="gameId"
            :book-count="interaction.context.bookCount"
            :discipline-names="disciplineNames"
            :errors="errors"
            @change="bookCounts = $event"
        />
        <KnowledgeStepDistributionPanel
            v-if="interaction.context.knowledgeStepCount > 0"
            :step-count="interaction.context.knowledgeStepCount"
            :discipline-names="disciplineNames"
            :errors="errors"
            @change="knowledgeCounts = $event"
        />

        <InputError :message="errors.game" />
        <Button
            type="submit"
            class="justify-self-end"
            :disabled="processing || remainingBookCount !== 0 || remainingKnowledgeCount !== 0"
        >
            {{ processing ? 'Сохранение…' : 'Подтвердить выбор' }}
        </Button>
    </Form>
</template>
