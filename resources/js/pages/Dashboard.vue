<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BoardMap from '@/components/game/BoardMap.vue';
import { dashboard } from '@/routes';
import type { BoardState } from '@/types';

defineProps<{
    board: BoardState;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Игровая карта',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Игровая карта" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-hidden p-4">
        <div>
            <h1 class="text-2xl font-semibold">Игровая карта</h1>
            <p class="text-sm text-muted-foreground">
                Статическое отображение стороны поля для 3–5 игроков
            </p>
        </div>

        <BoardMap :board="board" />
    </div>
</template>
