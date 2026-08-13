<script setup lang="ts">
import { computed } from 'vue';
import type { CSSProperties } from 'vue';
import type {
    GamePlayerBoardState,
    GamePlayerSummary,
    PlayerColor,
} from '@/types';
import cultBoardUrl from '../../../images/cult_board.png';

type KnowledgeDiscipline = keyof GamePlayerBoardState['knowledge'];

type KnowledgeMarker = {
    playerId: number;
    color: PlayerColor;
    discipline: KnowledgeDiscipline;
    level: number;
    collisionIndex: number;
    collisionCount: number;
};

const props = defineProps<{
    players: GamePlayerSummary[];
    playerStates: GamePlayerBoardState[];
}>();

const boardWidth = 861;
const boardHeight = 1309;
const tokenWidth = 75;
const disciplineX: Record<KnowledgeDiscipline, number> = {
    banking: 96,
    law: 292,
    engineering: 489,
    medicine: 684,
};
const levelY = [
    1014, 933, 860, 783, 706, 627, 552, 470, 385, 319, 267, 210, 73,
];

const tokenImages = import.meta.glob(
    '../../../images/buildings/*/token.png',
    {
        eager: true,
        import: 'default',
        query: '?url',
    },
) as Record<string, string>;

const disciplines = Object.keys(disciplineX) as KnowledgeDiscipline[];

function tokenImage(color: PlayerColor): string {
    return tokenImages[`../../../images/buildings/${color}/token.png`];
}

const knowledgeMarkers = computed<KnowledgeMarker[]>(() => {
    const markersWithoutCollisions = props.playerStates.flatMap((state) => {
        const player = props.players.find(
            (candidate) => candidate.id === state.playerId,
        );

        if (player === undefined || player.color === null) {
            return [];
        }

        return disciplines.map((discipline) => ({
            playerId: state.playerId,
            color: player.color,
            discipline,
            level: Math.max(0, Math.min(state.knowledge[discipline], 12)),
        }));
    });

    return markersWithoutCollisions.map((marker) => {
        const collisions = markersWithoutCollisions.filter(
            (candidate) =>
                candidate.discipline === marker.discipline &&
                candidate.level === marker.level,
        );

        return {
            ...marker,
            collisionIndex: collisions.indexOf(marker),
            collisionCount: collisions.length,
        };
    });
});

function markerStyle(marker: KnowledgeMarker): CSSProperties {
    const collisionOffset =
        (marker.collisionIndex - (marker.collisionCount - 1) / 2) * 18;

    return {
        left: `${((disciplineX[marker.discipline] + collisionOffset) / boardWidth) * 100}%`,
        top: `${(levelY[marker.level] / boardHeight) * 100}%`,
        width: `${(tokenWidth / boardWidth) * 100}%`,
    };
}
</script>

<template>
    <div
        class="relative overflow-hidden rounded-xl border border-border shadow-inner"
    >
        <img
            :src="cultBoardUrl"
            alt="Поле культов"
            class="block h-auto w-full"
        />

        <img
            v-for="marker in knowledgeMarkers"
            :key="`${marker.playerId}-${marker.discipline}`"
            :src="tokenImage(marker.color)"
            :style="markerStyle(marker)"
            :alt="`Уровень ${marker.level}`"
            class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-1/2 drop-shadow-md"
        />
    </div>
</template>
