<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import GameBotController from '@/actions/App/Http/Controllers/GameBotController';
import Form from '@/components/game/GameActionForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { SegmentedRadio } from '@/components/ui/segmented-radio';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { GameBotDifficulty } from '@/types';

const props = defineProps<{ gameId: number; difficulties: GameBotDifficulty[] }>();
const isOpen = ref(false);
const difficulty = ref<GameBotDifficulty>(
    props.difficulties.includes('balanced') ? 'balanced' : (props.difficulties[0] ?? 'fast'),
);
const difficultyOptions = computed(() =>
    props.difficulties.map((value) => ({
        value,
        label: { fast: 'Слабый', balanced: 'Обычный', strong: 'Сильный' }[value],
    })),
);

watch(
    () => props.difficulties,
    (difficulties) => {
        if (!difficulties.includes(difficulty.value)) {
            difficulty.value = difficulties[0] ?? 'fast';
        }
    },
);

function handleSuccess(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogTrigger as-child>
            <Button type="button" variant="outline">Добавить бота</Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Добавить бота</DialogTitle>
                <DialogDescription>Выберите сложность бота для свободного места в партии.</DialogDescription>
            </DialogHeader>
            <Form
                v-bind="GameBotController.form(gameId)"
                #default="{ errors, processing }"
                class="grid gap-4"
                @success="handleSuccess"
            >
                <fieldset class="grid gap-2" :disabled="processing">
                    <legend class="mb-2 text-sm font-medium">Сложность бота</legend>
                    <SegmentedRadio
                        v-model="difficulty"
                        name="difficulty"
                        :options="difficultyOptions"
                        :disabled="processing"
                    />
                    <InputError :message="errors.difficulty ?? errors.game" />
                </fieldset>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline" :disabled="processing">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Добавление…' : 'Добавить бота' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
