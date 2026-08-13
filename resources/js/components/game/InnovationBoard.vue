<script setup lang="ts">
import { computed } from 'vue';
import type { CSSProperties } from 'vue';
import type { Competency, Innovation } from '@/types';
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
}>();

const boardWidth = 850;
const tileWidth = 168;
const competencyBoardHeight = 480;
const competencyTileWidth = 100;
const competencyStackSize = 4;
const competencyLayerOffset = 2;

const inventionBoardUrl = computed(() =>
    props.playerCount >= 4
        ? fourPlayerInventionBoardUrl
        : twoPlayerInventionBoardUrl,
);

const inventionBoardHeight = computed(() =>
    props.playerCount >= 4 ? 564 : 423,
);

const innovationImages = import.meta.glob<string>(
    '../../../images/innovations/*.jpg',
    { eager: true, import: 'default', query: '?url' },
);

const competencyImages = import.meta.glob<string>(
    '../../../images/competencies/*.png',
    { eager: true, import: 'default', query: '?url' },
);

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
    { x: 92, y: 106 },
    { x: 304, y: 106 },
    { x: 516, y: 106 },
    { x: 728, y: 106 },
    { x: 92, y: 230 },
    { x: 304, y: 230 },
    { x: 516, y: 230 },
    { x: 728, y: 230 },
    { x: 92, y: 354 },
    { x: 304, y: 354 },
    { x: 516, y: 354 },
    { x: 728, y: 354 },
];

const innovationSlots = computed(() => [
    ...(props.playerCount % 2 === 0 ? upperEvenSlots : upperOddSlots),
    ...firstRowSlots,
    ...(props.playerCount >= 4 ? secondRowSlots : []),
]);

function innovationImage(innovation: Innovation): string {
    return (
        innovationImages[`../../../images/innovations/${innovation}.jpg`] ?? ''
    );
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
    return (
        competencyImages[`../../../images/competencies/${competency}.png`] ?? ''
    );
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
    const offset = (competencyStackSize - layer) * competencyLayerOffset;

    return {
        transform: `translate(${-offset}px, ${-offset}px)`,
    };
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border shadow-inner">
        <div class="relative overflow-hidden">
            <img
                :src="inventionBoardUrl"
                alt="Планшет инноваций"
                class="block h-auto w-full"
            />

            <img
                v-for="(innovation, index) in innovations"
                :key="innovation"
                :src="innovationImage(innovation)"
                :alt="`Плашка инновации ${innovation}`"
                :style="innovationStyle(innovationSlots[index])"
                class="absolute rounded-xs shadow-[-2px_-2px_2px_rgba(0,0,0,0.45)]"
            />
        </div>

        <div class="relative overflow-hidden">
            <img
                :src="competencyBoardUrl"
                alt="Планшет компетенций"
                class="block h-auto w-full"
            />

            <span
                v-for="(competency, index) in competencies"
                :key="competency"
                :style="competencyStyle(competencySlots[index])"
                class="absolute aspect-[120/117]"
                :aria-label="`Стопка из ${competencyStackSize} плашек компетенции ${competency}`"
            >
                <img
                    v-for="layer in competencyStackSize"
                    :key="layer"
                    :src="competencyImage(competency)"
                    alt=""
                    :style="competencyLayerStyle(layer)"
                    class="absolute inset-0 size-full drop-shadow-[-2px_-2px_2px_rgba(0,0,0,0.45)]"
                />
            </span>
        </div>
    </div>
</template>
