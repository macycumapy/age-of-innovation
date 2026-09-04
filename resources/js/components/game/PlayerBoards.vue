<script setup lang="ts">
import { computed } from 'vue';
import type { CSSProperties } from 'vue';
import type {
    Competency,
    Faction,
    GamePlayerBoardState,
    GamePlayerSummary,
    Innovation,
    PalaceAbility,
    PlayerColor,
    RoundBonus,
    TownTile,
} from '@/types';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { factionNames } from '@/lib/gameDisplay';
import annexUrl from '../../../images/buildings/white/annex.png';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import coinUrl from '../../../images/token_parts/gold_medallion.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import unassignedBookUrl from '../../../images/token_parts/gray_book.png';
import handUrl from '../../../images/token_parts/cube.png';
import goldCrossUrl from '../../../images/token_parts/gold_cross.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import manaUrl from '../../../images/token_parts/mana.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';

type PlayerBuildingType = 'workshop' | 'guild' | 'school' | 'university' | 'palace';

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

type BookType = keyof GamePlayerBoardState['books'];

const props = defineProps<{
    players: GamePlayerSummary[];
    playerStates: GamePlayerBoardState[];
    currentUserId: number;
    roundBonusDescriptions: Record<RoundBonus, string>;
    competencyDescriptions: Record<Competency, string>;
    innovationDescriptions: Record<Innovation, string>;
    palaceDescriptions: Record<PalaceAbility, string>;
    canSacrificePower: boolean;
    canExchangeResources: boolean;
    canUseRoundBonusAction: boolean;
    canUseFactionAction: boolean;
    canUsePalaceAction: boolean;
}>();

const emit = defineEmits<{
    sacrificePower: [];
    exchangeResources: [];
    useRoundBonusAction: [];
    useFactionAction: [];
    usePalaceAction: [];
}>();

