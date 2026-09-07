<script setup lang="ts">
import { computed, ref } from 'vue';
import StartingResourcesController from '@/actions/App/Http/Controllers/StartingResourcesController';
import BookDistributionPanel from '@/components/game/BookDistributionPanel.vue';
import Form from '@/components/game/GameActionForm.vue';
import KnowledgeStepDistributionPanel from '@/components/game/KnowledgeStepDistributionPanel.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { KnowledgeDiscipline, PendingInteraction } from '@/types';

type IncomeInteraction = Extract<PendingInteraction, { type: 'choose_starting_resources' }>;

const props = defineProps<{
    gameId: number;
    interaction: IncomeInteraction;
    disciplineNames: Record<KnowledgeDiscipline, string>;
}>();

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
    <Card class="mx-auto w-full max-w-5xl border-0 p-0">
        <CardContent>
            <Form
                v-bind="StartingResourcesController.store.form(gameId)"
                id="income-distribution-form"
                #default="{ errors, processing }"
                class="grid gap-5"
            >
                <template v-for="discipline in interaction.optionIds" :key="discipline">
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
                <Dialog>
                    <DialogTrigger as-child>
                        <Button
                            type="button"
                            class="justify-self-end"
                            :disabled="processing || remainingBookCount !== 0 || remainingKnowledgeCount !== 0"
                        >
                            Подтвердить выбор
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Подтвердите распределение дохода</DialogTitle>
                            <DialogDescription>Проверьте выбранные ресурсы перед сохранением.</DialogDescription>
                        </DialogHeader>
                        <div class="grid gap-4 rounded-lg bg-muted p-4 text-sm">
                            <div v-if="interaction.context.bookCount > 0" class="grid gap-1">
                                <p class="font-medium">Книги</p>
                                <p
                                    v-for="discipline in interaction.optionIds"
                                    v-show="bookCounts[discipline] > 0"
                                    :key="`book-${discipline}`"
                                    class="text-muted-foreground"
                                >
                                    {{ disciplineNames[discipline] }}: {{ bookCounts[discipline] }}
                                </p>
                            </div>
                            <div v-if="interaction.context.knowledgeStepCount > 0" class="grid gap-1">
                                <p class="font-medium">Шаги знаний</p>
                                <p
                                    v-for="discipline in interaction.optionIds"
                                    v-show="knowledgeCounts[discipline] > 0"
                                    :key="`knowledge-${discipline}`"
                                    class="text-muted-foreground"
                                >
                                    {{ disciplineNames[discipline] }}: {{ knowledgeCounts[discipline] }}
                                </p>
                            </div>
                        </div>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child><Button type="button" variant="outline">Отмена</Button></DialogClose>
                            <Button type="submit" form="income-distribution-form" :disabled="processing">
                                {{ processing ? 'Сохранение…' : 'Подтвердить' }}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </Form>
        </CardContent>
    </Card>
</template>
