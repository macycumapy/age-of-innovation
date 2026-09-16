<script setup lang="ts">
import { RotateCcw } from '@lucide/vue';
import PalaceWaterTownController from '@/actions/App/Http/Controllers/PalaceWaterTownController';
import Form from '@/components/game/GameActionForm.vue';
import { Button } from '@/components/ui/button';

defineProps<{
    gameId: number;
    selectedWaterHexId: string | null;
}>();

const emit = defineEmits<{
    resetSelection: [];
}>();
</script>

<template>
    <Form
        v-bind="PalaceWaterTownController.form(gameId)"
        class="flex shrink-0 items-center gap-2"
        #default="{ processing }"
    >
        <input type="hidden" name="water_hex_id" :value="selectedWaterHexId ?? ''" />
        <span class="text-sm font-medium">
            {{ selectedWaterHexId === null ? 'Выберите водную клетку на карте.' : 'Водная клетка выбрана.' }}
        </span>
        <Button
            v-if="selectedWaterHexId !== null"
            type="button"
            variant="outline"
            :disabled="processing"
            @click="emit('resetSelection')"
        >
            <RotateCcw class="size-4" />
            Сбросить выбор
        </Button>
        <Button type="submit" name="accept" value="0" variant="outline" :disabled="processing"> Отказаться </Button>
        <Button type="submit" name="accept" value="1" :disabled="processing || selectedWaterHexId === null">
            Основать город
        </Button>
    </Form>
</template>