const boardImages = import.meta.glob('../../../images/terrain_boards/*.webp', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const buildingImages = import.meta.glob(
    '../../../images/buildings/*/{workshop,guild,school,university,palace,token,scientist,bridge}.png',
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

const roundBonusImages = import.meta.glob('../../../images/round_bonus_cards/*_top.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const competencyImages = import.meta.glob('../../../images/competencies/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const innovationImages = import.meta.glob('../../../images/innovations/*.jpg', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const palaceImages = import.meta.glob('../../../images/palaces/*.jpg', {
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
const palaceTileX = 95;
const palaceTileY = 320;
const palaceTileWidth = 215;
const innovationTileX = 995;
const innovationTileWidth = 200;
const innovationTileYFromBottom = [455, 255, 54];
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

const bookImages: Record<BookType, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
    unassigned: unassignedBookUrl,
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
        (player): player is GamePlayerSummary & { color: PlayerColor } => player.color !== null,
    );
    const currentPlayerIndex = players.findIndex((player) => player.user.id === props.currentUserId);

    if (currentPlayerIndex <= 0) {
        return players;
    }

    return [
        players[currentPlayerIndex],
        ...players.slice(0, currentPlayerIndex),
        ...players.slice(currentPlayerIndex + 1),
    ];
});
const townTileImages = import.meta.glob('../../../images/cities/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

function townTileImage(townTile: TownTile, isUsed: boolean = false): string {
    if (isUsed) {
        return townTileImages['../../../images/cities/used.png'] ?? '';
    }

    return townTileImages[`../../../images/cities/${townTile}.png`] ?? '';
}

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

function isBuildingInSupply(playerId: number, slot: BuildingSlot, slotIndex: number): boolean {
    const typeSlotIndex =
        buildingSlots.slice(0, slotIndex + 1).filter((candidate) => candidate.type === slot.type).length - 1;
    const buildingsOnMap = playerState(playerId)?.buildingsOnMap[slot.type] ?? 0;

    return typeSlotIndex >= buildingsOnMap;
}

function factionImage(faction: Faction): string {
    return factionImages[`../../../images/factions/${faction}.jpg`];
}

function roundBonusImage(roundBonus: RoundBonus | undefined): string {
    if (roundBonus === undefined) {
        return '';
    }

    return roundBonusImages[`../../../images/round_bonus_cards/${roundBonus}_top.png`];
}

function factionCardStyle(): CSSProperties {
    return {
        left: `${(factionCardX / boardWidth) * 100}%`,
        top: `${(factionCardY / boardHeight) * 100}%`,
        width: `${(factionCardWidth / boardWidth) * 100}%`,
    };
}

function isFactionActionAvailable(player: GamePlayerSummary): boolean {
    return (
        props.canUseFactionAction &&
        player.user.id === props.currentUserId &&
        (playerState(player.id)?.canUseFactionAction ?? false)
    );
}

function isFactionActionUsed(player: GamePlayerSummary): boolean {
    return (
        player.faction !== null &&
        ['philosophers', 'psychics'].includes(player.faction) &&
        !(playerState(player.id)?.canUseFactionAction ?? false)
    );
}

function factionActionTokenStyle(faction: Faction): CSSProperties {
    const sourceWidth = 592;
    const sourceHeight = 438;
    const actionCenter = faction === 'philosophers' ? { x: 76, y: 249 } : { x: 76, y: 154 };
    const renderedHeight = (factionCardWidth * sourceHeight) / sourceWidth;
    const tokenSize = 82;

    return {
        left: `${((factionCardX + (actionCenter.x / sourceWidth) * factionCardWidth - tokenSize / 2) / boardWidth) * 100}%`,
        top: `${((factionCardY + (actionCenter.y / sourceHeight) * renderedHeight - tokenSize / 2) / boardHeight) * 100}%`,
        width: `${(tokenSize / boardWidth) * 100}%`,
    };
}

function tokenImage(color: PlayerColor): string {
    return buildingImages[`../../../images/buildings/${color}/token.png`];
}

function scientistImage(color: PlayerColor): string {
    return buildingImages[`../../../images/buildings/${color}/scientist.png`];
}

function bridgeImage(color: PlayerColor): string {
    return buildingImages[`../../../images/buildings/${color}/bridge.png`];
}

function competencyImage(competency: Competency): string {
    return competencyImages[`../../../images/competencies/${competency}.png`];
}

function innovationImage(innovation: Innovation): string {
    return innovationImages[`../../../images/innovations/${innovation}.jpg`];
}

function innovationTileStyle(index: number): CSSProperties {
    return {
        left: `${(innovationTileX / boardWidth) * 100}%`,
        top: `${(innovationTileYFromBottom[index] / boardHeight) * 100}%`,
        width: `${(innovationTileWidth / boardWidth) * 100}%`,
    };
}

function palaceImage(palace: PalaceAbility | null | undefined): string {
    if (!palace) {
        return '';
    }

    return palaceImages[`../../../images/palaces/${palace}.jpg`];
}

function palaceDescription(palace: PalaceAbility | null | undefined): string {
    return palace ? props.palaceDescriptions[palace] : '';
}

function palaceTileStyle(): CSSProperties {
    return {
        left: `${(palaceTileX / boardWidth) * 100}%`,
        top: `${(palaceTileY / boardHeight) * 100}%`,
        width: `${(palaceTileWidth / boardWidth) * 100}%`,
    };
}

function isPalaceActionAvailable(player: GamePlayerSummary): boolean {
    return (
        props.canUsePalaceAction &&
        player.user.id === props.currentUserId &&
        (playerState(player.id)?.canUsePalaceAction ?? false)
    );
}

function isPalaceActionUsed(playerId: number): boolean {
    const state = playerState(playerId);

    return (
        state?.palaceId !== null &&
        state?.palaceId !== undefined &&
        ['palace_01', 'palace_02', 'palace_03', 'palace_04', 'palace_06', 'palace_13'].includes(state.palaceId) &&
        !state.canUsePalaceAction
    );
}

function palaceActionCrossStyle(palace: PalaceAbility | null | undefined): CSSProperties {
    const left = palace === 'palace_13' ? 25 : ['palace_02', 'palace_03'].includes(palace ?? '') ? 50 : 72;

    return { left: `${left}%`, top: '50%', width: '35%' };
}

function playerState(playerId: number): GamePlayerBoardState | undefined {
    return props.playerStates.find((state) => state.playerId === playerId);
}

function scholarsForPlayer(playerId: number): number {
    return Math.max(0, Math.min(playerState(playerId)?.scholars ?? 0, playerState(playerId)?.scholarPoolSize ?? 7));
}

function scholarPoolSizeForPlayer(playerId: number): number {
    return Math.max(0, Math.min(playerState(playerId)?.scholarPoolSize ?? 7, 7));
}

function availableBridgesForPlayer(playerId: number): number {
    return playerState(playerId)?.availableBridges ?? 0;
}

function availableAnnexesForPlayer(playerId: number): number {
    return playerState(playerId)?.availableAnnexes ?? 0;
}

function coinsForPlayer(playerId: number): number {
    return playerState(playerId)?.coins ?? 0;
}

function toolsForPlayer(playerId: number): number {
    return playerState(playerId)?.tools ?? 0;
}

function competenciesForPlayer(playerId: number): Competency[] {
    return playerState(playerId)?.competencyIds ?? [];
}

function innovationsForPlayer(playerId: number): Innovation[] {
    return playerState(playerId)?.inventionIds ?? [];
}

function roundBonusForPlayer(playerId: number): RoundBonus | undefined {
    return playerState(playerId)?.roundBonus;
}

function isRoundBonusActionAvailable(player: GamePlayerSummary): boolean {
    return (
        props.canUseRoundBonusAction &&
        player.user.id === props.currentUserId &&
        (playerState(player.id)?.canUseRoundBonusAction ?? false)
    );
}

function isRoundBonusActionUsed(playerId: number): boolean {
    const state = playerState(playerId);

    return (
        state !== undefined &&
        ['spade', 'bridge', 'knowledge'].includes(state.roundBonus) &&
        !state.canUseRoundBonusAction
    );
}

function booksForPlayer(playerId: number): string[] {
    const books = playerState(playerId)?.books;

    if (books === undefined) {
        return [];
    }

    return (Object.keys(bookImages) as BookType[]).flatMap((bookType) =>
        Array.from({ length: books[bookType] }, () => bookImages[bookType]),
    );
}

function tokenStyle(x: number, y: number): CSSProperties {
    return {
        left: `${(x / boardWidth) * 100}%`,
        top: `${(y / boardHeight) * 100}%`,
        width: `${(tokenWidth / boardWidth) * 100}%`,
    };
}

function levelPosition(positions: number[], level: number | undefined): number {
    const normalizedLevel = Math.max(0, Math.min(level ?? 0, positions.length - 1));

    return positions[normalizedLevel];
}

function shippingPosition(color: PlayerColor, level: number | undefined): number {
    return levelPosition(shippingLevelY, color === 'blue' ? (level ?? 0) - 1 : level);
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

function powerInBowl(state: GamePlayerBoardState | undefined, bowl: PowerBowl): number {
    return state?.power[bowl.key] ?? 0;
}

function canSacrificeFromBowl(player: GamePlayerSummary, bowl: PowerBowl): boolean {
    return (
        props.canSacrificePower &&
        player.user.id === props.currentUserId &&
        bowl.key === 'bowlTwo' &&
        powerInBowl(playerState(player.id), bowl) > 1
    );
}
</script>

<template>
    <section v-if="playersWithBoards.length" class="grid gap-4">
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <figure
                v-for="player in playersWithBoards"
                :key="player.id"
                class="overflow-hidden rounded-xl bg-card shadow-sm"
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
                        :class="isFactionActionAvailable(player) ? 'cursor-pointer' : ''"
                        :role="isFactionActionAvailable(player) ? 'button' : undefined"
                        :tabindex="isFactionActionAvailable(player) ? 0 : undefined"
                        @click="isFactionActionAvailable(player) && emit('useFactionAction')"
                        @keydown.enter="isFactionActionAvailable(player) && emit('useFactionAction')"
                        @keydown.space.prevent="isFactionActionAvailable(player) && emit('useFactionAction')"
                    />

                    <img
                        v-if="player.faction && isFactionActionUsed(player)"
                        :src="goldCrossUrl"
                        alt="Действие расы использовано"
                        :style="factionActionTokenStyle(player.faction)"
                        class="pointer-events-none absolute z-10 h-auto drop-shadow-md"
                    />

                    <div
                        v-for="bowl in powerBowls"
                        :key="bowl.key"
                        :style="powerBowlStyle(bowl)"
                        class="absolute z-10 aspect-square rounded-full"
                        :class="canSacrificeFromBowl(player, bowl) ? 'cursor-pointer' : 'pointer-events-none'"
                        :role="canSacrificeFromBowl(player, bowl) ? 'button' : 'img'"
                        :tabindex="canSacrificeFromBowl(player, bowl) ? 0 : undefined"
                        :aria-label="`${bowl.label}: ${powerInBowl(playerState(player.id), bowl)}`"
                        @click="canSacrificeFromBowl(player, bowl) && emit('sacrificePower')"
                        @keydown.enter="canSacrificeFromBowl(player, bowl) && emit('sacrificePower')"
                        @keydown.space.prevent="canSacrificeFromBowl(player, bowl) && emit('sacrificePower')"
                    >
                        <img
                            v-for="manaIndex in powerInBowl(playerState(player.id), bowl)"
                            :key="manaIndex"
                            :src="manaUrl"
                            alt=""
                            :style="manaStyle(manaIndex - 1)"
                            class="absolute w-[18%] -translate-x-1/2 -translate-y-1/2 drop-shadow-md"
                        />
                    </div>

                    <button
                        v-if="canExchangeResources && player.user.id === currentUserId"
                        type="button"
                        class="absolute z-20 w-4 cursor-pointer rounded-md border border-amber-400/70 px-3 py-1.5 shadow-md"
                        :style="{ left: '72.5%', top: '44.5%', width: '7%', height: '15%' }"
                        @click="emit('exchangeResources')"
                        title="Обмен ресурсов"
                    ></button>

                    <span
                        v-for="(slot, slotIndex) in buildingSlots"
                        v-show="isBuildingInSupply(player.id, slot, slotIndex)"
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

                    <TooltipProvider v-if="playerState(player.id)?.palaceId" :delay-duration="150">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span
                                    :style="palaceTileStyle()"
                                    tabindex="0"
                                    class="absolute block h-auto rounded-sm shadow-md"
                                    :class="isPalaceActionAvailable(player) ? 'cursor-pointer' : 'cursor-help'"
                                    :role="isPalaceActionAvailable(player) ? 'button' : undefined"
                                    @click="isPalaceActionAvailable(player) && emit('usePalaceAction')"
                                    @keydown.enter="isPalaceActionAvailable(player) && emit('usePalaceAction')"
                                    @keydown.space.prevent="isPalaceActionAvailable(player) && emit('usePalaceAction')"
                                >
                                    <img
                                        :src="palaceImage(playerState(player.id)?.palaceId)"
                                        :alt="`Жетон Дворца игрока ${player.user.name}`"
                                        class="block h-auto w-full rounded-sm"
                                    />
                                    <img
                                        v-if="isPalaceActionUsed(player.id)"
                                        :src="goldCrossUrl"
                                        alt="Действие Дворца использовано"
                                        :style="palaceActionCrossStyle(playerState(player.id)?.palaceId)"
                                        class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 drop-shadow-md"
                                    />
                                </span>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs">
                                <p class="font-semibold">
                                    Жетон Дворца {{ Number(playerState(player.id)?.palaceId?.slice(-2)) }}
                                </p>
                                <p>{{ palaceDescription(playerState(player.id)?.palaceId) }}</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>

                    <span
                        :style="tokenStyle(15, shippingPosition(player.color, playerState(player.id)?.shippingLevel))"
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
                                levelPosition(terraformingLevelY, playerState(player.id)?.terraformingLevel),
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

                    <TooltipProvider v-if="innovationsForPlayer(player.id).length" :delay-duration="150">
                        <Tooltip
                            v-for="(innovation, innovationIndex) in innovationsForPlayer(player.id)"
                            :key="innovation"
                        >
                            <TooltipTrigger as-child>
                                <img
                                    :src="innovationImage(innovation)"
                                    :alt="`Инновация ${innovation}`"
                                    :style="innovationTileStyle(innovationIndex)"
                                    tabindex="0"
                                    class="absolute z-20 h-auto cursor-help rounded-sm object-contain drop-shadow-md"
                                />
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs">
                                <p class="font-semibold">Инновация</p>
                                <p>{{ innovationDescriptions[innovation] }}</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>

                <figcaption v-if="playerState(player.id)?.roundBonus" class="flex items-start gap-3 p-3">
                    <TooltipProvider :delay-duration="150">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span
                                    tabindex="0"
                                    class="relative block w-24 rounded-md shadow-sm"
                                    :class="isRoundBonusActionAvailable(player) ? 'cursor-pointer' : 'cursor-help'"
                                    :role="isRoundBonusActionAvailable(player) ? 'button' : undefined"
                                    @click="isRoundBonusActionAvailable(player) && emit('useRoundBonusAction')"
                                    @keydown.enter="isRoundBonusActionAvailable(player) && emit('useRoundBonusAction')"
                                    @keydown.space.prevent="
                                        isRoundBonusActionAvailable(player) && emit('useRoundBonusAction')
                                    "
                                >
                                    <img
                                        :src="roundBonusImage(roundBonusForPlayer(player.id))"
                                        :alt="`Выбранный бонус раунда игрока ${player.user.name}`"
                                        class="block h-auto w-full rounded-md"
                                    />
                                    <img
                                        v-if="isRoundBonusActionUsed(player.id)"
                                        :src="goldCrossUrl"
                                        alt="Действие использовано"
                                        class="pointer-events-none absolute top-[26%] left-1/2 w-16 -translate-x-1/2 -translate-y-1/2 object-contain drop-shadow-md"
                                    />
                                </span>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs">
                                <p class="font-semibold">Бонус раунда</p>
                                <p>{{ roundBonusDescriptions[roundBonusForPlayer(player.id)!] }}</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>

                    <span class="grid gap-2">
                        <span
                            class="flex flex-wrap gap-1"
                            :aria-label="`Доступные учёные: ${scholarsForPlayer(player.id)}`"
                        >
                            <img
                                v-for="scholarIndex in scholarPoolSizeForPlayer(player.id)"
                                :key="scholarIndex"
                                :src="scientistImage(player.color)"
                                alt=""
                                class="h-auto w-16 object-contain drop-shadow-md transition-opacity"
                                :class="{
                                    'opacity-35': scholarIndex > scholarsForPlayer(player.id),
                                }"
                            />
                        </span>

                        <span class="flex items-center gap-3">
                            <span
                                class="relative grid size-10 place-items-center"
                                :aria-label="`Монеты: ${coinsForPlayer(player.id)}`"
                            >
                                <img :src="coinUrl" alt="" class="absolute inset-0 size-full drop-shadow-md" />
                                <span class="relative z-10 font-bold text-amber-950">
                                    {{ coinsForPlayer(player.id) }}
                                </span>
                            </span>

                            <span
                                class="relative grid size-10 place-items-center"
                                :aria-label="`Инструменты: ${toolsForPlayer(player.id)}`"
                            >
                                <img
                                    :src="handUrl"
                                    alt=""
                                    class="absolute inset-0 size-full object-contain drop-shadow-md"
                                />
                                <span class="relative z-10 font-bold text-amber-950 drop-shadow-md">
                                    {{ toolsForPlayer(player.id) }}
                                </span>
                            </span>

                            <span
                                v-if="availableBridgesForPlayer(player.id)"
                                class="flex flex-wrap gap-1"
                                :aria-label="`Доступные мосты: ${availableBridgesForPlayer(player.id)}`"
                            >
                                <img
                                    v-for="bridgeIndex in availableBridgesForPlayer(player.id)"
                                    :key="bridgeIndex"
                                    :src="bridgeImage(player.color)"
                                    alt=""
                                    class="h-auto w-16 object-contain drop-shadow-md"
                                />
                            </span>

                            <span
                                v-if="availableAnnexesForPlayer(player.id)"
                                class="flex flex-wrap gap-1"
                                :aria-label="`Доступные пристройки: ${availableAnnexesForPlayer(player.id)}`"
                            >
                                <img
                                    v-for="annexIndex in availableAnnexesForPlayer(player.id)"
                                    :key="annexIndex"
                                    :src="annexUrl"
                                    alt=""
                                    class="h-auto w-12 object-contain drop-shadow-md"
                                />
                            </span>
                        </span>

                        <span
                            v-if="competenciesForPlayer(player.id).length"
                            class="flex flex-wrap gap-2"
                            :aria-label="`Компетенции: ${competenciesForPlayer(player.id).length}`"
                        >
                            <TooltipProvider :delay-duration="150">
                                <Tooltip v-for="competency in competenciesForPlayer(player.id)" :key="competency">
                                    <TooltipTrigger as-child>
                                        <img
                                            :src="competencyImage(competency)"
                                            :alt="`Компетенция ${competency}`"
                                            tabindex="0"
                                            class="size-16 cursor-help object-contain drop-shadow-md"
                                        />
                                    </TooltipTrigger>
                                    <TooltipContent class="max-w-xs">
                                        <p class="font-semibold">Компетенция {{ competency.slice(-2) }}</p>
                                        <p>{{ competencyDescriptions[competency] }}</p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </span>

                        <span
                            v-if="booksForPlayer(player.id).length"
                            class="flex flex-wrap gap-1"
                            :aria-label="`Книги: ${booksForPlayer(player.id).length}`"
                        >
                            <img
                                v-for="(bookImageUrl, bookIndex) in booksForPlayer(player.id)"
                                :key="bookIndex"
                                :src="bookImageUrl"
                                alt=""
                                class="h-auto w-10 object-contain drop-shadow-md"
                            />
                        </span>

                        <span v-if="playerState(player.id)?.townTileIds.length" class="flex flex-wrap gap-1">
                            <img
                                v-for="(townTile, townTileIndex) in playerState(player.id)?.townTileIds"
                                :key="`${townTile}-${townTileIndex}`"
                                :src="
                                    townTileImage(townTile, townTileIndex < (playerState(player.id)?.usedTownKeys ?? 0))
                                "
                                alt="Жетон города"
                                class="h-auto w-14 object-contain drop-shadow-md"
                            />
                        </span>
                    </span>
                </figcaption>
            </figure>
        </div>
    </section>
</template>
