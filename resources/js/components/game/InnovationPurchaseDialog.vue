<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed, reactive, watch } from 'vue';
import InnovationController from '@/actions/App/Http/Controllers/InnovationController';
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
import type { GamePlayerBoardState, Innovation, InnovationPurchaseState, KnowledgeDiscipline } from '@/types';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';

const props = defineProps<{
    gameId: number;
    innovation: Innovation | null;
    purchaseState: InnovationPurchaseState | null;
    playerState?: GamePlayerBoardState;
    description: string;
    disciplineNames: Record<KnowledgeDiscipline, string>;
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const bookCounts = reactive<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const bookImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};
const selectedBookCount = computed(() => Object.values(bookCounts).reduce((sum, count) => sum + count, 0));
const hasRequiredBooks = computed(() =>
    disciplines.every((discipline) => bookCounts[discipline] >= (props.purchaseState?.requiredBooks[discipline] ?? 0)),
);
const hasAvailableBooks = computed(() =>
    disciplines.every((discipline) => bookCounts[discipline] <= (props.playerState?.books[discipline] ?? 0)),
);
const hasEnoughCoins = computed(() => (props.playerState?.coins ?? 0) >= (props.purchaseState?.coins ?? 0));
const canConfirm = computed(
    () =>
        props.innovation !== null &&
        props.purchaseState?.isAvailable === true &&
        selectedBookCount.value === props.purchaseState.totalBooks &&
        hasRequiredBooks.value &&
        hasAvailableBooks.value &&
        hasEnoughCoins.value,
);

watch([isOpen, () => props.purchaseState], ([open]) => {
    if (!open || props.purchaseState === null) {
        return;
    }

    let remainingBooks = props.purchaseState.totalBooks;

    for (const discipline of disciplines) {
        bookCounts[discipline] = props.purchaseState.requiredBooks[discipline];
        remainingBooks -= bookCounts[discipline];
    }

    for (const discipline of disciplines) {
        const availableForAnyCost = Math.max(0, (props.playerState?.books[discipline] ?? 0) - bookCounts[discipline]);
        const selected = Math.min(availableForAnyCost, remainingBooks);
        bookCounts[discipline] += selected;
        remainingBooks -= selected;
    }
});

function maximumBookCount(discipline: KnowledgeDiscipline): number {
    return Math.max(
        props.purchaseState?.requiredBooks[discipline] ?? 0,
        Math.min(
            props.playerState?.books[discipline] ?? 0,
            bookCounts[discipline] + Math.max(0, (props.purchaseState?.totalBooks ?? 0) - selectedBookCount.value),
        ),
    );
}

function purchaseSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-3xl">
            <Form
                v-if="innovation && purchaseState"
                v-bind="InnovationController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="purchaseSucceeded"
            >
                <input type="hidden" name="innovation" :value="innovation" />

                <DialogHeader>
                    <DialogTitle>Купить инновацию</DialogTitle>
                    <DialogDescription>{{ description }}</DialogDescription>
                </DialogHeader>

                <p class="text-sm">
                    Выберите {{ purchaseState.totalBooks }} книг. Цветные требования отмечены под счётчиками.
                    <span v-if="purchaseState.coins > 0"
                        >Дополнительно требуется 5 монет, так как Дворец не построен.</span
                    >
                </p>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div
                        v-for="discipline in disciplines"
                        :key="discipline"
                        class="grid justify-items-center gap-2 rounded-lg border p-3 text-center text-sm"
                    >
                        <img
                            :src="bookImages[discipline]"
                            :alt="disciplineNames[discipline]"
                            class="h-12 w-auto object-contain"
                        />
                        <span class="min-h-10">{{ disciplineNames[discipline] }}</span>
                        <NumberStepper
                            v-model="bookCounts[discipline]"
                            :min="purchaseState.requiredBooks[discipline]"
                            :max="maximumBookCount(discipline)"
                            :disabled="processing"
                        />
                        <input type="hidden" :name="`book_counts[${discipline}]`" :value="bookCounts[discipline]" />
                        <span class="text-xs text-muted-foreground">
                            Есть: {{ playerState?.books[discipline] ?? 0 }} · минимум:
                            {{ purchaseState.requiredBooks[discipline] }}
                        </span>
                    </div>
                </div>

                <p class="text-sm text-muted-foreground">
                    Выбрано: {{ selectedBookCount }} / {{ purchaseState.totalBooks }} книг · монеты:
                    {{ purchaseState.coins }}
                </p>
                <p v-if="!hasEnoughCoins" class="text-sm text-destructive">Недостаточно монет.</p>
                <p v-if="!hasAvailableBooks" class="text-sm text-destructive">Недостаточно книг нужных цветов.</p>
                <InputError :message="errors.book_counts ?? errors.coins ?? errors.innovation ?? errors.game" />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing || !canConfirm">
                        {{ processing ? 'Покупка…' : 'Подтвердить покупку' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
