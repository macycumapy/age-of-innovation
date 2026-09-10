<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    backgroundImage?: string;
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="isolate overflow-x-clip bg-cover bg-fixed bg-center bg-no-repeat"
            :style="backgroundImage ? { backgroundImage: `url(${backgroundImage})` } : undefined"
        >
            <div v-if="backgroundImage" class="absolute inset-0 -z-10 bg-background opacity-10" aria-hidden="true" />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
