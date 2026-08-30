<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CurrentTurnFinishController from '@/actions/App/Http/Controllers/CurrentTurnFinishController';
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

defineProps<{
    gameId: number;
}>();

const isOpen = defineModel<boolean>('open', { default: false });

function finishSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent :show-close-button="false">
            <DialogHeader>
                <DialogTitle>Завершить ход?</DialogTitle>
                <DialogDescription>
                    После подтверждения ход перейдёт к следующему игроку.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2 sm:gap-0">
                <DialogClose as-child>
                    <Button type="button" variant="outline">
                        Отмена
                    </Button>
                </DialogClose>

                <Form
                    v-bind="CurrentTurnFinishController.form(gameId)"
                    #default="{ processing }"
                    @success="finishSucceeded"
                >
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Завершение…' : 'Завершить ход' }}
                    </Button>
                </Form>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
