<script setup lang="ts">
import type { PalaceAbility } from '@/types';

defineProps<{
    palaces: PalaceAbility[];
}>();

const palaceImages = import.meta.glob('../../../images/palaces/*.jpg', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

function palaceImage(palace: PalaceAbility): string {
    return palaceImages[`../../../images/palaces/${palace}.jpg`];
}
</script>

<template>
    <section
        v-if="palaces.length"
        class="grid gap-3 rounded-xl bg-card shadow-sm"
    >
        <div class="grid grid-cols-3 gap-2">
            <img
                v-for="palace in palaces"
                :key="palace"
                :src="palaceImage(palace)"
                :alt="`Крепость ${Number(palace.slice(-2))}`"
                class="h-auto w-full rounded-md border border-border shadow-sm"
            />
        </div>
    </section>
</template>
