<script setup lang="ts">
import { ref } from 'vue';
import RoundBonusChoiceController from '@/actions/App/Http/Controllers/RoundBonusChoiceController';
import Form from '@/components/game/GameActionForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { RoundBonus, RoundBonusOffer } from '@/types';
import goldMedallionUrl from '../../../images/token_parts/gold_medallion.png';

defineProps<{
    gameId: number;
    offers: RoundBonusOffer[];
    optionIds: RoundBonus[];
    descriptions: Record<RoundBonus, string>;
}>();

const selectedRoundBonus = ref<RoundBonus | null>(null);
const images = import.meta.glob<string>('../../../images/round_bonus_cards/*_top.png', {
    eager: true,
    import: 'default',
    query: '?url',
});

function image(roundBonus: RoundBonus): string {
    return images[`../../../images/round_bonus_cards/${roundBonus}_top.png`];
}
</script>

<template>
    <Card class="mx-auto w-fit max-w-full bg-background/95 border-none p-0">
        <CardContent>
            <Form
                v-bind="RoundBonusChoiceController.form(gameId)"
                class="grid max-w-full gap-3"
                #default="{ errors, processing }"
            >
                <input type="hidden" name="round_bonus" :value="selectedRoundBonus ?? ''" />
                <div class="flex max-w-full flex-col items-center gap-3">
                    <TooltipProvider :delay-duration="150">
                        <div class="flex max-w-full gap-2 overflow-x-auto p-1">
                            <Tooltip
                                v-for="offer in offers.filter((item) => optionIds.includes(item.roundBonus))"
                                :key="offer.roundBonus"
                            >
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="relative w-24 shrink-0 rounded-lg border-2 p-1 transition-colors sm:w-28"
                                        :class="
                                            selectedRoundBonus === offer.roundBonus
                                                ? 'border-primary ring-2 ring-primary'
                                                : 'border-muted'
                                        "
                                        @click="selectedRoundBonus = offer.roundBonus"
                                    >
                                        <img
                                            :src="image(offer.roundBonus)"
                                            :alt="`Бонус раунда ${offer.roundBonus}`"
                                            class="h-auto w-full rounded-md"
                                        />
                                        <span
                                            v-if="offer.coins > 0"
                                            class="absolute top-2 right-2 grid size-8 place-items-center"
                                        >
                                            <img :src="goldMedallionUrl" alt="" class="absolute size-full" />
                                            <span class="relative text-sm font-bold text-amber-950">{{
                                                offer.coins
                                            }}</span>
                                        </span>
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent class="max-w-xs">{{ descriptions[offer.roundBonus] }}</TooltipContent>
                            </Tooltip>
                        </div>
                    </TooltipProvider>
                    <Button type="submit" class="shrink-0" :disabled="processing || selectedRoundBonus === null">
                        {{ processing ? 'Подтверждение…' : 'Подтвердить' }}
                    </Button>
                </div>
                <div class="text-center">
                    <InputError :message="errors.round_bonus ?? errors.game" />
                </div>
            </Form>
        </CardContent>
    </Card>
</template>
