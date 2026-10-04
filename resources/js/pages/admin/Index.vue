<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { index as adminIndex } from '@/routes/admin';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Админка', href: adminIndex() }],
    },
});
</script>

<template>
    <div class="flex flex-1 flex-col gap-4 p-4">
        <Head title="Админка" />
        <h1 class="text-2xl font-semibold">Админка</h1>
        <p class="text-sm text-muted-foreground">Панель администрирования. Разделы управления появятся здесь.</p>
    </div>
</template>
