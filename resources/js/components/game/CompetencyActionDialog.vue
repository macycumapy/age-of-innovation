<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CompetencyActionController from '@/actions/App/Http/Controllers/CompetencyActionController';
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

function actionSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-bind="CompetencyActionController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="actionSucceeded"
            >
                <DialogHeader>
                    <DialogTitle>Активировать Компетенцию Силы?</DialogTitle>
                    <DialogDescription>Получить 4 Силы.</DialogDescription>
                </DialogHeader>

                <InputError :message="errors.competency ?? errors.game" />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Выполнение…' : 'Получить 4 Силы' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
