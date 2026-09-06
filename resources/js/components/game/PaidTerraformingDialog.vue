<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { computed } from 'vue';
import PaidTerraformingController from '@/actions/App/Http/Controllers/PaidTerraformingController';
import NeutralInnovationBuildingController from '@/actions/App/Http/Controllers/NeutralInnovationBuildingController';
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
import { terrainNames } from '@/lib/gameDisplay';
import type { BoardHexState, GamePlayerBoardState, TerrainType } from '@/types';
import toolUrl from '../../../images/token_parts/cube.png';

const props = defineProps<{
    gameId: number;
    playerState: GamePlayerBoardState;
    targetHex: BoardHexState;
    homeland: TerrainType;
    hasSpadeInteraction: boolean;
    buildsNeutralBuilding?: boolean;
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const toolCostPerSpade = computed(() => Math.max(1, 3 - props.playerState.terraformingLevel));
const terrainCycle: TerrainType[] = ['desert', 'plains', 'swamp', 'lake', 'forest', 'mountain', 'wasteland'];
const requiredSpades = computed(() => {
    const sourceIndex = terrainCycle.indexOf(props.targetHex.terrain);
    const targetIndex = terrainCycle.indexOf(props.homeland);

    if (sourceIndex < 0 || targetIndex < 0) {
        return 0;
    }

    const clockwiseDistance = (targetIndex - sourceIndex + terrainCycle.length) % terrainCycle.length;
    const counterclockwiseDistance = (sourceIndex - targetIndex + terrainCycle.length) % terrainCycle.length;

    return Math.min(clockwiseDistance, counterclockwiseDistance);
});
const availableSpades = computed(() =>
    props.buildsNeutralBuilding ? 0 : Math.min(requiredSpades.value, props.playerState.unassignedSpades),
);
const purchasedSpades = computed(() => Math.max(0, requiredSpades.value - availableSpades.value));
const totalToolCost = computed(() => purchasedSpades.value * toolCostPerSpade.value);
const canAffordFullTransformation = computed(() => totalToolCost.value <= props.playerState.tools);
const canTransformAvailable = computed(
    () => props.hasSpadeInteraction && availableSpades.value > 0 && availableSpades.value < requiredSpades.value,
);

function startSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-bind="
                    buildsNeutralBuilding
                        ? NeutralInnovationBuildingController.form(gameId)
                        : PaidTerraformingController.form(gameId)
                "
                class="contents"
                reset-on-success
                #default="{ errors, processing }"
                @success="startSucceeded"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{ buildsNeutralBuilding ? 'Преобразовать и построить' : 'Преобразовать местность' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ terrainNames[targetHex.terrain] }} будет преобразована в {{ terrainNames[homeland] }}.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="flex items-center justify-between gap-4 rounded-lg border p-3 text-sm">
                        <span>Стоимость одной лопаты</span>
                        <span class="flex items-center gap-1.5 font-semibold">
                            {{ toolCostPerSpade }}
                            <img :src="toolUrl" alt="инструментов" class="size-5 object-contain" />
                        </span>
                    </div>

                    <input type="hidden" name="hex_id" :value="targetHex.id" />

                    <div class="grid grid-cols-3 gap-3 text-sm">
                        <div class="grid gap-1 rounded-lg border p-3">
                            <span class="text-muted-foreground">Необходимо лопат</span>
                            <span class="text-lg font-semibold">{{ requiredSpades }}</span>
                        </div>
                        <div class="grid gap-1 rounded-lg border p-3">
                            <span class="text-muted-foreground">Уже доступно</span>
                            <span class="text-lg font-semibold">{{ availableSpades }}</span>
                        </div>
                        <div class="grid gap-1 rounded-lg border p-3">
                            <span class="text-muted-foreground">Будет списано</span>
                            <span class="flex items-center gap-1.5 text-lg font-semibold">
                                {{ totalToolCost }}
                                <img :src="toolUrl" alt="инструментов" class="size-5 object-contain" />
                            </span>
                        </div>
                    </div>
                    <p v-if="purchasedSpades > 0" class="text-sm text-muted-foreground">
                        Недостающие лопаты: {{ purchasedSpades }} × {{ toolCostPerSpade }} инструмента.
                    </p>
                    <p v-else-if="!buildsNeutralBuilding" class="text-sm text-muted-foreground">
                        Инструменты не потребуются — используются ранее полученные лопаты.
                    </p>
                    <p v-if="!canAffordFullTransformation" class="text-sm text-destructive">
                        Не хватает инструментов: доступно {{ playerState.tools }}.
                    </p>
                    <InputError :message="errors.hex_id ?? errors.game" />
                </div>

                <DialogFooter class="grid grid-cols-1 sm:grid-cols-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button
                        v-if="canTransformAvailable"
                        type="submit"
                        name="use_available"
                        value="1"
                        variant="outline"
                        class="whitespace-normal"
                        :disabled="processing"
                    >
                        Преобразовать доступные
                    </Button>
                    <Button
                        type="submit"
                        name="use_available"
                        value="0"
                        :class="canTransformAvailable ? 'whitespace-normal sm:col-span-2' : 'whitespace-normal'"
                        :disabled="processing || !canAffordFullTransformation"
                    >
                        {{
                            processing
                                ? 'Подтверждение…'
                                : buildsNeutralBuilding
                                  ? 'Преобразовать и построить'
                                  : 'Преобразовать полностью'
                        }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
