<script setup lang="ts">
import { computed } from 'vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { Competency } from '@/types';

const props = withDefaults(
    defineProps<{
        competencies: Competency[];
        boardOrder: Competency[];
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

const competencySlots = computed(() => {
    const availableCompetencies = new Set(props.competencies);

    return props.boardOrder.map((competency) => (availableCompetencies.has(competency) ? competency : null));
});

const cultBackgroundClasses = [
    'border-amber-500/60 bg-amber-400/30 dark:bg-amber-400/25',
    'border-blue-500/60 bg-blue-500/25 dark:bg-blue-500/30',
    'border-amber-800/60 bg-amber-800/25 dark:bg-amber-700/30',
    'border-teal-200/80 bg-teal-100/70 dark:border-teal-200/30 dark:bg-teal-100/15',
] as const;

function cultBackgroundClass(slotIndex: number): string {
    return cultBackgroundClasses[slotIndex % cultBackgroundClasses.length];
}

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
        <div class="grid w-fit grid-cols-4 gap-2">
            <template v-for="(competency, index) in competencySlots" :key="competency ?? `empty-${index}`">
                <Tooltip v-if="competency !== null">
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="grid size-24 rounded-lg border p-1.5 transition hover:border-primary disabled:cursor-not-allowed disabled:opacity-60"
                            :class="[
                                cultBackgroundClass(index),
                                modelValue === competency ? 'border-primary ring-2 ring-primary/40' : '',
                            ]"
                            :disabled="disabled"
                            :aria-pressed="modelValue === competency"
                            @click="emit('update:modelValue', competency)"
                        >
                            <img
                                :src="competencyImage(competency)"
                                :alt="`Компетенция ${competency.slice(-2)}`"
                                class="size-full object-contain drop-shadow-md"
                            />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        <p class="font-semibold">Компетенция {{ competency.slice(-2) }}</p>
                        <p>{{ descriptions[competency] }}</p>
                    </TooltipContent>
                </Tooltip>
                <div v-else aria-hidden="true" class="size-24" />
            </template>
        </div>
    </TooltipProvider>
</template>
