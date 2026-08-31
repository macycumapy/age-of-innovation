<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import BookActionController from '@/actions/App/Http/Controllers/BookActionController';
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
import type {
    BoardState,
    BookActionState,
    GamePlayerBoardState,
    KnowledgeDiscipline,
} from '@/types';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import bankingCultUrl from '../../../images/token_parts/coin_round.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import engineeringCultUrl from '../../../images/token_parts/engineering_round.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import lawCultUrl from '../../../images/token_parts/law_round.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';
import medicineCultUrl from '../../../images/token_parts/medicine_round.png';

type BookType = KnowledgeDiscipline;

const props = defineProps<{
    gameId: number;
    action: BookActionState | null;
    playerState?: GamePlayerBoardState;
    board: BoardState;
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const selectedDiscipline = ref<KnowledgeDiscipline | null>(null);
const selectedHexId = ref<string | null>(null);
const bookCounts = reactive<Record<BookType, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const bookTypes: BookType[] = ['banking', 'law', 'engineering', 'medicine'];
const disciplineNames: Record<KnowledgeDiscipline, string> = {
    banking: 'Банковское дело',
    law: 'Право',
    engineering: 'Инженерное дело',
    medicine: 'Медицина',
};
const disciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const disciplineImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingCultUrl,
    law: lawCultUrl,
    engineering: engineeringCultUrl,
    medicine: medicineCultUrl,
};
const bookNames: Record<BookType, string> = disciplineNames;
const bookImages: Record<BookType, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};
const selectedBookCount = computed(() => Object.values(bookCounts).reduce((sum, count) => sum + count, 0));
const workshopHexIds = computed(() => props.board.hexes
    .filter((hex) => hex.building?.ownerPlayerId === props.playerState?.playerId
        && hex.building.type === 'workshop'
        && !hex.building.isNeutral)
    .map((hex) => hex.id));
const hasRequiredChoice = computed(() => {
    if (props.action?.id === 'advance_knowledge') {
        return selectedDiscipline.value !== null;
    }

    if (props.action?.id === 'upgrade_to_guild') {
        return selectedHexId.value !== null;
    }

    return true;
});
const canConfirm = computed(() => props.action !== null
    && selectedBookCount.value === props.action.cost
    && hasRequiredChoice.value);

watch([isOpen, () => props.action], ([open]) => {
    if (!open) {
        return;
    }

    selectedDiscipline.value = null;
    selectedHexId.value = null;
    let remaining = props.action?.cost ?? 0;

    for (const bookType of bookTypes) {
        const selected = Math.min(props.playerState?.books[bookType] ?? 0, remaining);
        bookCounts[bookType] = selected;
        remaining -= selected;
    }
});

function maximumBookCount(bookType: BookType): number {
    return Math.min(
        props.playerState?.books[bookType] ?? 0,
        bookCounts[bookType] + Math.max(0, (props.action?.cost ?? 0) - selectedBookCount.value),
    );
}

function actionSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-3xl">
            <Form
                v-if="action"
                v-bind="BookActionController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="actionSucceeded"
            >
                <input type="hidden" name="action" :value="action.id" />
                <input type="hidden" name="discipline" :value="selectedDiscipline ?? ''" />
                <input type="hidden" name="hex_id" :value="selectedHexId ?? ''" />

                <DialogHeader>
                    <DialogTitle>Действие за книги</DialogTitle>
                    <DialogDescription>{{ action.description }}</DialogDescription>
                </DialogHeader>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="bookType in bookTypes" :key="bookType" class="grid justify-items-center gap-2 rounded-lg border p-3 text-center text-sm">
                        <img :src="bookImages[bookType]" :alt="bookNames[bookType]" class="h-12 w-auto object-contain" />
                        <span class="min-h-10">{{ bookNames[bookType] }}</span>
                        <NumberStepper
                            v-model="bookCounts[bookType]"
                            :name="`book_counts[${bookType}]`"
                            :min="0"
                            :max="maximumBookCount(bookType)"
                            :disabled="processing"
                        />
                        <span class="text-xs text-muted-foreground">Есть: {{ playerState?.books[bookType] ?? 0 }}</span>
                    </div>
                </div>

                <div v-if="action.id === 'advance_knowledge'" class="grid gap-2">
                    <p class="text-sm font-medium">Выберите дисциплину</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <Button
                            v-for="discipline in disciplines"
                            :key="discipline"
                            type="button"
                            variant="outline"
                            class="h-auto flex-col gap-2 bg-background py-3"
                            :class="selectedDiscipline === discipline ? 'border-primary ring-2 ring-primary/40' : ''"
                            :aria-label="disciplineNames[discipline]"
                            :aria-pressed="selectedDiscipline === discipline"
                            @click="selectedDiscipline = discipline"
                        >
                            <img
                                :src="disciplineImages[discipline]"
                                :alt="disciplineNames[discipline]"
                                class="h-16 w-auto object-contain drop-shadow-sm"
                            />
                            <span>{{ disciplineNames[discipline] }}</span>
                        </Button>
                    </div>
                </div>

                <div v-if="action.id === 'upgrade_to_guild'" class="grid gap-2">
                    <p class="text-sm font-medium">Выберите мастерскую</p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="hexId in workshopHexIds"
                            :key="hexId"
                            type="button"
                            :variant="selectedHexId === hexId ? 'default' : 'outline'"
                            @click="selectedHexId = hexId"
                        >
                            Ячейка {{ hexId }}
                        </Button>
                    </div>
                    <p v-if="workshopHexIds.length === 0" class="text-sm text-destructive">Нет доступных мастерских.</p>
                </div>

                <InputError :message="errors.book_counts ?? errors.discipline ?? errors.hex_id ?? errors.action ?? errors.game" />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing || !canConfirm">
                        {{ processing ? 'Выполнение…' : 'Подтвердить действие' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
