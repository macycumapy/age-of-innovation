<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import TerraformWorkshopController from '@/actions/App/Http/Controllers/TerraformWorkshopController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BoardHexState } from '@/types';

const props = defineProps<{
    gameId: number;
    hexIds: string[];
    hexes: BoardHexState[];
}>();

const selectedHexId = ref(props.hexIds[0] ?? '');
const selectedHexes = computed(() => props.hexes.filter((hex) => props.hexIds.includes(hex.id)));

watch(() => props.hexIds, (hexIds) => {
    selectedHexId.value = hexIds[0] ?? '';
});
</script>

<template>
    <Dialog :open="true">
        <DialogContent :show-close-button="false">
            <DialogHeader>
                <DialogTitle>Построить дом?</DialogTitle>
                <DialogDescription>
                    Преобразованная земля стала родной. Можно построить мастерскую за 1 инструмент и 2 золота.
                </DialogDescription>
            </DialogHeader>

            <div v-if="selectedHexes.length > 1" class="grid grid-cols-2 gap-2">
                <button
                    v-for="hex in selectedHexes"
                    :key="hex.id"
                    type="button"
                    class="rounded-md border px-3 py-2 text-sm transition-colors"
                    :class="selectedHexId === hex.id ? 'border-primary bg-primary/10' : 'hover:bg-muted'"
                    @click="selectedHexId = hex.id"
                >
                    Ячейка {{ hex.q }}:{{ hex.r }}
                </button>
            </div>

            <DialogFooter class="gap-2 sm:gap-0">
                <Form
                    v-bind="TerraformWorkshopController.form(gameId)"
                    #default="{ processing }"
                >
                    <input type="hidden" name="build" value="0" />
                    <Button type="submit" variant="outline" :disabled="processing">
                        Не строить
                    </Button>
                </Form>
                <Form
                    v-bind="TerraformWorkshopController.form(gameId)"
                    #default="{ processing }"
                >
                    <input type="hidden" name="build" value="1" />
                    <input type="hidden" name="hex_id" :value="selectedHexId" />
                    <Button type="submit" :disabled="processing || selectedHexId === ''">
                        Построить дом
                    </Button>
                </Form>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
