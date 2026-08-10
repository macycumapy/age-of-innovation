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
    { x: 82, y: 96 },
    { x: 294, y: 96 },
    { x: 506, y: 96 },
    { x: 718, y: 96 },
    { x: 82, y: 220 },
    { x: 294, y: 220 },
    { x: 506, y: 220 },
    { x: 718, y: 220 },
    { x: 82, y: 344 },
    { x: 294, y: 344 },
    { x: 506, y: 344 },
    { x: 718, y: 344 },
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
                class="absolute rounded-xs shadow-md"
            />
        </div>

        <div class="relative overflow-hidden">
            <img
                :src="competencyBoardUrl"
                alt="Планшет компетенций"
                class="block h-auto w-full"
            />

            <img
                v-for="(competency, index) in competencies"
                :key="competency"
                :src="competencyImage(competency)"
                :alt="`Плашка компетенции ${competency}`"
                :style="competencyStyle(competencySlots[index])"
                class="absolute drop-shadow-md"
            />
        </div>
    </div>
</template>
