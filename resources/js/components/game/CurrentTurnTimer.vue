<script setup lang="ts">
import { Clock3 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    startedAt: string | null;
}>();

const currentTime = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

const duration = computed(() => {
    if (props.startedAt === null) {
        return '00:00';
    }

    const elapsedSeconds = Math.max(0, Math.floor((currentTime.value - new Date(props.startedAt).getTime()) / 1000));
    const hours = Math.floor(elapsedSeconds / 3600);
    const minutes = Math.floor((elapsedSeconds % 3600) / 60);
    const seconds = elapsedSeconds % 60;
    const paddedMinutes = String(minutes).padStart(2, '0');
    const paddedSeconds = String(seconds).padStart(2, '0');

    return hours > 0 ? `${hours}:${paddedMinutes}:${paddedSeconds}` : `${paddedMinutes}:${paddedSeconds}`;
});

onMounted(() => {
    currentTime.value = Date.now();
    timer = setInterval(() => {
        currentTime.value = Date.now();
    }, 1000);
});

onBeforeUnmount(() => {
    if (timer !== undefined) {
        clearInterval(timer);
    }
});
</script>

<template>
    <span
        class="flex items-center gap-1.5 font-mono text-sm font-medium text-muted-foreground tabular-nums"
        :aria-label="`Текущий ход длится ${duration}`"
        title="Длительность текущего хода"
    >
        <Clock3 class="size-4" aria-hidden="true" />
        <span class="mt-0.5 leading-none">{{ duration }}</span>
    </span>
</template>
