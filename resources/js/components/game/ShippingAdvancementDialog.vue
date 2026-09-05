<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ShippingAdvancementController from '@/actions/App/Http/Controllers/ShippingAdvancementController';
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
} from '@/components/ui/dialog';

defineProps<{ gameId: number }>();
const isOpen = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-bind="ShippingAdvancementController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="isOpen = false"
            >
                <DialogHeader>
                    <DialogTitle>Прокачать навигацию?</DialogTitle>
                    <DialogDescription
                        >Потратить 4 монеты и 1 учёного, чтобы повысить навигацию на один уровень.</DialogDescription
                    >
                </DialogHeader>
                <InputError :message="errors.shipping ?? errors.game" />
                <DialogFooter class="gap-2">
                    <DialogClose as-child><Button type="button" variant="outline">Отмена</Button></DialogClose>
                    <Button type="submit" :disabled="processing">{{
                        processing ? 'Выполнение…' : 'Подтвердить'
                    }}</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
