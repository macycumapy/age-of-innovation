<script setup lang="ts">
import { computed } from 'vue';
import type { CSSProperties } from 'vue';
import type { Competency, Innovation, InnovationPurchaseState } from '@/types';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import competencyBoardUrl from '../../../images/competency_board.png';
import twoPlayerInventionBoardUrl from '../../../images/invention_board_2.jpg';
import fourPlayerInventionBoardUrl from '../../../images/invention_board_4.jpg';

type InnovationSlot = {
    x: number;
    y: number;
};

type CompetencySlot = InnovationSlot;

const props = defineProps<{
    playerCount: number;
    innovations: Innovation[];
    competencies: Competency[];
    competencyCounts: Partial<Record<Competency, number>>;
    innovationDescriptions: Record<Innovation, string>;
    competencyDescriptions: Record<Competency, string>;
    innovationStates: InnovationPurchaseState[];
    canMakeInnovation: boolean;
}>();

const emit = defineEmits<{
    innovationClick: [innovation: Innovation];
}>();

const boardWidth = 850;
const tileWidth = 168;
const competencyBoardHeight = 480;
const competencyTileWidth = 100;
const competencyLayerOffset = 2;

const inventionBoardUrl = computed(() =>
    props.playerCount >= 4 ? fourPlayerInventionBoardUrl : twoPlayerInventionBoardUrl,
);

const inventionBoardHeight = computed(() => (props.playerCount >= 4 ? 564 : 423));

const innovationImages = import.meta.glob<string>('../../../images/innovations/*.jpg', {
    eager: true,
    import: 'default',
    query: '?url',
});

const competencyImages = import.meta.glob<string>('../../../images/competencies/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
});

const upperEvenSlots: InnovationSlot[] = [
    { x: 129, y: 55 },
    { x: 554, y: 55 },
];

const upperOddSlots: InnovationSlot[] = [
    { x: 53, y: 55 },
    { x: 132, y: 55 },
    { x: 553, y: 55 },
    { x: 632, y: 55 },
];

const firstRowSlots: InnovationSlot[] = [
    { x: 22, y: 285 },
    { x: 235, y: 285 },
    { x: 447, y: 285 },
    { x: 660, y: 285 },
];

const secondRowSlots: InnovationSlot[] = [
    { x: 22, y: 440 },
    { x: 235, y: 440 },
    { x: 447, y: 440 },
    { x: 660, y: 440 },
];

const competencySlots: CompetencySlot[] = [
    { x: 85, y: 94 },
    { x: 298, y: 94 },
    { x: 509, y: 94 },
    { x: 721, y: 94 },
    { x: 85, y: 218 },
    { x: 298, y: 218 },
    { x: 509, y: 218 },
    { x: 721, y: 218 },
    { x: 85, y: 342 },
    { x: 298, y: 342 },
    { x: 509, y: 342 },
    { x: 721, y: 342 },
];

const innovationSlots = computed(() => [
    ...(props.playerCount % 2 === 0 ? upperEvenSlots : upperOddSlots),
    ...firstRowSlots,
    ...(props.playerCount >= 4 ? secondRowSlots : []),
]);

function innovationImage(innovation: Innovation): string {
    return innovationImages[`../../../images/innovations/${innovation}.jpg`] ?? '';
}

function innovationStyle(slot: InnovationSlot | undefined): CSSProperties {
    if (!slot) {
        return { display: 'none' };
    }

    return {
        left: `${(slot.x / boardWidth) * 100}%`,
        top: `${(slot.y / inventionBoardHeight.value) * 100}%`,
        width: `${(tileWidth / boardWidth) * 100}%`,
    };
}

function competencyImage(competency: Competency): string {
    return competencyImages[`../../../images/competencies/${competency}.png`] ?? '';
}

function competencyStyle(slot: CompetencySlot | undefined): CSSProperties {
    if (!slot) {
        return { display: 'none' };
    }

    return {
        left: `${(slot.x / boardWidth) * 100}%`,
        top: `${(slot.y / competencyBoardHeight) * 100}%`,
        width: `${(competencyTileWidth / boardWidth) * 100}%`,
    };
}

function competencyLayerStyle(layer: number): CSSProperties {
    const offset = (layer - 1) * competencyLayerOffset;

    return {
        transform: `translate(${offset}px, ${-offset}px)`,
    };
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border shadow-inner">
        <div class="relative overflow-hidden">
            <img :src="inventionBoardUrl" alt="Планшет инноваций" class="block h-auto w-full" />

            <TooltipProvider :delay-duration="150">
                <Tooltip v-for="(innovation, index) in innovations" :key="innovation">
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            :style="innovationStyle(innovationSlots[index])"
                            class="absolute rounded-xs text-left drop-shadow-[-2px_2px_2px_rgba(0,0,0,0.45)] transition enabled:cursor-pointer enabled:hover:ring-4 enabled:hover:ring-primary/70 disabled:cursor-help"
                            :class="innovationStates[index]?.isAvailable ? '' : 'opacity-40 grayscale'"
                            :disabled="!canMakeInnovation || !innovationStates[index]?.isAvailable"
                            @click="emit('innovationClick', innovation)"
                        >
                            <img
                                :src="innovationImage(innovation)"
                                :alt="`Плашка инновации ${innovation}`"
                                class="block h-auto w-full rounded-xs"
                            />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        <p class="font-semibold">Инновация</p>
                        <p>{{ innovationDescriptions[innovation] }}</p>
                        <p v-if="innovationStates[index]" class="mt-1 font-medium">
                            Цена: {{ innovationStates[index].totalBooks }} книг<span
                                v-if="innovationStates[index].coins > 0"
                            >
                                и {{ innovationStates[index].coins }} монет</span
                            >.
                        </p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>

        <div class="relative overflow-hidden">
            <img :src="competencyBoardUrl" alt="Планшет компетенций" class="block h-auto w-full" />

            <TooltipProvider :delay-duration="150">
                <Tooltip v-for="(competency, index) in competencies" :key="competency">
                    <TooltipTrigger as-child>
                        <span
                            :style="competencyStyle(competencySlots[index])"
                            tabindex="0"
                            class="absolute aspect-[120/117] cursor-help"
                            :aria-label="`Стопка из ${competencyCounts[competency] ?? 0} плашек компетенции ${competency}`"
                        >
                            <img
                                v-for="layer in competencyCounts[competency] ?? 0"
                                :key="layer"
                                :src="competencyImage(competency)"
                                alt=""
                                :style="competencyLayerStyle(layer)"
                                class="absolute inset-0 size-full object-contain drop-shadow-[-2px_2px_2px_rgba(0,0,0,0.45)]"
                            />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        <p class="font-semibold">Компетенция {{ competency.slice(-2) }}</p>
                        <p>{{ competencyDescriptions[competency] }}</p>
                        <p class="mt-1 font-medium">Осталось: {{ competencyCounts[competency] ?? 0 }}</p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
    </div>
</template>
