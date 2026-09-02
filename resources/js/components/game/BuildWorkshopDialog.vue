<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import WorkshopController from '@/actions/App/Http/Controllers/WorkshopController';
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
import toolUrl from '../../../images/token_parts/cube.png';
import coinUrl from '../../../images/token_parts/gold_medallion.png';

const props = defineProps<{
    gameId: number;
    hexId: string | null;
    playerColor: PlayerColor | null;
}>();

const isOpen = defineModel<boolean>('open', { default: false });
const workshopImages = import.meta.glob<string>('../../../images/buildings/*/workshop.png', {
    eager: true,
    import: 'default',
    query: '?url',
});

function workshopImage(): string {
    return workshopImages[`../../../images/buildings/${props.playerColor ?? 'white'}/workshop.png`] ?? '';
}

function buildSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Построить дом?</DialogTitle>
                <DialogDescription>Подтвердите строительство на выбранной родной территории.</DialogDescription>
            </DialogHeader>

            <Form
                v-bind="WorkshopController.form(gameId)"
                #default="{ errors, processing }"
                class="grid gap-4"
                @success="buildSucceeded"
            >
                <input type="hidden" name="hex_id" :value="hexId ?? ''" />

                <div class="grid justify-items-center gap-3 rounded-lg border p-4">
                    <img :src="workshopImage()" alt="Дом" class="h-24 w-28 object-contain" />
                    <div class="flex items-center gap-4" aria-label="Стоимость строительства">
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-semibold">
                            <img :src="toolUrl" alt="" class="size-6 object-contain" /> 1
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-semibold">
                            <img :src="coinUrl" alt="" class="size-6 object-contain" /> 2
                        </span>
                    </div>
                </div>

                <InputError :message="errors.building ?? errors.hex_id" />

                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing || hexId === null">
                        {{ processing ? 'Строительство…' : 'Подтвердить строительство' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
