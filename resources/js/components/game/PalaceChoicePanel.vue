<script setup lang="ts">
import { ref } from 'vue';
import PalaceChoiceController from '@/actions/App/Http/Controllers/PalaceChoiceController';
import Form from '@/components/game/GameActionForm.vue';
import PalaceSelector from '@/components/game/PalaceSelector.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { PalaceAbility } from '@/types';

defineProps<{
    gameId: number;
    palaces: PalaceAbility[];
    descriptions: Record<PalaceAbility, string>;
}>();

const selectedPalace = ref<PalaceAbility | null>(null);
</script>

<template>
    <Card class="mx-auto w-full max-w-5xl p-0 border-none">
        <CardContent>
            <Form v-bind="PalaceChoiceController.form(gameId)" class="grid gap-4" #default="{ errors, processing }">
                <input type="hidden" name="palace_id" :value="selectedPalace ?? ''" />
                <PalaceSelector
                    v-model="selectedPalace"
                    :palaces="palaces"
                    :descriptions="descriptions"
                    :disabled="processing"
                />
                <InputError :message="errors.palace_id" />
                <Button
                    type="submit"
                    class="justify-self-end"
                    :disabled="selectedPalace === null || processing"
                >
                    {{ processing ? 'Подтверждение…' : 'Подтвердить выбор' }}
                </Button>
            </Form>
        </CardContent>
    </Card>
</template>
