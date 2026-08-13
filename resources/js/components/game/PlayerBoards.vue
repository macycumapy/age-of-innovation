<script setup lang="ts">
import { computed } from 'vue';
import type { CSSProperties } from 'vue';
import type {
    Faction,
    GamePlayerBoardState,
    GamePlayerSummary,
    PlayerColor,
} from '@/types';
import manaUrl from '../../../images/token_parts/mana.png';

type PlayerBuildingType =
    'workshop' | 'guild' | 'school' | 'university' | 'palace';

type BuildingSlot = {
    type: PlayerBuildingType;
    x: number;
    y: number;
};

type PowerBowl = {
    key: 'bowlOne' | 'bowlTwo' | 'bowlThree';
    label: string;
    x: number;
    y: number;
};

const props = defineProps<{
    players: GamePlayerSummary[];
    playerStates: GamePlayerBoardState[];
    currentUserId: number;
}>();

const boardImages = import.meta.glob('../../../images/terrain_boards/*.webp', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const buildingImages = import.meta.glob(
    '../../../images/buildings/*/{workshop,guild,school,university,palace,token}.png',
    {
        eager: true,
        import: 'default',
        query: '?url',
    },
) as Record<string, string>;

const factionImages = import.meta.glob('../../../images/factions/*.jpg', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const boardWidth = 1219;
const boardHeight = 636;
const buildingWidth = 80;
const tokenWidth = 85;
const factionCardX = 593;
const factionCardY = 60;
const factionCardWidth = 380;
const shippingLevelY = [160, 110, 60, 10];
const terraformingLevelY = [150, 100, 50];

const powerBowls: PowerBowl[] = [
    { key: 'bowlOne', label: 'Чаша силы I', x: 664, y: 462 },
    { key: 'bowlTwo', label: 'Чаша силы II', x: 664, y: 302 },
    { key: 'bowlThree', label: 'Чаша силы III', x: 813, y: 380 },
];

const manaPositions = [
    [50, 50],
    [34, 35],
    [57, 29],
    [70, 47],
    [61, 67],
    [37, 69],
    [23, 52],
    [46, 18],
    [76, 31],
    [79, 66],
    [49, 81],
    [19, 75],
] as const;

const factionNames: Record<Faction, string> = {
    blessed: 'Благословенные',
    felines: 'Кошачьи',
    goblins: 'Гоблины',
    illusionists: 'Иллюзионисты',
    inventors: 'Изобретатели',
    lizards: 'Ящеры',
    moles: 'Кроты',
    monks: 'Монахи',
    navigators: 'Навигаторы',
    omar: 'Омар',
    philosophers: 'Философы',
    psychics: 'Провидцы',
};

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

function buildingImage(color: PlayerColor, type: PlayerBuildingType): string {
    return buildingImages[`../../../images/buildings/${color}/${type}.png`];
}

function buildingStyle(slot: BuildingSlot): CSSProperties {
    return {
        left: `${(slot.x / boardWidth) * 100}%`,
        top: `${(slot.y / boardHeight) * 100}%`,
        width: `${(buildingWidth / boardWidth) * 100}%`,
    };
}

function factionImage(faction: Faction): string {
    return factionImages[`../../../images/factions/${faction}.jpg`];
}

function factionCardStyle(): CSSProperties {
    return {
        left: `${(factionCardX / boardWidth) * 100}%`,
        top: `${(factionCardY / boardHeight) * 100}%`,
        width: `${(factionCardWidth / boardWidth) * 100}%`,
    };
}

function tokenImage(color: PlayerColor): string {
    return buildingImages[`../../../images/buildings/${color}/token.png`];
}

function playerState(playerId: number): GamePlayerBoardState | undefined {
    return props.playerStates.find((state) => state.playerId === playerId);
}

function tokenStyle(x: number, y: number): CSSProperties {
    return {
        left: `${(x / boardWidth) * 100}%`,
        top: `${(y / boardHeight) * 100}%`,
        width: `${(tokenWidth / boardWidth) * 100}%`,
    };
}

function levelPosition(positions: number[], level: number | undefined): number {
    const normalizedLevel = Math.max(
        0,
        Math.min(level ?? 0, positions.length - 1),
    );

    return positions[normalizedLevel];
}

function shippingPosition(
    color: PlayerColor,
    level: number | undefined,
): number {
    return levelPosition(
        shippingLevelY,
        color === 'blue' ? (level ?? 0) - 1 : level,
    );
}

function powerBowlStyle(bowl: PowerBowl): CSSProperties {
    return {
        left: `${(bowl.x / boardWidth) * 100}%`,
        top: `${(bowl.y / boardHeight) * 100}%`,
        width: `${(160 / boardWidth) * 100}%`,
    };
}

function manaStyle(index: number): CSSProperties {
    const [left, top] = manaPositions[index % manaPositions.length];
    const layer = Math.floor(index / manaPositions.length);

    return {
        left: `${left + layer * 3}%`,
        top: `${top + layer * 3}%`,
    };
}

function powerInBowl(
    state: GamePlayerBoardState | undefined,
    bowl: PowerBowl,
): number {
    return state?.power[bowl.key] ?? 0;
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

                    <img
                        v-if="player.faction"
                        :src="factionImage(player.faction)"
                        :alt="`Раса игрока ${player.user.name}: ${factionNames[player.faction]}`"
                        :style="factionCardStyle()"
                        class="absolute z-0 rounded-sm shadow-md"
                    />

                    <div
                        v-for="bowl in powerBowls"
                        :key="bowl.key"
                        :style="powerBowlStyle(bowl)"
                        class="pointer-events-none absolute z-10 aspect-square rounded-full"
                        role="img"
                        :aria-label="`${bowl.label}: ${powerInBowl(playerState(player.id), bowl)}`"
                    >
                        <img
                            v-for="manaIndex in powerInBowl(
                                playerState(player.id),
                                bowl,
                            )"
                            :key="manaIndex"
                            :src="manaUrl"
                            alt=""
                            :style="manaStyle(manaIndex - 1)"
                            class="absolute w-[18%] -translate-x-1/2 -translate-y-1/2 drop-shadow-md"
                        />
                    </div>

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

                    <span
                        :style="
                            tokenStyle(
                                15,
                                shippingPosition(
                                    player.color,
                                    playerState(player.id)?.shippingLevel,
                                ),
                            )
                        "
                        class="group absolute z-20 aspect-[141/158] cursor-pointer hover:z-30"
                    >
                        <img
                            :src="tokenImage(player.color)"
                            alt="Уровень навигации"
                            class="h-full w-full object-contain transition-transform duration-500 ease-in-out group-hover:translate-x-[25px] group-hover:-translate-y-[25px]"
                        />
                    </span>

                    <span
                        :style="
                            tokenStyle(
                                420,
                                levelPosition(
                                    terraformingLevelY,
                                    playerState(player.id)?.terraformingLevel,
                                ),
                            )
                        "
                        class="group absolute z-20 aspect-[141/158] cursor-pointer hover:z-30"
                    >
                        <img
                            :src="tokenImage(player.color)"
                            alt="Уровень преобразования"
                            class="h-full w-full object-contain transition-transform duration-500 ease-in-out group-hover:translate-x-[25px] group-hover:-translate-y-[25px]"
                        />
                    </span>
                </div>
            </figure>
        </div>
    </section>
</template>
