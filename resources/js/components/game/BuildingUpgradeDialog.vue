<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BuildingUpgradeController from '@/actions/App/Http/Controllers/BuildingUpgradeController';
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

const props = defineProps<{
    gameId: number;
    hexId: string | null;
    options: BuildingUpgradeOption[];
    playerColor: PlayerColor | null;
}>();

const isOpen = defineModel<boolean>('open', { default: false });
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
    },
    { immediate: true },
);

function buildingImage(type: BuildingType): string {
    const color = props.playerColor ?? 'white';

    return buildingImages[`../../../images/buildings/${color}/${type}.png`] ?? '';
}

function upgradeSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Улучшить здание</DialogTitle>
                <DialogDescription>Выберите одно доступное улучшение и подтвердите оплату.</DialogDescription>
            </DialogHeader>

            <Form
                v-bind="BuildingUpgradeController.form(gameId)"
                #default="{ errors, processing }"
                class="grid gap-4"
                @success="upgradeSucceeded"
            >
                <input type="hidden" name="hex_id" :value="hexId ?? ''" />
                <input type="hidden" name="target" :value="selectedTarget ?? ''" />

                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        v-for="option in options"
                        :key="option.target"
                        type="button"
                        class="grid gap-3 rounded-lg border p-4 text-left transition-colors hover:bg-muted/50"
                        :class="selectedTarget === option.target ? 'border-primary bg-primary/5 ring-1 ring-primary' : ''"
                        @click="selectedTarget = option.target"
                    >
                        <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                            <div class="grid justify-items-center gap-2">
                                <img :src="buildingImage(option.source)" class="h-20 w-24 object-contain" :alt="buildingNames[option.source]" />
                                <span class="text-sm font-medium">{{ buildingNames[option.source] }}</span>
                            </div>
                            <ArrowRight class="size-5 text-muted-foreground" />
                            <div class="grid justify-items-center gap-2">
                                <img :src="buildingImage(option.target)" class="h-20 w-24 object-contain" :alt="buildingNames[option.target]" />
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
                </div>

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
        </DialogContent>
    </Dialog>
</template>
