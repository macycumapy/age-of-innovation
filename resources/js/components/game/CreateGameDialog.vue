<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import GameController from '@/actions/App/Http/Controllers/GameController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

const isOpen = ref(false);
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogTrigger as-child>
            <Button>Новая игра</Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-lg">
            <Form
                v-bind="GameController.store.form()"
                reset-on-success
                class="contents"
                #default="{ errors, processing }"
                @success="isOpen = false"
            >
                <DialogHeader>
                    <DialogTitle>Новая игра</DialogTitle>
                    <DialogDescription>Выберите сторону игрового поля.</DialogDescription>
                </DialogHeader>

                <fieldset class="grid gap-2">
                    <legend class="text-sm font-medium">Количество игроков</legend>
                    <div class="flex flex-wrap gap-2">
                        <label
                            class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm shadow-xs transition-colors has-checked:border-primary has-checked:bg-primary/10 has-checked:text-primary has-focus-visible:ring-3 has-focus-visible:ring-ring/50"
                        >
                            <input
                                type="radio"
                                name="map_variant"
                                value="three_to_five_players"
                                class="size-4 accent-primary"
                                checked
                            />
                            3–5 игроков
                        </label>
                        <label
                            class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm shadow-xs transition-colors has-checked:border-primary has-checked:bg-primary/10 has-checked:text-primary has-focus-visible:ring-3 has-focus-visible:ring-ring/50"
                        >
                            <input
                                type="radio"
                                name="map_variant"
                                value="one_to_three_players"
                                class="size-4 accent-primary"
                            />
                            1–3 игрока
                        </label>
                    </div>
                    <InputError :message="errors.map_variant" />
                </fieldset>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Создание…' : 'Создать игру' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
