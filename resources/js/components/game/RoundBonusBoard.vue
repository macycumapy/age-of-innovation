<script setup lang="ts">
import type { RoundBonus, RoundBonusOffer } from '@/types';
import goldMedallionUrl from '../../../images/token_parts/gold_medallion.png';

defineProps<{
    offers: RoundBonusOffer[];
}>();

const roundBonusImages = import.meta.glob(
    '../../../images/round_bonus_cards/*_top.png',
    {
        eager: true,
        import: 'default',
        query: '?url',
    },
) as Record<string, string>;

function roundBonusImage(roundBonus: RoundBonus): string {
    return roundBonusImages[
        `../../../images/round_bonus_cards/${roundBonus}_top.png`
    ];
}
</script>

<template>
    <section
        v-if="offers.length"
        class="grid gap-3 rounded-xl bg-card shadow-sm w-xs"
    >
        <div class="grid grid-cols-3 gap-2">
            <figure
                v-for="offer in offers"
                :key="offer.roundBonus"
                class="relative"
            >
                <img
                    :src="roundBonusImage(offer.roundBonus)"
                    :alt="`Бонус раунда ${offer.roundBonus}`"
                    class="h-auto w-full rounded-md shadow-sm"
                />

                <figcaption
                    v-if="offer.coins > 0"
                    class="absolute top-2 right-2 grid size-9 place-items-center drop-shadow-md"
                    :aria-label="`Монет на карточке: ${offer.coins}`"
                >
                    <img
                        :src="goldMedallionUrl"
                        alt=""
                        class="absolute inset-0 size-full"
                    />
                    <span
                        class="relative z-10 text-sm font-bold text-amber-950"
                    >
                        {{ offer.coins }}
                    </span>
                </figcaption>
            </figure>
        </div>
    </section>
</template>
