<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { PalaceAbility } from '@/types';

defineProps<{
    palaces: PalaceAbility[];
    descriptions: Record<PalaceAbility, string>;
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
    <section v-if="palaces.length" class="grid gap-3 rounded-xl bg-card/50 p-2 shadow-sm">
        <TooltipProvider :delay-duration="150">
            <div class="grid grid-cols-4 gap-2">
                <Tooltip v-for="palace in palaces" :key="palace">
                    <TooltipTrigger as-child>
                        <img
                            :src="palaceImage(palace)"
                            :alt="`Жетон Дворца ${Number(palace.slice(-2))}`"
                            class="h-auto w-full rounded-md shadow-sm drop-shadow-[-2px_2px_2px_rgba(0,0,0,0.45)]"
                        />
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        <p class="font-semibold">Жетон Дворца {{ Number(palace.slice(-2)) }}</p>
                        <p>{{ descriptions[palace] }}</p>
                    </TooltipContent>
                </Tooltip>
            </div>
        </TooltipProvider>
    </section>
</template>
