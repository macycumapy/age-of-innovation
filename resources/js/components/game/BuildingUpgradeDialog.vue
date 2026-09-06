<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { ArrowRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BuildingUpgradeController from '@/actions/App/Http/Controllers/BuildingUpgradeController';
import AnnexPlacementController from '@/actions/App/Http/Controllers/AnnexPlacementController';
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
import InputError from '@/components/InputError.vue';
import type { BuildingType, BuildingUpgradeOption, PlayerColor } from '@/types';
import toolUrl from '../../../images/token_parts/cube.png';
import coinUrl from '../../../images/token_parts/gold_medallion.png';
import annexUrl from '../../../images/buildings/white/annex.png';

const props = defineProps<{
    gameId: number;
    hexId: string | null;
    options: BuildingUpgradeOption[];
    playerColor: PlayerColor | null;
    canPlaceAnnex: boolean;
}>();

const isOpen = defineModel<boolean>('open', { default: false });
const selectedAction = ref<'upgrade' | 'annex'>('upgrade');
const selectedTarget = ref<BuildingType | null>(null);
const buildingImages = import.meta.glob<string>(
    '../../../images/buildings/*/{workshop,guild,school,university,palace}.png',
    { eager: true, import: 'default', query: '?url' },
);
const buildingNames: Record<BuildingType, string> = {
    workshop: 'Дом',
    guild: 'Рынок',
    school: 'Школа',
    university: 'Университет',
    palace: 'Дворец',
    tower: 'Башня',
    monument: 'Монумент',
};
const selectedOption = computed(() => props.options.find((option) => option.target === selectedTarget.value));

watch(
    () => [props.hexId, props.options] as const,
    () => {
        selectedTarget.value = props.options[0]?.target ?? null;
        selectedAction.value = props.options.length > 0 ? 'upgrade' : 'annex';
    },
    { immediate: true },
);

function buildingImage(type: BuildingType): string {
    const color = props.playerColor ?? 'white';

    return buildingImages[`../../../images/buildings/${color}/${type}.png`] ?? '';
}

function selectUpgrade(target: BuildingType): void {
    selectedAction.value = 'upgrade';
    selectedTarget.value = target;
}

function actionSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Действие со зданием</DialogTitle>
                <DialogDescription>Улучшите здание либо установите к нему доступную пристройку.</DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        v-for="option in options"
                        :key="option.target"
                        type="button"
                        class="grid gap-3 rounded-lg border p-4 text-left transition-colors hover:bg-muted/50"
                        :class="
                            selectedAction === 'upgrade' && selectedTarget === option.target
                                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                : ''
                        "
                        @click="selectUpgrade(option.target)"
                    >
                        <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                            <div class="grid justify-items-center gap-2">
                                <img
                                    :src="buildingImage(option.source)"
                                    class="h-20 w-24 object-contain"
                                    :alt="buildingNames[option.source]"
                                />
                                <span class="text-sm font-medium">{{ buildingNames[option.source] }}</span>
                            </div>
                            <ArrowRight class="size-5 text-muted-foreground" />
                            <div class="grid justify-items-center gap-2">
                                <img
                                    :src="buildingImage(option.target)"
                                    class="h-20 w-24 object-contain"
                                    :alt="buildingNames[option.target]"
                                />
                                <span class="text-sm font-medium">{{ buildingNames[option.target] }}</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-center gap-4" aria-label="Стоимость улучшения">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border bg-background px-2.5 py-1 text-sm font-semibold"
                                :aria-label="`Инструменты: ${option.tools}`"
                            >
                                <img :src="toolUrl" alt="" class="size-6 object-contain" />
                                {{ option.tools }}
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border bg-background px-2.5 py-1 text-sm font-semibold"
                                :aria-label="`Золото: ${option.coins}`"
                            >
                                <img :src="coinUrl" alt="" class="size-6 object-contain" />
                                {{ option.coins }}
                            </span>
                        </div>
                    </button>
                    <button
                        v-if="canPlaceAnnex"
                        type="button"
                        class="grid gap-3 rounded-lg border p-4 text-left transition-colors hover:bg-muted/50"
                        :class="selectedAction === 'annex' ? 'border-primary bg-primary/5 ring-1 ring-primary' : ''"
                        @click="selectedAction = 'annex'"
                    >
                        <div class="grid justify-items-center gap-2">
                            <img :src="annexUrl" class="h-24 w-28 object-contain" alt="Пристройка" />
                            <span class="font-medium">Поставить пристройку</span>
                        </div>
                        <p class="text-center text-sm text-muted-foreground">
                            +1 к силе здания и −1 к числу клеток для образования города.
                        </p>
                    </button>
                </div>

                <Form
                    v-if="selectedAction === 'upgrade'"
                    v-bind="BuildingUpgradeController.form(gameId)"
                    #default="{ errors, processing }"
                    class="grid gap-4"
                    @success="actionSucceeded"
                >
                    <input type="hidden" name="hex_id" :value="hexId ?? ''" />
                    <input type="hidden" name="target" :value="selectedTarget ?? ''" />
                    <InputError :message="errors.building ?? errors.target ?? errors.hex_id" />
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="outline">Отмена</Button>
                        </DialogClose>
                        <Button type="submit" :disabled="processing || selectedOption === undefined">
                            {{ processing ? 'Улучшение…' : 'Подтвердить улучшение' }}
                        </Button>
                    </DialogFooter>
                </Form>

                <Form
                    v-else
                    v-bind="AnnexPlacementController.create.form(gameId)"
                    #default="{ errors, processing }"
                    class="grid gap-4"
                    @success="actionSucceeded"
                >
                    <input type="hidden" name="hex_id" :value="hexId ?? ''" />
                    <InputError :message="errors.annex ?? errors.hex_id" />
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="outline">Отмена</Button>
                        </DialogClose>
                        <Button type="submit" :disabled="processing || !canPlaceAnnex">
                            {{ processing ? 'Размещение…' : 'Поставить пристройку' }}
                        </Button>
                    </DialogFooter>
                </Form>
            </div>
        </DialogContent>
    </Dialog>
</template>
