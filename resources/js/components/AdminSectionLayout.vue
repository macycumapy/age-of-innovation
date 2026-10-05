<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { edit as settingsEdit } from '@/routes/admin/settings';
import { index as usersIndex } from '@/routes/admin/users';

defineProps<{ section: 'settings' | 'users' }>();
</script>

<template>
    <div class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-2xl font-semibold">Админка</h1>
        <nav aria-label="Разделы админки" class="flex flex-wrap gap-2">
            <Button :variant="section === 'settings' ? 'secondary' : 'ghost'" as-child>
                <Link :href="settingsEdit()" :aria-current="section === 'settings' ? 'page' : undefined"
                    >Настройки</Link
                >
            </Button>
            <Button :variant="section === 'users' ? 'secondary' : 'ghost'" as-child>
                <Link :href="usersIndex()" :aria-current="section === 'users' ? 'page' : undefined">Пользователи</Link>
            </Button>
        </nav>
        <slot />
    </div>
</template>
