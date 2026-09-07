<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import PassController from '@/actions/App/Http/Controllers/PassController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { NumberStepper } from '@/components/ui/number-stepper';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { KnowledgeDiscipline, RoundBonus } from '@/types';
import bankingRoundUrl from '../../../images/token_parts/coin_round.png';
import engineeringRoundUrl from '../../../images/token_parts/engineering_round.png';
import lawRoundUrl from '../../../images/token_parts/law_round.png';
import medicineRoundUrl from '../../../images/token_parts/medicine_round.png';

const props = defineProps<{
    gameId: number;
    availableActions: string[];
    isFinalRound: boolean;
    currentRoundBonus: RoundBonus | null;
    schoolCount: number;
    disciplineNames: Record<KnowledgeDiscipline, string>;
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const initialFocusTarget = ref<HTMLElement | null>(null);
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const knowledgeCounts = reactive<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const knowledgeImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingRoundUrl,
    law: lawRoundUrl,
    engineering: engineeringRoundUrl,
    medicine: medicineRoundUrl,
};
const assignedKnowledgeSteps = computed(() =>
    Object.values(knowledgeCounts).reduce((total, count) => total + count, 0),
);
const remainingKnowledgeSteps = computed(() => Math.max(0, props.schoolCount - assignedKnowledgeSteps.value));
watch(isOpen, (open) => {
    if (open) {
        for (const discipline of disciplines) {
            knowledgeCounts[discipline] = 0;
        }
    }
});

function focusDialogTitle(event: Event): void {
    event.preventDefault();
    void nextTick(() => initialFocusTarget.value?.focus());
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-lg" @open-auto-focus="focusDialogTitle">
            <Form
                v-bind="PassController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="isOpen = false"
            >
                <DialogHeader>
                    <DialogTitle><span ref="initialFocusTarget" tabindex="-1">Спасовать?</span></DialogTitle>
                    <DialogDescription>
                        <template v-if="isFinalRound"> Текущий жетон бонуса раунда вернётся в общий пул. </template>
                        <template v-else>Сначала будут начислены бонусы конца раунда.</template>
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="availableActions.length > 0"
                    class="rounded-lg border border-amber-500/50 bg-amber-500/10 px-3 py-2 text-sm"
                >
                    <p class="font-semibold">У вас ещё есть доступные действия:</p>
                    <p>{{ availableActions.join(', ') }}.</p>
                    <p class="mt-1 text-muted-foreground">После паса выполнить их в этом раунде уже не получится.</p>
                </div>

                <div v-if="currentRoundBonus === 'pass_school' && schoolCount > 0" class="grid gap-3">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <p class="font-medium">Распределите шаги знаний за школы</p>
                        <p class="rounded-md bg-muted px-3 py-1.5 font-medium">
                            Осталось: {{ remainingKnowledgeSteps }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <label
                            v-for="discipline in disciplines"
                            :key="discipline"
                            class="grid justify-items-center gap-2 rounded-lg border bg-background/70 p-2 text-center text-sm"
                        >
                            <img
                                :src="knowledgeImages[discipline]"
                                :alt="disciplineNames[discipline]"
                                class="h-12 w-auto object-contain"
                            />
                            <span>{{ disciplineNames[discipline] }}</span>
                            <NumberStepper
                                v-model="knowledgeCounts[discipline]"
                                :name="`knowledge_counts[${discipline}]`"
                                :min="0"
                                :max="knowledgeCounts[discipline] + remainingKnowledgeSteps"
                                required
                                class="w-24"
                            />
                        </label>
                    </div>
                    <InputError :message="errors.knowledge_counts" />
                </div>

                <InputError :message="errors.game" />
                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="
                            processing ||
                            (currentRoundBonus === 'pass_school' && assignedKnowledgeSteps !== schoolCount)
                        "
                    >
                        Подтвердить пас
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
