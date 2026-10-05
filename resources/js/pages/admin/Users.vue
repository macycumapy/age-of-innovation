<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminSectionLayout from '@/components/AdminSectionLayout.vue';
import AdminUserPasswordDialog from '@/components/AdminUserPasswordDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { index as usersIndex } from '@/routes/admin/users';

defineProps<{
    users: {
        data: { id: number; name: string; email: string; createdAt: string | null }[];
        meta: { current_page: number; last_page: number; total: number };
    };
}>();

const formatDate = (date: string | null): string =>
    date ? new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(new Date(date)) : '—';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Пользователи', href: usersIndex() }],
    },
});
</script>

<template>
    <AdminSectionLayout section="users">
        <Head title="Пользователи — Админка" />
        <Card>
            <CardHeader>
                <CardTitle>Пользователи</CardTitle>
                <CardDescription>Всего: {{ users.meta.total }}</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <p v-if="users.data.length === 0" class="text-sm text-muted-foreground">Пользователи не найдены.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-muted-foreground">
                                <th scope="col" class="px-3 py-2 font-medium">Имя</th>
                                <th scope="col" class="px-3 py-2 font-medium">Email</th>
                                <th scope="col" class="px-3 py-2 font-medium">Регистрация</th>
                                <th scope="col" class="px-3 py-2 font-medium">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in users.data" :key="user.id" class="border-b last:border-0">
                                <td class="px-3 py-3">{{ user.name }}</td>
                                <td class="px-3 py-3 break-all">{{ user.email }}</td>
                                <td class="px-3 py-3 whitespace-nowrap">{{ formatDate(user.createdAt) }}</td>
                                <td class="px-3 py-3">
                                    <AdminUserPasswordDialog
                                        :user-id="user.id"
                                        :user-name="user.name"
                                        :user-email="user.email"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <nav
                    v-if="users.meta.last_page > 1"
                    aria-label="Страницы пользователей"
                    class="flex flex-wrap items-center justify-between gap-3"
                >
                    <Button v-if="users.meta.current_page > 1" variant="outline" as-child>
                        <Link
                            :href="usersIndex({ query: { page: users.meta.current_page - 1 } })"
                            :only="['users']"
                            preserve-state
                            preserve-scroll
                            >Назад</Link
                        >
                    </Button>
                    <span class="text-sm text-muted-foreground"
                        >{{ users.meta.current_page }} / {{ users.meta.last_page }}</span
                    >
                    <Button v-if="users.meta.current_page < users.meta.last_page" variant="outline" as-child>
                        <Link
                            :href="usersIndex({ query: { page: users.meta.current_page + 1 } })"
                            :only="['users']"
                            preserve-state
                            preserve-scroll
                            >Далее</Link
                        >
                    </Button>
                </nav>
            </CardContent>
        </Card>
    </AdminSectionLayout>
</template>
