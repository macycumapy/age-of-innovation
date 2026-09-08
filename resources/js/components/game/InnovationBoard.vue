<script setup lang="ts">
import { computed } from 'vue';
import type { CSSProperties } from 'vue';
import type { Competency, Innovation, InnovationPurchaseState } from '@/types';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import competencyBoardUrl from '../../../images/competency_board.png';
import twoPlayerInventionBoardUrl from '../../../images/invention_board_2.jpg';
import threePlayerInventionBoardUrl from '../../../images/invention_board_3.jpg';
import fourPlayerInventionBoardUrl from '../../../images/invention_board_4.jpg';
import fivePlayerInventionBoardUrl from '../../../images/invention_board_5.jpg';

type InnovationSlot = {
    x: number;
    y: number;
};

type CompetencySlot = InnovationSlot;

type InventionBoard = {
    url: string;
    width: number;
    height: number;
    slots: InnovationSlot[];
};

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

const tileWidth = 168;
const competencyBoardWidth = 850;
const competencyBoardHeight = 480;
const competencyTileWidth = 100;
const competencyLayerOffset = 2;

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

const croppedBoardFirstRowSlots: InnovationSlot[] = [
    { x: 20, y: 28 },
    { x: 233, y: 28 },
    { x: 445, y: 28 },
    { x: 657, y: 28 },
];

const croppedBoardSecondRowSlots: InnovationSlot[] = [
    { x: 20, y: 176 },
    { x: 233, y: 176 },
    { x: 445, y: 176 },
    { x: 657, y: 176 },
];

const croppedBoardThirdRowSlots: InnovationSlot[] = [
    { x: 20, y: 324 },
    { x: 233, y: 324 },
    { x: 445, y: 324 },
    { x: 657, y: 324 },
];

const inventionBoards: Record<number, InventionBoard> = {
    2: {
        url: twoPlayerInventionBoardUrl,
        width: 850,
        height: 423,
        slots: [...upperEvenSlots, ...firstRowSlots],
    },
    3: {
        url: threePlayerInventionBoardUrl,
        width: 850,
        height: 307,
        slots: [...croppedBoardFirstRowSlots, ...croppedBoardSecondRowSlots],
    },
    4: {
        url: fourPlayerInventionBoardUrl,
        width: 850,
        height: 564,
        slots: [...upperEvenSlots, ...firstRowSlots, ...secondRowSlots],
    },
    5: {
        url: fivePlayerInventionBoardUrl,
        width: 849,
        height: 453,
        slots: [...croppedBoardFirstRowSlots, ...croppedBoardSecondRowSlots, ...croppedBoardThirdRowSlots],
    },
};

const inventionBoard = computed(() => inventionBoards[props.playerCount] ?? inventionBoards[2]);

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

function innovationImage(innovation: Innovation): string {
    return innovationImages[`../../../images/innovations/${innovation}.jpg`] ?? '';
}

function innovationStyle(slot: InnovationSlot | undefined): CSSProperties {
    if (!slot) {
        return { display: 'none' };
    }

    return {
        left: `${(slot.x / inventionBoard.value.width) * 100}%`,
        top: `${(slot.y / inventionBoard.value.height) * 100}%`,
        width: `${(tileWidth / inventionBoard.value.width) * 100}%`,
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
        left: `${(slot.x / competencyBoardWidth) * 100}%`,
        top: `${(slot.y / competencyBoardHeight) * 100}%`,
        width: `${(competencyTileWidth / competencyBoardWidth) * 100}%`,
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
            <img :src="inventionBoard.url" alt="Планшет инноваций" class="block h-auto w-full" />

            <TooltipProvider :delay-duration="150">
                <template v-for="(innovation, index) in innovations" :key="innovation">
                    <Tooltip v-if="innovationStates[index]?.isAvailable">
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                :style="innovationStyle(inventionBoard.slots[index])"
                                class="absolute rounded-xs text-left drop-shadow-[-2px_2px_2px_rgba(0,0,0,0.45)] transition enabled:cursor-pointer enabled:hover:ring-4 enabled:hover:ring-primary/70 disabled:cursor-help"
                                :disabled="!canMakeInnovation || !innovationStates[index]?.isAffordable"
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
                </template>
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
