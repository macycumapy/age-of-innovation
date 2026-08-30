<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const props = withDefaults(defineProps<{
    id?: string;
    name?: string;
    min?: number;
    max?: number;
    step?: number;
    disabled?: boolean;
    required?: boolean;
}>(), {
    min: Number.NEGATIVE_INFINITY,
    max: Number.POSITIVE_INFINITY,
    step: 1,
    disabled: false,
    required: false,
});

const modelValue = defineModel<number>({ default: 0 });
const canDecrease = computed(() => !props.disabled && modelValue.value > props.min);
const canIncrease = computed(() => !props.disabled && modelValue.value < props.max);

function clamp(value: number): number {
    return Math.min(props.max, Math.max(props.min, value));
}

function updateValue(value: string | number): void {
    const parsedValue = Number(value);

    if (!Number.isNaN(parsedValue)) {
        modelValue.value = clamp(parsedValue);
    }
}

function decrease(): void {
    modelValue.value = clamp(modelValue.value - props.step);
}

function increase(): void {
    modelValue.value = clamp(modelValue.value + props.step);
}
</script>

<template>
    <div class="flex h-9 w-full items-stretch">
        <Button
            type="button"
            variant="outline"
            size="icon"
            class="h-9 shrink-0 rounded-r-none"
            :disabled="!canDecrease"
            aria-label="Уменьшить"
            @click="decrease"
        >
            <Minus class="size-4" />
        </Button>
        <Input
            :id="id"
            :model-value="modelValue"
            :name="name"
            type="number"
            :min="Number.isFinite(min) ? min : undefined"
            :max="Number.isFinite(max) ? max : undefined"
            :step="step"
            :disabled="disabled"
            :required="required"
            class="h-9 rounded-none border-x-0 px-1 text-center shadow-none focus-visible:border-input focus-visible:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            @update:model-value="updateValue"
        />
        <Button
            type="button"
            variant="outline"
            size="icon"
            class="h-9 shrink-0 rounded-l-none"
            :disabled="!canIncrease"
            aria-label="Увеличить"
            @click="increase"
        >
            <Plus class="size-4" />
        </Button>
    </div>
</template>
