<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import ScienceBonusBooksController from '@/actions/App/Http/Controllers/ScienceBonusBooksController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { NumberStepper } from '@/components/ui/number-stepper';
import type { KnowledgeDiscipline } from '@/types';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';

const props = defineProps<{
    gameId: number;
    bookCount: number;
    disciplineNames: Record<KnowledgeDiscipline, string>;
}>();
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const counts = reactive<Record<KnowledgeDiscipline, number>>({ banking: 0, law: 0, engineering: 0, medicine: 0 });
const images: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};
const assignedCount = computed(() => Object.values(counts).reduce((sum, count) => sum + count, 0));
const remainingCount = computed(() => props.bookCount - assignedCount.value);

function maximumFor(discipline: KnowledgeDiscipline): number {
    return counts[discipline] + remainingCount.value;
}
</script>

<template>
    <Dialog :open="true">
        <DialogContent class="sm:max-w-lg" :show-close-button="false">
            <Form v-bind="ScienceBonusBooksController.form(gameId)" class="contents" #default="{ errors, processing }">
                <DialogHeader>
                    <DialogTitle>Научный бонус: выберите книги</DialogTitle>
                    <DialogDescription>Распределите все полученные книги по дисциплинам. Осталось: {{ remainingCount }}</DialogDescription>
                </DialogHeader>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <label v-for="discipline in disciplines" :key="discipline" class="grid gap-2 rounded-lg border p-2 text-center text-xs font-medium">
                        <img :src="images[discipline]" :alt="disciplineNames[discipline]" class="mx-auto size-12 object-contain" />
                        <span>{{ disciplineNames[discipline] }}</span>
                        <NumberStepper v-model="counts[discipline]" :min="0" :max="maximumFor(discipline)" />
                        <input type="hidden" :name="`book_counts[${discipline}]`" :value="counts[discipline]" />
                    </label>
                </div>
                <InputError :message="errors.book_counts ?? errors.game" />
                <DialogFooter>
                    <Button type="submit" :disabled="processing || remainingCount !== 0">Подтвердить выбор</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
