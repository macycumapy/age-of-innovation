<script setup lang="ts">
import { computed } from 'vue';
import type { BoardHexState, GamePlayerSummary } from '@/types';

type PositionedHex = BoardHexState & { x: number; y: number };
type Bridge = { fromHexId: string; toHexId: string; ownerPlayerId: number };

const props = defineProps<{
    hexes: PositionedHex[];
    bridges: Bridge[];
    players: GamePlayerSummary[];
    pendingBridge?: Bridge | null;
}>();

const bridgeImages = import.meta.glob('../../../images/buildings/*/bridge.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const bridgeTokenSize = 120;
const bridgeImageAxisAngle = 22.5;

const renderedBridges = computed(() =>
    [
        ...props.bridges.map((bridge) => ({ ...bridge, pending: false })),
        ...(props.pendingBridge ? [{ ...props.pendingBridge, pending: true }] : []),
    ].flatMap((bridge) => {
        const fromHex = props.hexes.find((hex) => hex.id === bridge.fromHexId);
        const toHex = props.hexes.find((hex) => hex.id === bridge.toHexId);
        const color = props.players.find((player) => player.id === bridge.ownerPlayerId)?.color;

        if (fromHex === undefined || toHex === undefined || color === null || color === undefined) {
            return [];
        }

        return [
            {
                ...bridge,
                x: (fromHex.x + toHex.x) / 2,
                y: (fromHex.y + toHex.y) / 2,
                angle: (Math.atan2(toHex.y - fromHex.y, toHex.x - fromHex.x) * 180) / Math.PI - bridgeImageAxisAngle,
                image: bridgeImages[`../../../images/buildings/${color}/bridge.png`],
            },
        ];
    }),
);
</script>

<template>
    <g aria-label="Мосты">
        <TransitionGroup name="bridge">
            <image
                v-for="bridge in renderedBridges"
                :key="`${bridge.pending ? 'pending' : 'built'}-${bridge.fromHexId}-${bridge.toHexId}-${bridge.ownerPlayerId}`"
                :href="bridge.image"
                :x="bridge.x - bridgeTokenSize / 2"
                :y="bridge.y - bridgeTokenSize / 2"
                :width="bridgeTokenSize"
                :height="bridgeTokenSize"
                preserveAspectRatio="xMidYMid meet"
                :transform="`rotate(${bridge.angle} ${bridge.x} ${bridge.y})`"
                class="pointer-events-none drop-shadow-md"
            />
        </TransitionGroup>
    </g>
</template>

<style scoped>
.bridge-enter-active,
.bridge-leave-active {
    transition:
        opacity 360ms ease-in-out,
        clip-path 480ms cubic-bezier(0.22, 1, 0.36, 1),
        filter 480ms ease-out;
}

.bridge-enter-from {
    opacity: 0;
    clip-path: inset(0 50% 0 50%);
    filter: brightness(1.65) drop-shadow(0 0 10px rgb(250 204 21 / 0.9));
}

.bridge-enter-to,
.bridge-leave-from {
    clip-path: inset(0);
}

.bridge-leave-to {
    opacity: 0;
    clip-path: inset(0 50% 0 50%);
}

@media (prefers-reduced-motion: reduce) {
    .bridge-enter-active,
    .bridge-leave-active {
        transition-duration: 0.01ms;
    }
}
</style>
