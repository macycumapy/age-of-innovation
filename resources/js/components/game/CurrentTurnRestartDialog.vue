<script setup lang="ts">
import { ref } from 'vue';
import { RotateCcw } from '@lucide/vue';
import CurrentTurnRestartController from '@/actions/App/Http/Controllers/CurrentTurnRestartController';
import Form from '@/components/game/GameActionForm.vue';
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

defineProps<{ gameId: number }>();

const isOpen = ref(false);
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogTrigger as-child>
            <Button type="button" variant="outline">
                <RotateCcw class="size-4" />
                Перезапустить ход
            </Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Перезапустить текущий ход?</DialogTitle>
                <DialogDescription>
                    Все действия после последней контрольной точки будут отменены. Это действие нельзя отменить.
                </DialogDescription>
            </DialogHeader>

            <Form
                v-bind="CurrentTurnRestartController.form(gameId)"
                #default="{ processing }"
                @success="isOpen = false"
            >
                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline" :disabled="processing">Не отменять</Button>
                    </DialogClose>
                    <Button type="submit" variant="destructive" :disabled="processing">
                        <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                        {{ processing ? 'Откат…' : 'Откатить ход' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
