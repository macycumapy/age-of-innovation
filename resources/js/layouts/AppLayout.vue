<script setup lang="ts">
import AppLayout from '@/layouts/app/AppHeaderLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [], fullWidth = false } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
    fullWidth?: boolean;
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :full-width="fullWidth">
        <slot />
    </AppLayout>
</template>
