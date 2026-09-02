<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import PalaceWaterTownController from '@/actions/App/Http/Controllers/PalaceWaterTownController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const props = defineProps<{ gameId: number; waterHexIds: string[] }>();
const selectedWaterHexId = ref(props.waterHexIds[0] ?? null);
</script>

<template>
    <Dialog :open="true">
        <DialogContent :show-close-button="false" class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Можно основать город через воду</DialogTitle>
                <DialogDescription>
                    Жетон Дворца позволяет включить в город одну водную клетку. Это необязательная возможность.
                </DialogDescription>
            </DialogHeader>

            <Form v-bind="PalaceWaterTownController.form(gameId)" #default="{ errors, processing }" class="grid gap-4">
                <input type="hidden" name="water_hex_id" :value="selectedWaterHexId ?? ''" />

                <div v-if="waterHexIds.length > 1" class="grid grid-cols-2 gap-2">
                    <button
                        v-for="waterHexId in waterHexIds"
                        :key="waterHexId"
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm"
                        :class="selectedWaterHexId === waterHexId ? 'border-primary ring-1 ring-primary' : ''"
                        @click="selectedWaterHexId = waterHexId"
                    >
                        Водная клетка {{ waterHexId }}
                    </button>
                </div>

                <InputError :message="errors.town ?? errors.water_hex_id" />

                <DialogFooter class="gap-2 sm:justify-between">
                    <Button type="submit" name="accept" value="0" variant="outline" :disabled="processing">
                        Отказаться
                    </Button>
                    <Button type="submit" name="accept" value="1" :disabled="processing || selectedWaterHexId === null">
                        Основать город
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
