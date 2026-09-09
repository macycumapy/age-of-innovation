<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed, reactive, ref, watch } from 'vue';
import PalaceActionController from '@/actions/App/Http/Controllers/PalaceActionController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { NumberStepper } from '@/components/ui/number-stepper';
import type { KnowledgeDiscipline, PalaceAbility } from '@/types';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import bankingRoundUrl from '../../../images/token_parts/coin_round.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import engineeringRoundUrl from '../../../images/token_parts/engineering_round.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import lawRoundUrl from '../../../images/token_parts/law_round.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';
import medicineRoundUrl from '../../../images/token_parts/medicine_round.png';

const props = defineProps<{
    gameId: number;
    palace: PalaceAbility | null;
    selectedHexId?: string | null;
    disciplineNames: Record<KnowledgeDiscipline, string>;
}>();
const isOpen = defineModel<boolean>('open', { required: true });
const discipline = ref<KnowledgeDiscipline | null>(null);
const knowledgeSteps = reactive<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const hexId = ref<string | null>(null);
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const bookImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};
const roundImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingRoundUrl,
    law: lawRoundUrl,
    engineering: engineeringRoundUrl,
    medicine: medicineRoundUrl,
};
const actionQuestions: Partial<Record<PalaceAbility, string>> = {
    palace_01: 'Получить 2 инструмента?',
    palace_02: 'Получить 2 лопаты для преобразования и строительства?',
    palace_03: 'Заменить выбранную школу рынком и получить 3 ПО и инструмент?',
    palace_04: 'Бесплатно улучшить выбранный дом до рынка?',
    palace_06: 'Распределите 2 шага между любыми дисциплинами.',
    palace_13: 'Получить 3 золота и выбранную книгу?',
};
const needsDiscipline = computed(() => props.palace === 'palace_13');
const distributesKnowledge = computed(() => props.palace === 'palace_06');
const assignedKnowledgeSteps = computed(() => Object.values(knowledgeSteps).reduce((sum, steps) => sum + steps, 0));
const remainingKnowledgeSteps = computed(() => 2 - assignedKnowledgeSteps.value);
const sourceBuilding = computed(() =>
    props.palace === 'palace_03' ? 'school' : props.palace === 'palace_04' ? 'workshop' : null,
);
const canSubmit = computed(
    () =>
        (!needsDiscipline.value || discipline.value !== null) &&
        (!distributesKnowledge.value || remainingKnowledgeSteps.value === 0) &&
        (sourceBuilding.value === null || hexId.value !== null),
);
const question = computed(() => actionQuestions[props.palace ?? 'palace_01'] ?? 'Выполнить действие жетона Дворца?');

watch(isOpen, (open) => {
    if (open) {
        discipline.value = null;
        hexId.value = props.selectedHexId ?? null;

        for (const item of disciplines) {
            knowledgeSteps[item] = 0;
        }
    }
});

function image(disciplineId: KnowledgeDiscipline): string {
    return props.palace === 'palace_13' ? bookImages[disciplineId] : roundImages[disciplineId];
}

function maximumKnowledgeSteps(disciplineId: KnowledgeDiscipline): number {
    return knowledgeSteps[disciplineId] + remainingKnowledgeSteps.value;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-2xl">
            <Form
                v-bind="PalaceActionController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="isOpen = false"
            >
                <input type="hidden" name="discipline" :value="discipline ?? ''" />
                <input
                    v-for="item in disciplines"
                    :key="`knowledge-${item}`"
                    type="hidden"
                    :name="`knowledge_steps[${item}]`"
                    :value="knowledgeSteps[item]"
                />
                <input type="hidden" name="hex_id" :value="hexId ?? ''" />
                <DialogHeader>
                    <DialogTitle>Выполнить действие Дворца?</DialogTitle>
                    <DialogDescription>{{ question }}</DialogDescription>
                </DialogHeader>
                <div v-if="needsDiscipline" class="grid grid-cols-4 gap-2">
                    <button
                        v-for="item in disciplines"
                        :key="item"
                        type="button"
                        class="grid gap-1 rounded-lg border-2 p-2 text-xs"
                        :class="discipline === item ? 'border-primary ring-2 ring-primary' : 'border-muted'"
                        :aria-pressed="discipline === item"
                        @click="discipline = item"
                    >
                        <img
                            :src="image(item)"
                            :alt="disciplineNames[item]"
                            class="mx-auto size-14 object-contain"
                        /><span>{{ disciplineNames[item] }}</span>
                    </button>
                </div>
                <div v-if="distributesKnowledge" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <label
                        v-for="item in disciplines"
                        :key="item"
                        class="grid justify-items-center gap-2 rounded-lg border-2 border-muted p-2 text-xs"
                    >
                        <img :src="roundImages[item]" :alt="disciplineNames[item]" class="size-14 object-contain" />
                        <span>{{ disciplineNames[item] }}</span>
                        <NumberStepper v-model="knowledgeSteps[item]" :min="0" :max="maximumKnowledgeSteps(item)" />
                    </label>
                </div>
                <p
                    v-if="['palace_03', 'palace_04'].includes(palace ?? '') && hexId"
                    class="text-sm text-muted-foreground"
                >
                    {{ palace === 'palace_03' ? 'Выбрана школа' : 'Выбран дом' }} на ячейке {{ hexId }}.
                </p>
                <InputError
                    :message="
                        errors.knowledge_steps ?? errors.discipline ?? errors.hex_id ?? errors.palace ?? errors.game
                    "
                />
                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing || !canSubmit">Подтвердить действие</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
