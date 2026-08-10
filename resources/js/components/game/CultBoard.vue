<script setup lang="ts">
import cultBoardUrl from '../../../images/cult_board.png';
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border shadow-inner">
        <img
            :src="cultBoardUrl"
            alt="Поле культов"
            class="block h-auto w-full"
        />
    </div>
</template>
