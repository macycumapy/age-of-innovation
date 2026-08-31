<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ScholarController from '@/actions/App/Http/Controllers/ScholarController';
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
import InputError from '@/components/InputError.vue';
import type { GamePlayerBoardState, KnowledgeDiscipline } from '@/types';
import bankingUrl from '../../../images/token_parts/coin_round.png';
import engineeringUrl from '../../../images/token_parts/engineering_round.png';
import lawUrl from '../../../images/token_parts/law_round.png';
import medicineUrl from '../../../images/token_parts/medicine_round.png';
import scholarUrl from '../../../images/token_parts/scholar.png';

const props = defineProps<{
    open: boolean;
    gameId: number;
    discipline: KnowledgeDiscipline | null;
    playerState?: GamePlayerBoardState;
    occupiedSlots: number;
    disciplineNames: Record<KnowledgeDiscipline, string>;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const placeScholar = ref(true);
const disciplineImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingUrl,
    law: lawUrl,
    engineering: engineeringUrl,
    medicine: medicineUrl,
};
const placementSteps = computed(() => props.occupiedSlots === 0 ? 3 : 2);

watch(() => props.open, (open) => {
    if (open) {
        placeScholar.value = props.occupiedSlots < 4;
    }
});
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Отправить учёного</DialogTitle>
                <DialogDescription v-if="discipline">
                    Выберите способ продвижения в дисциплине «{{ disciplineNames[discipline] }}».
                </DialogDescription>
            </DialogHeader>

            <Form
                v-if="discipline"
                v-bind="ScholarController.form(gameId)"
                #default="{ errors, processing }"
                class="grid gap-4"
                @success="emit('update:open', false)"
            >
                <input type="hidden" name="discipline" :value="discipline" />
                <input type="hidden" name="place" :value="placeScholar ? '1' : '0'" />

                <div class="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                    <img :src="disciplineImages[discipline]" :alt="disciplineNames[discipline]" class="size-14 object-contain" />
                    <div>
                        <p class="font-medium">Доступно учёных: {{ playerState?.scholars ?? 0 }}</p>
                        <p class="text-sm text-muted-foreground">
                            Фигурок в пуле: {{ playerState?.scholarPoolSize ?? 7 }} из 7
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="flex items-center gap-4 rounded-lg border p-4 text-left transition hover:border-primary"
                    :class="placeScholar ? 'border-primary ring-2 ring-primary/40' : ''"
                    :disabled="occupiedSlots >= 4"
                    @click="placeScholar = true"
                >
                    <img :src="scholarUrl" alt="" class="size-12 object-contain" />
                    <span>
                        <strong class="block">Установить на планшет</strong>
                        <span class="text-sm text-muted-foreground">
                            Убрать учёного из пула и продвинуться на {{ placementSteps }} шага.
                        </span>
                    </span>
                </button>

                <button
                    type="button"
                    class="flex items-center gap-4 rounded-lg border p-4 text-left transition hover:border-primary"
                    :class="!placeScholar ? 'border-primary ring-2 ring-primary/40' : ''"
                    @click="placeScholar = false"
                >
                    <img :src="scholarUrl" alt="" class="size-12 object-contain opacity-60" />
                    <span>
                        <strong class="block">Продвинуться без установки</strong>
                        <span class="text-sm text-muted-foreground">
                            Потратить доступного учёного, оставить его фигурку в пуле и продвинуться на 1 шаг.
                        </span>
                    </span>
                </button>

                <InputError :message="errors.scholar ?? errors.discipline ?? errors.place" />

                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing || (placeScholar && occupiedSlots >= 4)">
                        {{ processing ? 'Отправка…' : 'Подтвердить' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
