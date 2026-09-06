<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed, ref, watch } from 'vue';
import TerraformWorkshopController from '@/actions/App/Http/Controllers/TerraformWorkshopController';
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
import type { BoardHexState, PlayerColor } from '@/types';
import toolUrl from '../../../images/token_parts/cube.png';
import coinUrl from '../../../images/token_parts/gold_medallion.png';

const props = withDefaults(defineProps<{
    gameId: number;
    hexId?: string | null;
    hexIds?: string[];
    hexes?: BoardHexState[];
    playerColor: PlayerColor | null;
    afterTerraforming?: boolean;
}>(), {
    hexId: null,
    hexIds: () => [],
    hexes: () => [],
    afterTerraforming: false,
});

const isOpen = defineModel<boolean>('open', { default: false });
const dialogOpen = computed({
    get: () => props.afterTerraforming || isOpen.value,
    set: (open: boolean) => {
        if (!props.afterTerraforming) {
            isOpen.value = open;
        }
    },
});
const selectedHexId = ref(props.hexId ?? props.hexIds[0] ?? '');
const selectedHexes = computed(() => props.hexes.filter((hex) => props.hexIds.includes(hex.id)));
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

watch(
    () => [props.hexId, props.hexIds] as const,
    () => {
        selectedHexId.value = props.hexId ?? props.hexIds[0] ?? '';
    },
);
</script>

<template>
    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md" :show-close-button="!afterTerraforming">
            <DialogHeader>
                <DialogTitle>Построить дом?</DialogTitle>
                <DialogDescription>
                    {{ afterTerraforming
                        ? 'Преобразованная земля стала родной. Можно построить дом.'
                        : 'Подтвердите строительство на выбранной родной территории.' }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="selectedHexes.length > 1" class="grid grid-cols-2 gap-2">
                <button
                    v-for="hex in selectedHexes"
                    :key="hex.id"
                    type="button"
                    class="rounded-md border px-3 py-2 text-sm transition-colors hover:bg-muted"
                    :class="selectedHexId === hex.id ? 'border-primary ring-1 ring-primary' : ''"
                    @click="selectedHexId = hex.id"
                >
                    Ячейка {{ hex.q }}:{{ hex.r }}
                </button>
            </div>

            <Form
                v-bind="afterTerraforming
                    ? TerraformWorkshopController.form(gameId)
                    : WorkshopController.form(gameId)"
                #default="{ errors, processing }"
                class="grid gap-4"
                @success="buildSucceeded"
            >
                <input type="hidden" name="hex_id" :value="selectedHexId" />

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

                <InputError :message="errors.build ?? errors.building ?? errors.hex_id ?? errors.game" />

                <DialogFooter>
                    <Button
                        v-if="afterTerraforming"
                        type="submit"
                        name="build"
                        value="0"
                        variant="outline"
                        :disabled="processing"
                    >
                        Не строить
                    </Button>
                    <DialogClose v-else as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :name="afterTerraforming ? 'build' : undefined"
                        :value="afterTerraforming ? '1' : undefined"
                        :disabled="processing || selectedHexId === ''"
                    >
                        {{ processing ? 'Строительство…' : 'Подтвердить строительство' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
