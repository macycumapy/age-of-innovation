<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed, reactive, ref, watch } from 'vue';
import RewardDistributionController from '@/actions/App/Http/Controllers/RewardDistributionController';
import CompetencySelector from '@/components/game/CompetencySelector.vue';
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
import { NumberStepper } from '@/components/ui/number-stepper';
import type { Competency, KnowledgeDiscipline } from '@/types';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';
import bankingKnowledgeUrl from '../../../images/token_parts/coin_round.png';
import engineeringKnowledgeUrl from '../../../images/token_parts/engineering_round.png';
import lawKnowledgeUrl from '../../../images/token_parts/law_round.png';
import medicineKnowledgeUrl from '../../../images/token_parts/medicine_round.png';

type RewardDistributionType =
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
        type?: RewardDistributionType;
        disciplineNames: Record<KnowledgeDiscipline, string>;
        competencyDescriptions: Record<Competency, string>;
        competencyIds?: Competency[];
        competencyBoardOrder: Competency[];
        requiresConfirmation?: boolean;
    }>(),
    { knowledgeStepCount: 0, competencyIds: () => [], requiresConfirmation: false },
);
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const counts = reactive<Record<KnowledgeDiscipline, number>>({ banking: 0, law: 0, engineering: 0, medicine: 0 });
const knowledgeCounts = ref<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const selectedCompetencyId = ref<Competency | null>(null);
const images: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};
const knowledgeImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingKnowledgeUrl,
    law: lawKnowledgeUrl,
    engineering: engineeringKnowledgeUrl,
    medicine: medicineKnowledgeUrl,
};
const assignedCount = computed(() => Object.values(counts).reduce((sum, count) => sum + count, 0));
const remainingCount = computed(() => props.bookCount - assignedCount.value);
const assignedKnowledgeCount = computed(() =>
    Object.values(knowledgeCounts.value).reduce((total, count) => total + count, 0),
);
const remainingKnowledgeCount = computed(() => props.knowledgeStepCount - assignedKnowledgeCount.value);
const isComplete = computed(
    () =>
        remainingCount.value === 0 &&
        remainingKnowledgeCount.value === 0 &&
        (props.competencyIds.length === 0 || selectedCompetencyId.value !== null),
);

function maximumFor(discipline: KnowledgeDiscipline): number {
    return counts[discipline] + remainingCount.value;
}

function maximumKnowledgeFor(discipline: KnowledgeDiscipline): number {
    return knowledgeCounts.value[discipline] + remainingKnowledgeCount.value;
}

watch(
    () => props.competencyIds,
    (competencyIds) => {
        selectedCompetencyId.value = competencyIds[0] ?? null;
    },
    { immediate: true },
);
</script>

<template>
    <Card class="mx-auto max-w-3xl border-none bg-background/50 p-0 shadow-none">
        <CardContent class="px-4 py-3">
            <Form
                v-bind="RewardDistributionController.form(gameId)"
                :id="`reward-distribution-${gameId}`"
                class="grid gap-4"
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
                <section v-if="bookCount > 0" class="grid gap-3">
                    <div class="grid gap-0.5">
                        <h3 class="text-base font-semibold">Выберите книги</h3>
                        <p class="text-xs text-muted-foreground">
                            Распределите все полученные книги по дисциплинам. Осталось: {{ remainingCount }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div
                            v-for="discipline in disciplines"
                            :key="`book-${discipline}`"
                            class="grid justify-items-center gap-1 rounded-md border bg-background/40 px-2 py-1.5 text-xs font-medium"
                            :title="disciplineNames[discipline]"
                        >
                            <img
                                :src="images[discipline]"
                                :alt="disciplineNames[discipline]"
                                class="size-10 shrink-0 object-contain"
                            />
                            <NumberStepper v-model="counts[discipline]" :min="0" :max="maximumFor(discipline)" />
                        </div>
                    </div>
                    <InputError :message="errors.book_counts ?? errors.game" />
                </section>
                <section v-if="knowledgeStepCount > 0" class="grid gap-3">
                    <div class="grid gap-0.5">
                        <h3 class="text-base font-semibold">Выберите продвижения по дисциплинам</h3>
                        <p class="text-xs text-muted-foreground">
                            Распределите все полученные шаги знаний. Осталось: {{ remainingKnowledgeCount }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div
                            v-for="discipline in disciplines"
                            :key="`knowledge-${discipline}`"
                            class="grid justify-items-center gap-1 rounded-md border bg-background/40 px-2 py-1.5 text-xs font-medium"
                            :title="disciplineNames[discipline]"
                        >
                            <img
                                :src="knowledgeImages[discipline]"
                                :alt="disciplineNames[discipline]"
                                class="size-10 shrink-0 object-contain"
                            />
                            <NumberStepper
                                v-model="knowledgeCounts[discipline]"
                                :min="0"
                                :max="maximumKnowledgeFor(discipline)"
                            />
                        </div>
                    </div>
                    <InputError :message="errors.knowledge_counts ?? errors.game" />
                </section>
                <section v-if="competencyIds.length > 0" class="grid gap-3">
                    <div class="grid gap-0.5">
                        <h3 class="text-base font-semibold">Выберите компетенцию</h3>
                        <p class="text-xs text-muted-foreground">Выберите одну из доступных компетенций.</p>
                    </div>
                    <input type="hidden" name="competency_id" :value="selectedCompetencyId ?? ''" />
                    <CompetencySelector
                        v-model="selectedCompetencyId"
                        :competencies="competencyIds"
                        :board-order="competencyBoardOrder"
                        :descriptions="competencyDescriptions"
                        :disabled="processing"
                    />
                    <InputError :message="errors.competency_id ?? errors.game" />
                </section>
                <div class="grid justify-items-end gap-1.5">
                    <InputError :message="errors.game" />
                    <Button v-if="!requiresConfirmation" type="submit" size="sm" :disabled="processing || !isComplete">
                        {{ processing ? 'Подтверждение…' : 'Подтвердить распределение' }}
                    </Button>
                    <Dialog v-else>
                        <DialogTrigger as-child>
                            <Button type="button" size="sm" :disabled="processing || !isComplete">
                                Подтвердить выбор
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Подтвердите распределение дохода</DialogTitle>
                                <DialogDescription>Проверьте выбранные ресурсы перед сохранением.</DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child
                                    ><Button type="button" variant="outline">Отмена</Button></DialogClose
                                >
                                <Button type="submit" :form="`reward-distribution-${gameId}`" :disabled="processing">
                                    {{ processing ? 'Сохранение…' : 'Подтвердить' }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </Form>
        </CardContent>
    </Card>
</template>
