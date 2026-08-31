<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import RoundBonusActionController from '@/actions/App/Http/Controllers/RoundBonusActionController';
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
import type { KnowledgeDiscipline, RoundBonus } from '@/types';
import bankingUrl from '../../../images/token_parts/coin_round.png';
import engineeringUrl from '../../../images/token_parts/engineering_round.png';
import lawUrl from '../../../images/token_parts/law_round.png';
import medicineUrl from '../../../images/token_parts/medicine_round.png';

const props = defineProps<{
    gameId: number;
    roundBonus: RoundBonus | null;
    description: string;
    disciplineNames: Record<KnowledgeDiscipline, string>;
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const selectedDiscipline = ref<KnowledgeDiscipline | null>(null);
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const disciplineImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingUrl,
    law: lawUrl,
    engineering: engineeringUrl,
    medicine: medicineUrl,
};
const requiresDiscipline = computed(() => props.roundBonus === 'knowledge');
const canSubmit = computed(() => !requiresDiscipline.value || selectedDiscipline.value !== null);

watch(isOpen, (open) => {
    if (open) {
        selectedDiscipline.value = null;
    }
});

function actionSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-bind="RoundBonusActionController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="actionSucceeded"
            >
                <input type="hidden" name="discipline" :value="selectedDiscipline ?? ''" />

                <DialogHeader>
                    <DialogTitle>Использовать бонус раунда?</DialogTitle>
                    <DialogDescription>{{ description }}</DialogDescription>
                </DialogHeader>

                <div v-if="requiresDiscipline" class="grid grid-cols-4 gap-2">
                    <button
                        v-for="discipline in disciplines"
                        :key="discipline"
                        type="button"
                        class="relative grid gap-1 rounded-lg border-2 border-muted p-2 text-center text-xs transition-colors"
                        :class="selectedDiscipline === discipline
                            ? 'border-primary ring-2 ring-primary ring-offset-1 ring-offset-background'
                            : 'hover:border-muted-foreground/40'"
                        :aria-pressed="selectedDiscipline === discipline"
                        @click="selectedDiscipline = discipline"
                    >
                        <img :src="disciplineImages[discipline]" :alt="disciplineNames[discipline]" class="mx-auto size-14 object-contain" />
                        <span>{{ disciplineNames[discipline] }}</span>
                    </button>
                </div>

                <InputError :message="errors.discipline ?? errors.round_bonus ?? errors.game" />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing || !canSubmit">
                        {{ processing ? 'Выполнение…' : 'Подтвердить действие' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
