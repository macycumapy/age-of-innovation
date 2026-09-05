<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';
import PassController from '@/actions/App/Http/Controllers/PassController';
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
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { RoundBonus, RoundBonusOffer } from '@/types';
import goldMedallionUrl from '../../../images/token_parts/gold_medallion.png';

defineProps<{
    gameId: number;
    offers: RoundBonusOffer[];
    descriptions: Record<RoundBonus, string>;
    availableActions: string[];
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const selectedRoundBonus = ref<RoundBonus | null>(null);
const initialFocusTarget = ref<HTMLElement | null>(null);
const roundBonusImages = import.meta.glob<string>('../../../images/round_bonus_cards/*_top.png', {
    eager: true,
    import: 'default',
    query: '?url',
});

watch(isOpen, (open) => {
    if (open) {
        selectedRoundBonus.value = null;
    }
});

function roundBonusImage(roundBonus: RoundBonus): string {
    return roundBonusImages[`../../../images/round_bonus_cards/${roundBonus}_top.png`];
}

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
                <input type="hidden" name="round_bonus" :value="selectedRoundBonus ?? ''" />
                <DialogHeader>
                    <DialogTitle><span ref="initialFocusTarget" tabindex="-1">Спасовать?</span></DialogTitle>
                    <DialogDescription>
                        Выберите новый жетон бонуса раунда. Текущий жетон вернётся в общий пул.
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

                <TooltipProvider :delay-duration="150">
                    <div class="grid grid-cols-3 gap-2">
                        <Tooltip v-for="offer in offers" :key="offer.roundBonus">
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    class="relative rounded-lg border-2 p-1 transition-colors"
                                    :class="
                                        selectedRoundBonus === offer.roundBonus
                                            ? 'border-primary ring-2 ring-primary'
                                            : 'border-muted'
                                    "
                                    :aria-pressed="selectedRoundBonus === offer.roundBonus"
                                    @click="selectedRoundBonus = offer.roundBonus"
                                >
                                    <img
                                        :src="roundBonusImage(offer.roundBonus)"
                                        :alt="`Бонус раунда ${offer.roundBonus}`"
                                        class="h-auto w-full rounded-md"
                                    />
                                    <span
                                        v-if="offer.coins > 0"
                                        class="absolute top-3 right-3 grid size-9 place-items-center"
                                    >
                                        <img :src="goldMedallionUrl" alt="" class="absolute size-full" />
                                        <span class="relative text-sm font-bold text-amber-950">{{ offer.coins }}</span>
                                    </span>
                                </button>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs">{{ descriptions[offer.roundBonus] }}</TooltipContent>
                        </Tooltip>
                    </div>
                </TooltipProvider>

                <InputError :message="errors.round_bonus ?? errors.game" />
                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing || selectedRoundBonus === null">
                        Подтвердить пас
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
