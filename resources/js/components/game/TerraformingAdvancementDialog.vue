<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed } from 'vue';
import TerraformingAdvancementController from '@/actions/App/Http/Controllers/TerraformingAdvancementController';
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
import type { PlayerColor } from '@/types';

const props = defineProps<{ gameId: number; playerColor: PlayerColor | null }>();
const isOpen = defineModel<boolean>('open', { required: true });
const coinCost = computed(() => (props.playerColor === 'brown' ? 1 : 5));
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-bind="TerraformingAdvancementController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="isOpen = false"
            >
                <DialogHeader>
                    <DialogTitle>Прокачать лопату?</DialogTitle>
                    <DialogDescription
                        >Потратить 1 инструмент, {{ coinCost }} золота и 1 учёного, чтобы повысить эффективность
                        преобразования на один уровень.</DialogDescription
                    >
                </DialogHeader>
                <InputError :message="errors.terraforming ?? errors.game" />
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
