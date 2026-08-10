<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    fullWidth?: boolean;
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    fullWidth: false,
});
</script>

<template>
    <AppShell variant="header">
        <AppHeader :breadcrumbs="breadcrumbs" />
        <AppContent
            variant="header"
            :class="fullWidth ? 'max-w-none' : undefined"
        >
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
