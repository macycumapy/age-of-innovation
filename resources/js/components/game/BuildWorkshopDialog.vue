<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import TerraformWorkshopController from '@/actions/App/Http/Controllers/Game/TerraformWorkshopController';
import WorkshopController from '@/actions/App/Http/Controllers/Game/WorkshopController';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import type { BoardHexState, BoardState, PlayerColor } from '@/types';
import { hasAdjacentOpponent } from '@/lib/buildingAdjacency';
import toolUrl from '../../../images/token_parts/cube.png';
import coinUrl from '../../../images/token_parts/gold_medallion.png';

const props = withDefaults(defineProps<{
    gameId: number;
    board: BoardState;
    playerId: number | null;
    hexId?: string | null;
    hexIds?: string[];
    hexes?: BoardHexState[];
    playerColor: PlayerColor | null;
    afterTerraforming?: boolean;
    toolCost?: number;
    coinCost?: number;
}>(), {
    hexId: null,
    hexIds: () => [],
    hexes: () => [],
    afterTerraforming: false,
    toolCost: 1,
    coinCost: 2,
});

const isOpen = defineModel<boolean>('open', { default: false });
const dialogOpen = computed({
    get: () => isOpen.value,
    set: (open: boolean) => {
        isOpen.value = open;
    },
});
const selectedHexId = ref(props.hexId ?? props.hexIds[0] ?? '');
const hasNeighboringOpponent = computed(() => hasAdjacentOpponent(props.board, selectedHexId.value, props.playerId));
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
        <DialogContent class="sm:max-w-md">
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
                            <img :src="toolUrl" alt="" class="size-6 object-contain" /> {{ toolCost }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-semibold">
                            <img :src="coinUrl" alt="" class="size-6 object-contain" /> {{ coinCost }}
                        </span>
                    </div>
                </div>

                <InputError :message="errors.build ?? errors.building ?? errors.hex_id ?? errors.game" />

                <Alert v-if="hasNeighboringOpponent" class="border-amber-400 bg-amber-50 text-amber-950 dark:border-amber-500 dark:bg-amber-950/60 dark:text-amber-100">
                    <TriangleAlert aria-hidden="true" />
                    <AlertDescription class="font-medium text-amber-950 dark:text-amber-100">
                        Если хотя бы один сосед примет Силу, отменить ход будет невозможно.
                    </AlertDescription>
                </Alert>

                <DialogFooter>
                    <DialogClose as-child>
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
