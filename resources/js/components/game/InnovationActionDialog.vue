<script setup lang="ts">
import InnovationActionController from '@/actions/App/Http/Controllers/InnovationActionController';
import Form from '@/components/game/GameActionForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { Innovation } from '@/types';

defineProps<{
    gameId: number;
    innovation: Innovation | null;
    description: string;
}>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Активировать инновацию?</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <Form
                v-if="innovation !== null"
                v-bind="InnovationActionController.form(gameId)"
                #default="{ errors, processing }"
                class="grid gap-4"
                @success="open = false"
            >
                <input type="hidden" name="innovation" :value="innovation" />
                <InputError :message="errors.innovation ?? errors.game" />
                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Отмена</Button>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Активация…' : 'Активировать' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
