<script setup lang="ts">
import { computed } from 'vue';
import type { CSSProperties } from 'vue';
import type { GamePlayerSummary, PlayerColor } from '@/types';

type PlayerBuildingType =
    | 'workshop'
    | 'guild'
    | 'school'
    | 'university'
    | 'palace';

type BuildingSlot = {
    type: PlayerBuildingType;
    x: number;
    y: number;
};

const props = defineProps<{
    players: GamePlayerSummary[];
    currentUserId: number;
}>();

const boardImages = import.meta.glob(
    '../../../images/terrain_boards/*.webp',
    {
        eager: true,
        import: 'default',
        query: '?url',
    },
) as Record<string, string>;

const buildingImages = import.meta.glob(
    '../../../images/buildings/*/{workshop,guild,school,university,palace}.png',
    {
        eager: true,
        import: 'default',
        query: '?url',
    },
) as Record<string, string>;

const boardWidth = 1219;
const boardHeight = 636;
const buildingWidth = 80;

const buildingSlots: BuildingSlot[] = [
    { type: 'palace', x: 168, y: 335 },
    { type: 'university', x: 465, y: 320 },
    { type: 'guild', x: 70, y: 440 },
    { type: 'guild', x: 125, y: 440 },
    { type: 'guild', x: 175, y: 440 },
    { type: 'guild', x: 230, y: 440 },
    { type: 'school', x: 387, y: 440 },
    { type: 'school', x: 463, y: 440 },
    { type: 'school', x: 540, y: 440 },
    { type: 'workshop', x: 110, y: 535 },
    { type: 'workshop', x: 165, y: 535 },
    { type: 'workshop', x: 220, y: 535 },
    { type: 'workshop', x: 275, y: 535 },
    { type: 'workshop', x: 330, y: 535 },
    { type: 'workshop', x: 385, y: 535 },
    { type: 'workshop', x: 440, y: 535 },
    { type: 'workshop', x: 495, y: 535 },
    { type: 'workshop', x: 550, y: 535 },
];

const playersWithBoards = computed(() => {
    const players = props.players.filter(
        (player): player is GamePlayerSummary & { color: PlayerColor } =>
            player.color !== null,
    );
    const currentPlayerIndex = players.findIndex(
        (player) => player.user.id === props.currentUserId,
    );

    if (currentPlayerIndex <= 0) {
        return players;
    }

    return [
        players[currentPlayerIndex],
        ...players.slice(0, currentPlayerIndex),
        ...players.slice(currentPlayerIndex + 1),
    ];
});

function boardImage(color: PlayerColor): string {
    return boardImages[`../../../images/terrain_boards/${color}.webp`];
}

function buildingImage(
    color: PlayerColor,
    type: PlayerBuildingType,
): string {
    return buildingImages[
        `../../../images/buildings/${color}/${type}.png`
    ];
}

function buildingStyle(slot: BuildingSlot): CSSProperties {
    return {
        left: `${(slot.x / boardWidth) * 100}%`,
        top: `${(slot.y / boardHeight) * 100}%`,
        width: `${(buildingWidth / boardWidth) * 100}%`,
    };
}
</script>

<template>
    <section v-if="playersWithBoards.length" class="grid gap-4">
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <figure
                v-for="player in playersWithBoards"
                :key="player.id"
                class="overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <div class="relative">
                    <img
                        :src="boardImage(player.color)"
                        :alt="`Планшет игрока ${player.user.name}`"
                        class="block h-auto w-full"
                    />

                    <span
                        v-for="(slot, slotIndex) in buildingSlots"
                        :key="`${slot.type}-${slotIndex}`"
                        :style="buildingStyle(slot)"
                        class="group absolute z-0 aspect-[141/158] cursor-pointer hover:z-10"
                    >
                        <img
                            :src="buildingImage(player.color, slot.type)"
                            alt=""
                            class="h-full w-full object-contain drop-shadow-md transition-transform duration-500 ease-in-out group-hover:translate-x-[25px] group-hover:-translate-y-[25px]"
                        />
                    </span>
                </div>
            </figure>
        </div>
    </section>
</template>
