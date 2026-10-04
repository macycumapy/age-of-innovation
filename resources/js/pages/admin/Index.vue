<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AdminSettingsController from '@/actions/App/Http/Controllers/AdminSettingsController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { index as adminIndex } from '@/routes/admin';
import type { GameBotDifficulty, Settings } from '@/types';

const props = defineProps<{
    settings: Settings;
    difficultyOptions: { value: GameBotDifficulty; label: string }[];
}>();

const form = useForm({
    bots_enabled: props.settings.bots.enabled,
    bot_difficulties: [...props.settings.bots.available_difficulties],
});

watch(
    () => props.settings,
    (settings) => {
        form.defaults({
            bots_enabled: settings.bots.enabled,
            bot_difficulties: [...settings.bots.available_difficulties],
        });
        form.reset();
    },
);

function toggleDifficulty(difficulty: GameBotDifficulty, checked: boolean | 'indeterminate'): void {
    form.bot_difficulties =
        checked === true
            ? [...form.bot_difficulties.filter((value) => value !== difficulty), difficulty]
            : form.bot_difficulties.filter((value) => value !== difficulty);
}

function save(): void {
    form.submit(AdminSettingsController(), { preserveScroll: true });
}

function cancel(): void {
    form.reset();
    form.clearErrors();
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Админка', href: adminIndex() }],
    },
});
</script>

<template>
    <div class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 p-4">
        <Head title="Админка" />
        <h1 class="text-2xl font-semibold">Админка</h1>
        <Card>
            <CardHeader>
                <CardTitle>Настройки приложения</CardTitle>
                <CardDescription>Общие настройки и доступные возможности игры.</CardDescription>
            </CardHeader>
            <CardContent>
                <form class="grid gap-6" @submit.prevent="save">
                    <div class="grid gap-2">
                        <label for="bots-enabled" class="flex items-center gap-3 text-sm font-medium">
                            <Checkbox id="bots-enabled" v-model="form.bots_enabled" :disabled="form.processing" />
                            Включить ботов
                        </label>
                        <InputError :message="form.errors.bots_enabled" />
                    </div>
                    <fieldset class="grid gap-3" :disabled="form.processing">
                        <legend class="mb-3 text-sm font-medium">Доступные сложности ботов</legend>
                        <label
                            v-for="option in difficultyOptions"
                            :key="option.value"
                            :for="`difficulty-${option.value}`"
                            class="flex items-center gap-3 text-sm"
                        >
                            <Checkbox
                                :id="`difficulty-${option.value}`"
                                :model-value="form.bot_difficulties.includes(option.value)"
                                :disabled="form.processing"
                                @update:model-value="toggleDifficulty(option.value, $event)"
                            />
                            {{ option.label }}
                        </label>
                        <InputError :message="form.errors.bot_difficulties" />
                        <InputError
                            v-for="(_, index) in form.bot_difficulties"
                            :key="index"
                            :message="form.errors[`bot_difficulties.${index}`]"
                        />
                    </fieldset>
                    <div class="flex flex-wrap gap-3">
                        <Button type="submit" :disabled="form.processing || !form.isDirty">
                            {{ form.processing ? 'Сохранение…' : 'Сохранить' }}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing || !form.isDirty"
                            @click="cancel"
                        >
                            Отменить изменения
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
