<script setup lang="ts">
import { computed } from 'vue';
import competencyBoardUrl from '../../../images/competency_board.png';
import twoPlayerInventionBoardUrl from '../../../images/invention_board_2.jpg';
import fourPlayerInventionBoardUrl from '../../../images/invention_board_4.jpg';

const props = defineProps<{
    playerCount: number;
}>();

const inventionBoardUrl = computed(() =>
    props.playerCount >= 4
        ? fourPlayerInventionBoardUrl
        : twoPlayerInventionBoardUrl,
);
</script>

<template>
    <div class="grid gap-4">
        <div
            class="overflow-hidden rounded-xl border border-border shadow-inner"
        >
            <div
                class="overflow-hidden rounded-xl border border-border shadow-inner"
            >
                <img
                    :src="inventionBoardUrl"
                    alt="Планшет инноваций"
                    class="block h-auto w-full"
                />
            </div>
            <img
                :src="competencyBoardUrl"
                alt="Планшет компетенций"
                class="block h-auto w-full"
            />
        </div>
    </div>
</template>
