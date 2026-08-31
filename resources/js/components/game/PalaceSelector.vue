<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { PalaceAbility } from '@/types';

withDefaults(
    defineProps<{
        palaces: PalaceAbility[];
        descriptions: Record<PalaceAbility, string>;
        modelValue?: PalaceAbility | null;
        disabled?: boolean;
    }>(),
    {
        modelValue: null,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [palace: PalaceAbility];
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
    <TooltipProvider :delay-duration="150">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <Tooltip v-for="palace in palaces" :key="palace">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="rounded-lg border bg-background/70 p-2 transition hover:border-primary disabled:cursor-not-allowed disabled:opacity-60"
                        :class="modelValue === palace ? 'border-primary ring-2 ring-primary/40' : ''"
                        :disabled="disabled"
                        :aria-pressed="modelValue === palace"
                        @click="emit('update:modelValue', palace)"
                    >
                        <img
                            :src="palaceImage(palace)"
                            :alt="`Жетон Дворца ${Number(palace.slice(-2))}`"
                            class="h-auto w-full rounded-md shadow-sm"
                        />
                    </button>
                </TooltipTrigger>
                <TooltipContent class="max-w-xs">
                    <p class="font-semibold">Жетон Дворца {{ Number(palace.slice(-2)) }}</p>
                    <p>{{ descriptions[palace] }}</p>
                </TooltipContent>
            </Tooltip>
        </div>
    </TooltipProvider>
</template>
