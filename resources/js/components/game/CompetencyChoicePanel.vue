<script setup lang="ts">
import { ref } from 'vue';
import StartingCompetencyController from '@/actions/App/Http/Controllers/StartingCompetencyController';
import CompetencySelector from '@/components/game/CompetencySelector.vue';
import Form from '@/components/game/GameActionForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { Competency } from '@/types';

const props = defineProps<{
    gameId: number;
    competencies: Competency[];
    descriptions: Record<Competency, string>;
    isBuildingChoice: boolean;
}>();

const selectedCompetency = ref<Competency | null>(null);
</script>

<template>
    <Card class="mx-auto w-full max-w-3xl border-none">
        <CardHeader>
            <CardTitle>
                {{ isBuildingChoice ? 'Компетенция нового здания' : 'Стартовая компетенция' }}
            </CardTitle>
            <CardDescription>
                {{
                    isBuildingChoice
                        ? 'Выберите компетенцию для построенной школы или университета.'
                        : 'Выберите компетенцию. Вы сразу получите её книги, продвижение по дисциплине и ресурсы.'
                }}
            </CardDescription>
        </CardHeader>
        <CardContent>
            <Form
                v-bind="StartingCompetencyController.store.form(props.gameId)"
                #default="{ errors, processing }"
                class="grid gap-4"
            >
                <input type="hidden" name="competency_id" :value="selectedCompetency ?? ''" />
                <CompetencySelector
                    v-model="selectedCompetency"
                    :competencies="props.competencies"
                    :descriptions="props.descriptions"
                    :disabled="processing"
                />
                <InputError :message="errors.competency_id" />
                <Button type="submit" class="justify-self-end" :disabled="selectedCompetency === null || processing">
                    {{ processing ? 'Подтверждение…' : 'Подтвердить выбор' }}
                </Button>
            </Form>
        </CardContent>
    </Card>
</template>
