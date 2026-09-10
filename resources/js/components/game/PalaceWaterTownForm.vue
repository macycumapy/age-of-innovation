<script setup lang="ts">
import { ref, watch } from 'vue';
import PalaceWaterTownController from '@/actions/App/Http/Controllers/PalaceWaterTownController';
import Form from '@/components/game/GameActionForm.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    gameId: number;
    waterHexIds: string[];
}>();

const selectedWaterHexId = ref<string | null>(null);

watch(
    () => props.waterHexIds,
    (waterHexIds) => {
        selectedWaterHexId.value = waterHexIds[0] ?? null;
    },
    { immediate: true },
);
</script>

<template>
    <Form
        v-bind="PalaceWaterTownController.form(gameId)"
        class="flex shrink-0 items-center gap-2"
        #default="{ processing }"
    >
        <input type="hidden" name="water_hex_id" :value="selectedWaterHexId ?? ''" />
        <select
            v-if="waterHexIds.length > 1"
            v-model="selectedWaterHexId"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            aria-label="Водная клетка для города"
            :disabled="processing"
        >
            <option v-for="waterHexId in waterHexIds" :key="waterHexId" :value="waterHexId">
                Водная клетка {{ waterHexId }}
            </option>
        </select>
        <Button type="submit" name="accept" value="0" variant="outline" :disabled="processing"> Отказаться </Button>
        <Button type="submit" name="accept" value="1" :disabled="processing || selectedWaterHexId === null">
            Основать город
        </Button>
    </Form>
</template>
