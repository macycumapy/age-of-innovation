<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed, ref, watch } from 'vue';
import FactionActionController from '@/actions/App/Http/Controllers/FactionActionController';
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
import type { Faction, KnowledgeDiscipline } from '@/types';
import bankingUrl from '../../../images/token_parts/coin_book.png';
import engineeringUrl from '../../../images/token_parts/engineering_book.png';
import lawUrl from '../../../images/token_parts/law_book.png';
import medicineUrl from '../../../images/token_parts/medicine_book.png';

const props = defineProps<{
    gameId: number;
    faction: Faction | null;
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
const requiresBook = computed(() => props.faction === 'philosophers');
const isImplementedFactionAction = computed(() =>
    props.faction !== null && ['moles', 'philosophers', 'psychics'].includes(props.faction),
);
const canSubmit = computed(
    () => isImplementedFactionAction.value && (!requiresBook.value || selectedDiscipline.value !== null),
);
const actionQuestion = computed(() => {
    const questions: Partial<Record<Faction, string>> = {
        philosophers: 'Получить выбранную книгу?',
        moles: 'Заплатить 1 инструмент и построить мост?',
        psychics: 'Получить 5 Силы и выполнить дополнительное действие?',
    };

    return props.faction === null ? '' : (questions[props.faction] ?? '');
});

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
                v-bind="FactionActionController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="actionSucceeded"
            >
                <input type="hidden" name="discipline" :value="selectedDiscipline ?? ''" />

                <DialogHeader>
                    <DialogTitle>Выполнить действие расы?</DialogTitle>
                    <DialogDescription>{{ actionQuestion }}</DialogDescription>
                </DialogHeader>

                <div v-if="requiresBook" class="grid grid-cols-4 gap-2">
                    <button
                        v-for="discipline in disciplines"
                        :key="discipline"
                        type="button"
                        class="grid gap-1 rounded-lg border-2 border-muted p-2 text-center text-xs transition-colors"
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

                <InputError :message="errors.discipline ?? errors.faction ?? errors.game" />

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
