<script setup lang="ts">
defineProps<{
    name: string;
    options: { value: string; label: string }[];
    disabled?: boolean;
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <div class="grid auto-cols-fr grid-flow-col gap-1 rounded-lg border border-input bg-muted p-1">
        <label
            v-for="option in options"
            :key="option.value"
            class="relative flex cursor-pointer items-center justify-center rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors has-checked:bg-background has-checked:text-foreground has-checked:shadow-sm has-focus-visible:ring-2 has-focus-visible:ring-ring has-disabled:cursor-wait has-disabled:opacity-50"
        >
            <input
                v-model="model"
                type="radio"
                :name="name"
                :value="option.value"
                :disabled="disabled"
                class="sr-only"
            />
            {{ option.label }}
        </label>
    </div>
</template>
