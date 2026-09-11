<script setup lang="ts">
import backgroundLoginUrl from '../../../images/background_login.jpg';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-cover bg-center p-6 md:p-10"
        :style="{ backgroundImage: `url(${backgroundLoginUrl})` }"
    >
        <div
            class="w-full max-w-sm rounded-2xl border border-white/30 bg-background/90 p-6 shadow-2xl backdrop-blur-xs md:p-8 dark:border-white/10 dark:bg-background/60"
        >
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
