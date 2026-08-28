<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { Competency } from '@/types';

const props = withDefaults(
    defineProps<{
        competencies: Competency[];
        descriptions: Record<Competency, string>;
        modelValue?: Competency | null;
        disabled?: boolean;
    }>(),
    {
        modelValue: null,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [competency: Competency];
}>();

const competencyImages = import.meta.glob('../../../images/competencies/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

function competencyImage(competency: Competency): string {
    return competencyImages[`../../../images/competencies/${competency}.png`];
}
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <div
            class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6"
        >
            <Tooltip v-for="competency in props.competencies" :key="competency">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="grid rounded-lg border bg-background/70 p-2 transition hover:border-primary disabled:cursor-not-allowed disabled:opacity-60"
                        :class="modelValue === competency ? 'border-primary ring-2 ring-primary/40' : ''"
                        :disabled="disabled"
                        :aria-pressed="modelValue === competency"
                        @click="emit('update:modelValue', competency)"
                    >
                        <img
                            :src="competencyImage(competency)"
                            :alt="`Компетенция ${competency.slice(-2)}`"
                            class="aspect-square w-full object-contain drop-shadow-md"
                        />
                    </button>
                </TooltipTrigger>
                <TooltipContent class="max-w-xs">
                    <p class="font-semibold">Компетенция {{ competency.slice(-2) }}</p>
                    <p>{{ descriptions[competency] }}</p>
                </TooltipContent>
            </Tooltip>
        </div>
    </TooltipProvider>
</template>
